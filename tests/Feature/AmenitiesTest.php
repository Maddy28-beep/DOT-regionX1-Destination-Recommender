<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\DestinationTag;
use App\Models\EstablishmentAccount;
use App\Models\ListingPhoto;
use App\Models\Package;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use App\Services\Amenities\AmenityStore;
use App\Services\Establishment\ListingReadiness;
use App\Support\Amenities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One shared list of amenities and accessibility features: ticked by every kind of listing (a package has who it suits
 * and what is on the trip instead), tied to photos by the uploader, shown on the public pages, checked by DOT.
 */
class AmenitiesTest extends TestCase
{
    use RefreshDatabase;

    private function region(): Region
    {
        return Region::firstOrCreate(['name' => 'Davao City']);
    }

    private function listing(string $kind, string $name = 'Sample'): object
    {
        $base = ['slug' => Str::slug($name).'-'.$kind, 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region()->id, 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range'];

        return match ($kind) {
            'destination' => Destination::create($base + ['type' => 'Nature']),
            'accommodation' => Accommodation::create($base + ['type' => 'Hotel']),
            'restaurant' => Restaurant::create($base + ['cuisine_type' => 'Cafe']),
            'souvenir_center' => SouvenirCenter::create($base),
            'tour_operator' => TourOperator::create($base),
            'package' => Package::create($base + ['status' => 'live']),
        };
    }

    private function account(string $kind, object $listing, string $email = 'owner@test.example.com'): EstablishmentAccount
    {
        return EstablishmentAccount::create([
            'business_name' => $listing->name, 'listing_kind' => $kind, 'matched_listing_id' => $listing->id, 'portal_key' => (string) Str::uuid(),
            'email' => $email, 'password_hash' => Hash::make('x'), 'contact_person' => 'Owner', 'contact_number' => '09170000000', 'status' => 'approved', 'submitted_at' => now(),
        ]);
    }

    // ---------- the list ----------

    public function test_every_choice_a_kind_is_offered_exists_in_the_catalog_and_the_planner_keeps_its_old_labels(): void
    {
        foreach (Amenities::FOR_KIND as $kind => $keys) {
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, Amenities::CATALOG, "{$kind} offers {$key}");
            }
        }

        // the strings saved on past trip plans and on destination tags still match
        $this->assertSame(['Parking Area', 'Restaurant', 'Swimming Pool', 'Wi-Fi', 'Restroom', 'Accessibility Ramp', 'Air Conditioning'], Amenities::plannerLabels());
        $this->assertSame('ramp', Amenities::keysByLabel()['Accessibility Ramp']);

        $this->assertSame([], Amenities::keysFor('tour_operator'), 'a tour operator has no amenities');
        $this->assertNotContains('elevator', Amenities::keysFor('restaurant'), 'each kind is only offered what makes sense for it');
        $this->assertContains('accroom', Amenities::keysFor('accommodation'));
    }

    public function test_the_planner_page_offers_the_shared_list(): void
    {
        $html = $this->get(route('plan.edit'))->getContent();

        foreach (Amenities::plannerLabels() as $label) {
            $this->assertStringContainsString('value="'.e($label).'"', $html);
        }
    }

    public function test_amenities_are_saved_by_key_for_every_kind_and_only_offered_choices_stick(): void
    {
        $store = app(AmenityStore::class);

        $stay = $this->listing('accommodation');
        $this->assertSame(['wifi', 'pool'], $store->sync('accommodation', $stay, ['pool', 'wifi', 'stepfree', 'nonsense']), 'a stay is not offered a step-free entrance');
        $this->assertSame(['wifi', 'pool'], $store->keys('accommodation', $stay));

        $store->sync('accommodation', $stay, ['ac']);
        $this->assertSame(['ac'], $store->keys('accommodation', $stay), 'syncing replaces');

        $this->assertSame([], $store->sync('tour_operator', $this->listing('tour_operator'), ['parking']));
    }

    public function test_destinations_keep_their_amenities_as_the_tags_the_recommender_reads(): void
    {
        $place = $this->listing('destination');
        DestinationTag::create(['destination_id' => $place->id, 'kind' => 'amenity', 'value' => 'Parking Area']);
        DestinationTag::create(['destination_id' => $place->id, 'kind' => 'amenity', 'value' => 'Some Old Value']);
        DestinationTag::create(['destination_id' => $place->id, 'kind' => 'category', 'value' => 'Wildlife']);

        $store = app(AmenityStore::class);
        $this->assertSame(['parking'], $store->keys('destination', $place));

        $store->sync('destination', $place, ['ramp', 'restroom']);

        $values = fn (string $kind) => DestinationTag::where('destination_id', $place->id)->where('kind', $kind)->pluck('value')->sort()->values()->all();
        $this->assertSame(['Accessibility Ramp', 'Restroom', 'Some Old Value'], $values('amenity'), 'parking is gone, a value the list does not know is left alone');
        $this->assertSame(['Wildlife'], $values('category'));
    }

    // ---------- the partner portal ----------

    public function test_every_kind_with_amenities_gets_the_form_and_a_tour_operator_or_package_does_not(): void
    {
        foreach (['destination', 'accommodation', 'restaurant', 'souvenir_center'] as $kind) {
            $account = $this->account($kind, $this->listing($kind, 'Place '.$kind), $kind.'@test.example.com');
            $fragment = $this->actingAs($account, 'establishment')->get('/portal/establishment/listing/amenities?modal=1')->assertOk()->getContent();

            $this->assertStringContainsString('name="amenities[]"', $fragment, $kind);
            $this->assertStringContainsString('Accessibility', $fragment, $kind);
            $this->assertStringNotContainsString('<html', $fragment);
        }

        foreach (['tour_operator', 'package'] as $kind) {
            $account = $this->account($kind, $this->listing($kind, 'Biz '.$kind), $kind.'@test.example.com');
            $this->actingAs($account, 'establishment')->get('/portal/establishment/listing/amenities')->assertNotFound();
        }
    }

    public function test_a_partner_ticks_amenities_and_the_page_and_checklist_follow(): void
    {
        $stay = $this->listing('accommodation');
        $account = $this->account('accommodation', $stay);

        $this->actingAs($account, 'establishment')->putJson('/portal/establishment/listing', ['section' => 'amenities', 'amenities' => ['wifi', 'pool', 'bogus']])
            ->assertOk()->assertJsonStructure(['redirect']);

        $this->assertSame(['wifi', 'pool'], app(AmenityStore::class)->keys('accommodation', $stay));

        $this->actingAs($account, 'establishment')->get('/portal/establishment/listing')->assertOk()->assertSee('Wi-Fi')->assertSee('Swimming Pool')->assertSee('2 ticked');

        $check = collect(app(ListingReadiness::class)->for('accommodation', $stay)['checks'])->firstWhere('key', 'amenities');
        $this->assertFalse($check['ok'], 'four or more count as done');

        app(AmenityStore::class)->sync('accommodation', $stay, ['wifi', 'pool', 'ac', 'parking']);
        $this->assertTrue(collect(app(ListingReadiness::class)->for('accommodation', $stay)['checks'])->firstWhere('key', 'amenities')['ok']);

        // a form that ticks nothing clears them
        $this->actingAs($account, 'establishment')->putJson('/portal/establishment/listing', ['section' => 'amenities'])->assertOk();
        $this->assertSame([], app(AmenityStore::class)->keys('accommodation', $stay));
    }

    // ---------- photos tied to an amenity ----------

    public function test_an_amenity_photo_says_which_amenity_it_shows_and_ticks_it(): void
    {
        Storage::fake('public');
        $place = $this->listing('destination');
        $account = $this->account('destination', $place);
        $client = $this->actingAs($account, 'establishment');
        $photo = fn (array $extra) => $client->postJson('/portal/establishment/photos', $extra + ['photos' => [UploadedFile::fake()->image('a.jpg')], 'own' => 1]);

        $photo(['category' => 'Amenities'])->assertStatus(422)->assertJsonValidationErrors(['amenity']);
        $photo(['category' => 'Amenities', 'amenity' => 'ramp'])->assertStatus(422)->assertJsonValidationErrors(['amenity']);       // a ramp is Accessibility, not Amenities
        $photo(['category' => 'Accessibility', 'amenity' => 'parking'])->assertStatus(422)->assertJsonValidationErrors(['amenity']); // and parking is not accessibility
        $this->assertSame(0, ListingPhoto::count());

        $photo(['category' => 'Amenities', 'amenity' => 'lockers'])->assertOk();
        $photo(['category' => 'Accessibility', 'amenity' => 'ramp'])->assertOk();
        $photo(['category' => 'Exterior'])->assertOk();

        $this->assertSame('lockers', ListingPhoto::where('category', 'Amenities')->first()->amenity);
        $this->assertNull(ListingPhoto::where('category', 'Exterior')->first()->amenity);
        $this->assertEqualsCanonicalizing(['lockers', 'ramp'], app(AmenityStore::class)->keys('destination', $place), 'a photo of it means it is there');

        $this->actingAs($account, 'establishment')->get('/portal/establishment/photos')->assertOk()->assertSee('Shows: Lockers');
        $this->actingAs($account, 'establishment')->get('/portal/establishment/photos/create?modal=1')->assertOk()->assertSee('Which one does it show?');
    }

    // ---------- packages: accessibility and suitability, photos ----------

    private function operatorAccount(): EstablishmentAccount
    {
        $operator = $this->listing('tour_operator', 'Island Explorers');
        $operator->update(['is_accredited' => true]);

        return $this->account('tour_operator', $operator, 'ops@test.example.com');
    }

    private function form(array $over = []): array
    {
        return $over + [
            'intent' => 'draft', 'name' => 'Samal Day', 'description' => 'A relaxed day on Samal Island with a beach lunch and a snorkelling stop.',
            'price_per_pax' => 2450, 'price_tier' => 'Mid-range', 'meeting_point' => 'Sasa Wharf', 'itinerary' => "Ferry\nBeach lunch",
            'difficulty' => 'moderate', 'features' => ['seniors', 'route_water', 'route_firstaid'], 'accessibility_note' => 'About 2 hours of walking on sand.',
        ];
    }

    public function test_an_operator_gives_a_package_a_difficulty_who_it_suits_and_what_is_on_the_trip(): void
    {
        $account = $this->operatorAccount();

        $this->actingAs($account, 'establishment')->postJson('/portal/establishment/packages', $this->form())->assertOk();

        $package = Package::firstOrFail();
        $this->assertSame('moderate', $package->difficulty);
        $this->assertSame('About 2 hours of walking on sand.', $package->accessibility_note);
        $this->assertSame(['seniors', 'route_water', 'route_firstaid'], app(AmenityStore::class)->keys('package', $package));

        // the form shows them again, and refuses anything else
        $this->actingAs($account, 'establishment')->get("/portal/establishment/packages/{$package->id}/edit?modal=1")->assertOk()->assertSee('How demanding is it?')->assertSee('Suitable for seniors');
        $this->actingAs($account, 'establishment')->postJson('/portal/establishment/packages', $this->form(['difficulty' => 'extreme']))->assertStatus(422)->assertJsonValidationErrors(['difficulty']);
        $this->actingAs($account, 'establishment')->postJson('/portal/establishment/packages', $this->form(['features' => ['pool']]))->assertStatus(422)->assertJsonValidationErrors(['features.0']);
    }

    public function test_the_public_package_page_and_the_dot_review_show_it(): void
    {
        $account = $this->operatorAccount();
        $this->actingAs($account, 'establishment')->postJson('/portal/establishment/packages', $this->form(['intent' => 'submit']))->assertOk();
        $package = Package::firstOrFail();

        $review = $this->actingAs($this->admin(), 'admin')->get("/portal/admin/package-approvals/{$package->id}?modal=1")->assertOk()->getContent();
        $this->assertStringContainsString('Moderate', $review);
        $this->assertStringContainsString('Suitable for seniors', $review);
        $this->assertStringContainsString('Accessibility information given', $review);
        $this->assertStringContainsString('no photo', $review, 'a claim with no photo behind it says so');

        $this->actingAs($this->admin(), 'admin')->postJson("/portal/admin/package-approvals/{$package->id}/decide", ['decision' => 'approve'])->assertOk();

        auth()->forgetGuards();
        $this->get(route('packages.show', $package))->assertOk()->assertSee('Accessibility')->assertSee('Moderate.', false)->assertSee('Suitable for seniors (65+)')->assertSee('Drinking water provided')->assertSee('About 2 hours of walking on sand.');
    }

    public function test_package_photos_need_a_credit_and_an_amenity_photo_says_what_it_shows(): void
    {
        Storage::fake('public');
        $account = $this->operatorAccount();
        $this->actingAs($account, 'establishment')->postJson('/portal/establishment/packages', $this->form(['features' => []]))->assertOk();
        $package = Package::firstOrFail();
        $client = $this->actingAs($account, 'establishment');
        $url = "/portal/establishment/packages/{$package->id}/photos";
        $file = fn () => [UploadedFile::fake()->image('p.jpg')];

        $client->postJson($url, ['photos' => $file(), 'category' => 'General'])->assertStatus(422)->assertJsonValidationErrors(['credit_text']);
        $client->postJson($url, ['photos' => $file(), 'category' => 'Accessibility', 'own' => 1])->assertStatus(422)->assertJsonValidationErrors(['amenity']);
        $client->postJson($url, ['photos' => $file(), 'category' => 'Accessibility', 'own' => 1, 'amenity' => 'wheel'])->assertOk();

        $photo = ListingPhoto::firstOrFail();
        $this->assertSame('package', $photo->listing_kind);
        $this->assertSame('wheel', $photo->amenity);
        $this->assertTrue($photo->is_primary);
        $this->assertSame(['wheel'], app(AmenityStore::class)->keys('package', $package), 'it shows the trip is step-free, so that is ticked');

        $client->get("/portal/establishment/packages/{$package->id}")->assertOk()->assertSee('Shows: Wheelchair or step-free');
        $client->get("/portal/establishment/packages/{$package->id}/photos/create?modal=1")->assertOk()->assertSee('What does it show?');

        // another operator cannot add to or remove from it
        $other = $this->account('tour_operator', $this->listing('tour_operator', 'Other Tours'), 'other@test.example.com');
        $this->actingAs($other, 'establishment')->get("{$url}/create")->assertForbidden();
        $this->actingAs($other, 'establishment')->delete("{$url}/{$photo->id}")->assertForbidden();

        $this->actingAs($account, 'establishment')->delete("{$url}/{$photo->id}")->assertRedirect();
        $this->assertSame(0, ListingPhoto::count());
    }

    public function test_deleting_a_package_removes_its_ticked_features(): void
    {
        $account = $this->operatorAccount();
        $this->actingAs($account, 'establishment')->postJson('/portal/establishment/packages', $this->form())->assertOk();
        $package = Package::firstOrFail();
        $this->assertSame(3, \App\Models\ListingAmenity::count());

        $this->actingAs($account, 'establishment')->delete("/portal/establishment/packages/{$package->id}")->assertRedirect();

        $this->assertSame(0, \App\Models\ListingAmenity::count());
    }

    private ?AdminUser $admin = null;

    private function admin(): AdminUser
    {
        return $this->admin ??= AdminUser::create(['email' => 'dot@test.example.com', 'password_hash' => Hash::make('x'), 'full_name' => 'Ana Reyes', 'role' => 'super_admin']);
    }

    // ---------- the public pages ----------

    public function test_stays_restaurants_and_souvenir_centers_show_their_amenities_and_accessibility(): void
    {
        $store = app(AmenityStore::class);
        $cases = [
            ['accommodation', 'accommodations.show', ['wifi', 'accroom']],
            ['restaurant', 'restaurants.show', ['parking', 'stepfree']],
            ['souvenir_center', 'souvenir-centers.show', ['gcash', 'accwc']],
        ];

        foreach ($cases as [$kind, $route, $keys]) {
            $listing = $this->listing($kind, 'Public '.$kind);
            $store->sync($kind, $listing, $keys);

            $html = $this->get(route($route, $listing))->assertOk()->getContent();
            $this->assertStringContainsString('Amenities on site', $html, $kind);
            $this->assertStringContainsString('Accessibility', $html, $kind);
            foreach ($keys as $key) {
                $this->assertStringContainsString(e(Amenities::label($key)), $html, "{$kind} shows {$key}");
            }
        }

        $bare = $this->listing('accommodation', 'Bare Inn');
        $this->assertStringNotContainsString('Amenities on site', $this->get(route('accommodations.show', $bare))->getContent());
    }

    public function test_a_destination_shows_the_real_photo_of_an_amenity_when_there_is_one(): void
    {
        $place = $this->listing('destination', 'Eden');
        app(AmenityStore::class)->sync('destination', $place, ['parking', 'ramp']);
        ListingPhoto::create(['listing_kind' => 'destination', 'listing_id' => $place->id, 'path' => 'listings/ramp-real.jpg', 'category' => 'Accessibility', 'amenity' => 'ramp', 'credit_text' => 'Rey Santos']);

        $html = $this->get(route('destinations.show', $place))->assertOk()->getContent();

        $this->assertStringContainsString('Accessibility Ramp', $html);
        $this->assertStringContainsString('ramp-real.jpg', $html, 'the viewer opens the real photo');
        $this->assertStringContainsString('Photo: Rey Santos', $html);
        $this->assertStringContainsString('parking-area.svg', $html, 'with no photo it shows the generic plate');
    }

    // ---------- DOT ----------

    public function test_dot_ticks_amenities_in_the_listing_form_and_the_completeness_check_follows(): void
    {
        $admin = $this->admin();
        $stay = $this->listing('accommodation', 'Admin Inn');

        $form = $this->actingAs($admin, 'admin')->get("/portal/admin/listings/accommodations/{$stay->id}/edit?modal=1")->assertOk()->getContent();
        $this->assertStringContainsString('name="amenities[]"', $form);
        $this->assertStringContainsString('Accessible room', $form);

        $payload = ['name' => 'Admin Inn', 'location' => 'Davao City', 'region_id' => $this->region()->id, 'type' => 'Hotel', 'amenities_form' => 1, 'amenities' => ['wifi', 'pool', 'ac', 'parking']];
        $this->actingAs($admin, 'admin')->putJson("/portal/admin/listings/accommodations/{$stay->id}", $payload)->assertOk();
        $this->assertSame(['parking', 'wifi', 'pool', 'ac'], app(AmenityStore::class)->keys('accommodation', $stay), 'in catalog order');

        $miss = fn () => app(\App\Services\Admin\DataCompleteness::class)->missFor('accommodations', $stay->fresh());
        $this->assertNotContains('amenities', $miss());

        // a form that does not carry the amenities does not clear them
        $this->actingAs($admin, 'admin')->putJson("/portal/admin/listings/accommodations/{$stay->id}", array_diff_key($payload, ['amenities_form' => 1, 'amenities' => 1]))->assertOk();
        $this->assertSame(4, count(app(AmenityStore::class)->keys('accommodation', $stay)));

        app(AmenityStore::class)->sync('accommodation', $stay, ['wifi']);
        $this->assertContains('amenities', $miss());
    }

    public function test_dot_can_set_a_packages_difficulty_and_features(): void
    {
        $package = $this->listing('package', 'DOT Package');

        $this->actingAs($this->admin(), 'admin')->putJson("/portal/admin/listings/packages/{$package->id}", [
            'name' => 'DOT Package', 'location' => 'Davao City', 'region_id' => $this->region()->id, 'difficulty' => 'hard', 'accessibility_note' => 'Steep.',
            'amenities_form' => 1, 'features' => ['route_lift', 'wheel'],
        ])->assertOk();

        $package->refresh();
        $this->assertSame('hard', $package->difficulty);
        $this->assertSame(['wheel', 'route_lift'], app(AmenityStore::class)->keys('package', $package));
    }
}
