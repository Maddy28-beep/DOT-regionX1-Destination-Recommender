<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\EstablishmentAccount;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\ListingPhoto;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\Review;
use App\Models\TouristPreference;
use App\Models\TouristVisit;
use App\Services\Establishment\ListingInsights;
use App\Services\Establishment\ListingReadiness;
use App\Support\OpeningHours;
use App\Support\OpeningHoursText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The partner portal in the DOT Admin's look: every form a modal that is also a page, a completeness checklist with
 * the form that closes each gap, opening hours as seven rows, photo credits, and insights from check-ins and trip plans.
 */
class PartnerPortalRedesignTest extends TestCase
{
    use RefreshDatabase;

    private function region(): Region
    {
        return Region::firstOrCreate(['name' => 'Davao City']);
    }

    private function stay(array $over = []): Accommodation
    {
        return Accommodation::create($over + [
            'slug' => 'my-resort', 'name' => 'My Resort', 'location' => 'Davao City', 'region_id' => $this->region()->id,
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 4.0, 'review_count' => 1, 'price_tier' => 'Mid-range', 'price_per_night' => 3000,
        ]);
    }

    private function cafe(array $over = []): Restaurant
    {
        return Restaurant::create($over + [
            'slug' => 'my-cafe', 'name' => 'My Cafe', 'location' => 'Davao City', 'region_id' => $this->region()->id,
            'cuisine_type' => 'Cafe', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Budget-Friendly',
        ]);
    }

    private function partnerFor($listing, string $kind, string $email = 'owner@test.example.com'): EstablishmentAccount
    {
        return EstablishmentAccount::create([
            'business_name' => $listing->name, 'listing_kind' => $kind, 'matched_listing_id' => $listing->id,
            'portal_key' => (string) Str::uuid(), 'email' => $email, 'password_hash' => Hash::make('x'),
            'contact_person' => 'Owner', 'contact_number' => '09170000000', 'status' => 'approved', 'submitted_at' => now(),
        ]);
    }

    // ---------- opening hours as text ----------

    public function test_rows_become_text_the_site_can_read_and_back(): void
    {
        $open = fn ($from, $to) => ['on' => true, 'from' => $from, 'to' => $to];
        $shut = ['on' => false, 'from' => '08:00', 'to' => '17:00'];

        $daily = OpeningHoursText::compose(array_fill(0, 7, $open('08:00', '17:00')));
        $this->assertSame('8:00 AM–5:00 PM', $daily);
        $this->assertNotNull(OpeningHours::windows($daily), 'the same hours every day are the plain form');

        $mixed = OpeningHoursText::compose([$open('08:00', '17:00'), $open('08:00', '17:00'), $open('08:00', '17:00'), $open('08:00', '17:00'), $open('08:00', '17:00'), $open('09:00', '12:00'), $shut]);
        $this->assertSame('Mon–Fri 8:00 AM–5:00 PM; Sat 9:00 AM–12:00 PM', $mixed);

        // read back for every weekday: Monday is open 8-5, Saturday 9-12, Sunday closed
        $week = OpeningHoursText::week($mixed);
        $this->assertTrue($week[0]['on']);
        $this->assertSame('08:00', $week[0]['from']);
        $this->assertSame('09:00', $week[5]['from']);
        $this->assertSame('12:00', $week[5]['to']);
        $this->assertFalse($week[6]['on']);

        $this->assertNull(OpeningHoursText::week('By arrangement'), 'text it cannot read is not guessed at');
    }

    public function test_the_hours_form_saves_to_the_listings_hours_column(): void
    {
        $cafe = $this->cafe();
        $partner = $this->partnerFor($cafe, 'restaurant');
        $hours = [];
        foreach (range(0, 6) as $i) {
            $hours[$i] = $i === 6 ? ['from' => '08:00', 'to' => '17:00'] : ['on' => 1, 'from' => '08:00', 'to' => '17:00'];
        }

        $this->actingAs($partner, 'establishment')
            ->putJson('/portal/establishment/listing', ['section' => 'hours', 'hours' => $hours])
            ->assertOk()->assertJsonStructure(['redirect']);

        $this->assertSame('Mon–Sat 8:00 AM–5:00 PM', $cafe->fresh()->opening_hours);
    }

    public function test_hours_that_make_no_sense_are_refused_with_a_reason(): void
    {
        $partner = $this->partnerFor($this->cafe(), 'restaurant');
        $closesEarly = array_fill(0, 7, ['on' => 1, 'from' => '17:00', 'to' => '08:00']);
        $allShut = array_fill(0, 7, ['from' => '08:00', 'to' => '17:00']);

        $this->actingAs($partner, 'establishment')->putJson('/portal/establishment/listing', ['section' => 'hours', 'hours' => $closesEarly])
            ->assertStatus(422)->assertJsonValidationErrors(['hours.0.to']);

        $this->actingAs($partner, 'establishment')->putJson('/portal/establishment/listing', ['section' => 'hours', 'hours' => $allShut])
            ->assertStatus(422)->assertJsonValidationErrors(['hours.0.on']);
    }

    public function test_a_stay_has_no_hours_form(): void
    {
        $partner = $this->partnerFor($this->stay(), 'accommodation');

        $this->actingAs($partner, 'establishment')->get('/portal/establishment/listing/hours')->assertNotFound();
        $this->actingAs($partner, 'establishment')->get('/portal/establishment/listing/nonsense')->assertNotFound();
    }

    // ---------- forms: a modal and a page ----------

    public function test_every_form_is_a_fragment_for_the_modal_and_a_full_page_otherwise(): void
    {
        $stay = $this->stay();
        $partner = $this->partnerFor($stay, 'accommodation');
        $review = Review::create(['listing_kind' => 'accommodation', 'listing_id' => $stay->id, 'author_name' => 'Ana', 'rating' => 5, 'comment' => 'Lovely']);

        $urls = [
            '/portal/establishment/listing/details', '/portal/establishment/listing/status', '/portal/establishment/listing/position',
            '/portal/establishment/photos/create', '/portal/establishment/photos/credit', '/portal/establishment/promotions/create',
            "/portal/establishment/reviews/{$review->id}/reply",
        ];

        foreach ($urls as $url) {
            $fragment = $this->actingAs($partner, 'establishment')->get($url.'?modal=1')->assertOk()->getContent();
            $this->assertStringContainsString('<form', $fragment, $url);
            $this->assertStringNotContainsString('<html', $fragment, "{$url} as a modal is just the form");

            $page = $this->actingAs($partner, 'establishment')->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('<html', $page, "{$url} still works as a page");
            $this->assertStringContainsString('<form', $page, $url);
        }
    }

    public function test_a_saved_modal_form_answers_with_where_to_go(): void
    {
        $stay = $this->stay();
        $partner = $this->partnerFor($stay, 'accommodation');

        $this->actingAs($partner, 'establishment')
            ->putJson('/portal/establishment/listing', ['section' => 'details', 'description' => 'A quiet place by the sea with a long, honest description of it.', 'price_tier' => 'Premium', 'price_amount' => 4500])
            ->assertOk()->assertJsonStructure(['redirect']);

        $stay->refresh();
        $this->assertSame('Premium', $stay->price_tier);
        $this->assertEquals(4500, $stay->price_per_night);
    }

    public function test_a_section_changes_only_its_own_fields(): void
    {
        $stay = $this->stay(['latitude' => 7.0731, 'longitude' => 125.6128, 'description' => 'Keep me', 'operating_status' => 'open']);
        $partner = $this->partnerFor($stay, 'accommodation');

        $this->actingAs($partner, 'establishment')
            ->putJson('/portal/establishment/listing', ['section' => 'status', 'operating_status' => 'temporarily_closed', 'reopens_on' => '2026-12-01', 'closure_reason' => 'Repairs'])
            ->assertOk();

        $stay->refresh();
        $this->assertSame('temporarily_closed', $stay->operating_status);
        $this->assertSame('Keep me', $stay->description, 'the status form must not blank the description');
        $this->assertEqualsWithDelta(7.0731, (float) $stay->latitude, 0.0001, 'nor the map position');

        $this->actingAs($partner, 'establishment')
            ->putJson('/portal/establishment/listing', ['section' => 'position', 'latitude' => 7.2, 'longitude' => 125.7])
            ->assertOk();

        $stay->refresh();
        $this->assertEqualsWithDelta(7.2, (float) $stay->latitude, 0.0001);
        $this->assertSame('Keep me', $stay->description);
        $this->assertSame('temporarily_closed', $stay->operating_status);
    }

    // ---------- completeness ----------

    public function test_readiness_counts_what_is_filled_in_and_names_the_form_for_each_gap(): void
    {
        $stay = $this->stay();
        $readiness = app(ListingReadiness::class);

        $empty = $readiness->for('accommodation', $stay->load('photos'));
        $this->assertSame(0, collect($empty['checks'])->where('key', 'hours')->count(), 'a stay has no opening hours to ask for');
        $this->assertSame(7, $empty['total']);
        $this->assertSame(0, $empty['done']);

        $stay->update([
            'description' => str_repeat('A long and honest description. ', 3), 'latitude' => 7.07, 'longitude' => 125.6,
            'website_url' => 'https://example.com', 'facebook_url' => 'https://facebook.com/x',
        ]);
        foreach (['a', 'b', 'c'] as $n) {
            ListingPhoto::create(['listing_kind' => 'accommodation', 'listing_id' => $stay->id, 'path' => "listings/{$n}.jpg", 'category' => 'General', 'credit_text' => 'Rey', 'source_name' => 'Rey']);
        }

        app(\App\Services\Amenities\AmenityStore::class)->sync('accommodation', $stay, ['wifi', 'pool', 'ac', 'parking']);

        $full = $readiness->for('accommodation', $stay->fresh()->load('photos'));
        $this->assertSame(100, $full['percent']);
    }

    public function test_the_overview_shows_the_checklist_and_a_fix_link_that_opens_in_the_modal(): void
    {
        $partner = $this->partnerFor($this->cafe(), 'restaurant');

        $html = $this->actingAs($partner, 'establishment')->get('/portal/establishment')->assertOk()->getContent();

        $this->assertStringContainsString('Complete your listing', $html);
        $this->assertStringContainsString('Opening hours', $html);
        $this->assertStringContainsString('/portal/establishment/listing/hours', $html);
        $this->assertStringContainsString('data-modal-form', $html);
        $this->assertStringContainsString('How travellers see you', $html);
    }

    // ---------- photos ----------

    public function test_an_upload_needs_a_credit_or_a_tick_that_the_photos_are_the_partners_own(): void
    {
        Storage::fake('public');
        $stay = $this->stay();
        $partner = $this->partnerFor($stay, 'accommodation');

        $this->actingAs($partner, 'establishment')
            ->postJson('/portal/establishment/photos', ['photos' => [UploadedFile::fake()->image('a.jpg')], 'category' => 'General'])
            ->assertStatus(422)->assertJsonValidationErrors(['credit_text']);

        $this->actingAs($partner, 'establishment')
            ->postJson('/portal/establishment/photos', ['photos' => [UploadedFile::fake()->image('a.jpg')], 'category' => 'General', 'own' => 1])
            ->assertOk();

        $photo = ListingPhoto::where('listing_id', $stay->id)->firstOrFail();
        $this->assertSame('My Resort', $photo->credit_text);
        $this->assertSame('My Resort', $photo->source_name, 'DOT\'s completeness check reads the source');

        $this->actingAs($partner, 'establishment')
            ->postJson('/portal/establishment/photos', ['photos' => [UploadedFile::fake()->image('b.jpg')], 'category' => 'Views', 'credit_text' => 'Rey Santos'])
            ->assertOk();

        $this->assertSame('Rey Santos', ListingPhoto::where('listing_id', $stay->id)->where('category', 'Views')->first()->credit_text);
    }

    public function test_credits_are_added_to_the_photos_that_have_none_and_only_those(): void
    {
        $stay = $this->stay();
        $partner = $this->partnerFor($stay, 'accommodation');
        $credited = ListingPhoto::create(['listing_kind' => 'accommodation', 'listing_id' => $stay->id, 'path' => 'listings/a.jpg', 'category' => 'General', 'credit_text' => 'Rey', 'source_name' => 'Rey']);
        $bare = ListingPhoto::create(['listing_kind' => 'accommodation', 'listing_id' => $stay->id, 'path' => 'listings/b.jpg', 'category' => 'General']);

        $this->actingAs($partner, 'establishment')->putJson('/portal/establishment/photos/credit', ['credit_text' => 'Staff'])->assertOk();

        $this->assertSame('Staff', $bare->fresh()->credit_text);
        $this->assertSame('Rey', $credited->fresh()->credit_text);

        $this->actingAs($partner, 'establishment')->putJson("/portal/establishment/photos/{$credited->id}/credit", ['own' => 1])->assertOk();
        $this->assertSame('My Resort', $credited->fresh()->credit_text);
    }

    public function test_a_partner_cannot_credit_or_reorder_someone_elses_photos(): void
    {
        $mine = $this->stay();
        $theirs = $this->stay(['slug' => 'other', 'name' => 'Other Inn']);
        $partner = $this->partnerFor($mine, 'accommodation');
        $foreign = ListingPhoto::create(['listing_kind' => 'accommodation', 'listing_id' => $theirs->id, 'path' => 'listings/x.jpg', 'category' => 'General', 'sort_order' => 5]);

        $this->actingAs($partner, 'establishment')->putJson("/portal/establishment/photos/{$foreign->id}/credit", ['own' => 1])->assertForbidden();
        $this->actingAs($partner, 'establishment')->postJson('/portal/establishment/photos/reorder', ['order' => [$foreign->id]])->assertOk();

        $this->assertSame(5, (int) $foreign->fresh()->sort_order, 'ids that are not this listing\'s are ignored');
    }

    public function test_dragging_photos_saves_the_new_order(): void
    {
        $stay = $this->stay();
        $partner = $this->partnerFor($stay, 'accommodation');
        $ids = [];
        foreach ([0, 1, 2] as $n) {
            $ids[] = ListingPhoto::create(['listing_kind' => 'accommodation', 'listing_id' => $stay->id, 'path' => "listings/{$n}.jpg", 'category' => 'General', 'sort_order' => $n])->id;
        }

        $this->actingAs($partner, 'establishment')->postJson('/portal/establishment/photos/reorder', ['order' => [$ids[2], $ids[0], $ids[1]]])->assertOk();

        $this->assertSame([$ids[2], $ids[0], $ids[1]], ListingPhoto::where('listing_id', $stay->id)->orderBy('sort_order')->pluck('id')->all());
    }

    // ---------- reviews and promos ----------

    public function test_a_reply_posted_from_the_modal_is_saved(): void
    {
        $stay = $this->stay();
        $partner = $this->partnerFor($stay, 'accommodation');
        $review = Review::create(['listing_kind' => 'accommodation', 'listing_id' => $stay->id, 'author_name' => 'Ana Cruz', 'rating' => 4, 'comment' => 'Nice']);

        $form = $this->actingAs($partner, 'establishment')->get("/portal/establishment/reviews/{$review->id}/reply?modal=1")->getContent();
        $this->assertStringContainsString('Start from a template', $form);
        $this->assertStringContainsString('Thank you so much, Ana!', $form, 'templates use the traveller\'s first name');

        $this->actingAs($partner, 'establishment')->postJson("/portal/establishment/reviews/{$review->id}/reply", ['owner_reply' => 'Thank you, Ana.'])->assertOk()->assertJsonStructure(['redirect']);
        $this->assertSame('Thank you, Ana.', $review->fresh()->owner_reply);
    }

    public function test_the_promos_page_says_which_promos_are_showing_scheduled_or_ended(): void
    {
        $stay = $this->stay();
        $partner = $this->partnerFor($stay, 'accommodation');
        $today = Carbon::now('Asia/Manila');
        foreach ([
            ['Showing now', null, null],
            ['Starts soon', $today->copy()->addDays(10)->toDateString(), null],
            ['Long gone', $today->copy()->subDays(30)->toDateString(), $today->copy()->subDays(10)->toDateString()],
        ] as [$title, $from, $to]) {
            \App\Models\Promotion::create(['listing_kind' => 'accommodation', 'listing_id' => $stay->id, 'title' => $title, 'starts_at' => $from, 'ends_at' => $to]);
        }

        $html = $this->actingAs($partner, 'establishment')->get('/portal/establishment/promotions')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '>Showing<'));
        $this->assertSame(1, substr_count($html, '>Scheduled<'));
        $this->assertSame(1, substr_count($html, '>Ended<'));
    }

    // ---------- insights ----------

    private function tripWith(Destination|Restaurant|Accommodation $stop, int $day = 1, ?Carbon $when = null): Itinerary
    {
        $preference = TouristPreference::create([
            'travel_days' => 2, 'travel_type' => 'Solo', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
        ]);
        $itinerary = Itinerary::create(['preference_id' => $preference->id, 'total_days' => 2, 'generated_at' => $when ?? now()]);
        $column = match (true) {
            $stop instanceof Accommodation => 'accommodation_id',
            $stop instanceof Restaurant => 'restaurant_id',
            default => 'destination_id',
        };
        ItineraryItem::create(['itinerary_id' => $itinerary->id, 'day_number' => $day, 'sort_order' => 1, 'slot' => 'Morning', 'kind' => 'activity', 'title' => 'Stop', $column => $stop->id]);

        return $itinerary;
    }

    public function test_insights_count_check_ins_and_trip_plans_and_compare_with_the_period_before(): void
    {
        $cafe = $this->cafe();
        $today = Carbon::now('Asia/Manila');

        foreach (['a', 'b', 'c'] as $token) {
            TouristVisit::create(['visitor_token' => $token, 'listing_kind' => 'restaurant', 'listing_id' => $cafe->id, 'visit_date' => $today->toDateString(), 'source' => 'qr']);
        }
        TouristVisit::create(['visitor_token' => 'old', 'listing_kind' => 'restaurant', 'listing_id' => $cafe->id, 'visit_date' => $today->copy()->subDays(40)->toDateString(), 'source' => 'qr']);
        // someone else's check-in is not counted
        TouristVisit::create(['visitor_token' => 'z', 'listing_kind' => 'restaurant', 'listing_id' => $cafe->id + 99, 'visit_date' => $today->toDateString(), 'source' => 'qr']);

        $this->tripWith($cafe, 1);
        $this->tripWith($cafe, 2);
        $this->tripWith($cafe, 3, now()->subDays(40));

        $insights = app(ListingInsights::class);
        $this->assertSame(['total' => 3, 'before' => 1], $insights->checkIns('restaurant', $cafe->id, 30));
        $plans = $insights->tripPlans('restaurant', $cafe->id, 30);
        $this->assertSame(2, $plans['total']);
        $this->assertSame(1, $plans['before']);
        $this->assertSame([1 => 1, 2 => 1, 3 => 0], $plans['byDay']);
        $this->assertSame(200, ListingInsights::change(3, 1));
        $this->assertNull(ListingInsights::change(3, 0));
        $this->assertNull($insights->tripPlans('tour_operator', 1, 30), 'a kind that never appears in a plan has no trip-plan count');
    }

    public function test_the_chart_has_the_right_number_of_bars_for_each_range(): void
    {
        $insights = app(ListingInsights::class);

        $this->assertCount(7, $insights->series('restaurant', 1, 7));
        $this->assertCount(30, $insights->series('restaurant', 1, 30));
        $this->assertCount(13, $insights->series('restaurant', 1, 90), '90 days is shown as 13 weeks');
    }

    public function test_the_insights_page_renders_for_every_range_and_without_data(): void
    {
        $cafe = $this->cafe();
        $partner = $this->partnerFor($cafe, 'restaurant');

        foreach ([7, 30, 90] as $range) {
            $this->actingAs($partner, 'establishment')->get("/portal/establishment/insights?range={$range}")->assertOk()->assertSee('QR check-ins');
        }
        $this->actingAs($partner, 'establishment')->get('/portal/establishment/insights?range=999')->assertOk();
        $this->actingAs($partner, 'establishment')->get('/portal/establishment/insights')->assertOk()->assertSee('No check-ins yet');
    }

    public function test_a_partner_without_a_linked_listing_is_sent_back_from_listing_pages(): void
    {
        $partner = EstablishmentAccount::create([
            'business_name' => 'No Listing', 'listing_kind' => 'accommodation', 'portal_key' => (string) Str::uuid(), 'email' => 'none@test.example.com',
            'password_hash' => Hash::make('x'), 'contact_person' => 'Owner', 'contact_number' => '09170000000', 'status' => 'approved', 'submitted_at' => now(),
        ]);

        foreach (['/portal/establishment/insights', '/portal/establishment/listing', '/portal/establishment/listing/details', '/portal/establishment/photos/create', '/portal/establishment/promotions/create'] as $url) {
            $this->actingAs($partner, 'establishment')->get($url)->assertRedirect(route('establishment.overview'));
        }
        $this->actingAs($partner, 'establishment')->get('/portal/establishment')->assertOk();
    }
}
