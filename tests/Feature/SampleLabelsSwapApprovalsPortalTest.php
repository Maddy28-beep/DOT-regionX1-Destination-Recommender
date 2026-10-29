<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\AccreditationRecord;
use App\Models\AdminUser;
use App\Models\Advisory;
use App\Models\Destination;
use App\Models\DestinationEmbedding;
use App\Models\EstablishmentAccount;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\Package;
use App\Models\Region;
use App\Models\TourOperator;
use App\Models\TouristPreference;
use App\Services\Embeddings\DestinationEmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sample data stays labelled on the swap panel, the package-approval screens and the partner portal: a placeholder
 * operator's package must not read "Operator accreditation is current".
 */
class SampleLabelsSwapApprovalsPortalTest extends TestCase
{
    use RefreshDatabase;

    private int $numbers = 0;

    private function region(): int
    {
        return Region::firstOrCreate(['name' => 'Davao City'])->id;
    }

    private function record(string $kind, int $id, bool $placeholder): void
    {
        AccreditationRecord::create([
            'listing_kind' => $kind, 'listing_id' => $id, 'accreditation_number' => ($placeholder ? 'DOT-XI-' : 'DOT-R11-').(++$this->numbers),
            'status' => 'Active', 'issue_date' => now()->subYear(), 'expiration_date' => now()->addYear(), 'is_placeholder' => $placeholder,
        ]);
    }

    private function place(string $name, array $vector, bool $sample): Destination
    {
        $d = Destination::create([
            'slug' => Str::slug($name), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region(), 'type' => 'Nature & Leisure',
            'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Mid-range', 'latitude' => 7.10, 'longitude' => 125.50,
        ]);
        $this->record('destination', $d->id, $sample);
        DestinationEmbedding::create(['destination_id' => $d->id, 'model' => 'test', 'dimensions' => count($vector),
            'text_hash' => sha1($name), 'vector' => DestinationEmbeddingService::normalise($vector)]);

        return $d;
    }

    private function trip(Destination $stop): array
    {
        $pref = TouristPreference::create(['travel_days' => 1, 'travel_type' => 'Solo', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'far']);
        $trip = Itinerary::create(['preference_id' => $pref->id, 'total_days' => 1, 'generated_at' => now()]);
        ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => 1, 'sort_order' => 1, 'slot' => 'Morning', 'kind' => 'activity',
            'title' => 'Visit '.$stop->name, 'starts_at' => '10:00:00', 'ends_at' => '12:00:00', 'destination_id' => $stop->id]);

        return [$trip, $pref];
    }

    private function operator(string $name, bool $sample): TourOperator
    {
        $o = TourOperator::create(['slug' => Str::slug($name), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region(),
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0]);
        $this->record('tour_operator', $o->id, $sample);

        return $o;
    }

    private function inReview(TourOperator $operator, string $name): Package
    {
        return Package::create(['slug' => Str::slug($name), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region(), 'is_accredited' => false,
            'status' => Package::STATUS_IN_REVIEW, 'submitted_at' => now(), 'rating' => 0, 'review_count' => 0, 'price_per_pax' => 1500,
            'duration_label' => '1 Day', 'price_tier' => 'Mid-range', 'type' => 'Adventure', 'tour_operator_id' => $operator->id,
            'description' => 'A relaxed day with a beach lunch and a snorkelling stop for everyone.']);
    }

    private function admin(): AdminUser
    {
        return AdminUser::create(['email' => 'dot@test.example.com', 'password_hash' => Hash::make('x'), 'full_name' => 'Ana Reyes', 'role' => 'super_admin']);
    }

    public function test_the_swap_panel_flags_a_sample_place_and_not_a_real_one(): void
    {
        $stop = $this->place('Zoo', [1, 0, 0], false);
        $this->place('Mini Zoo', [0.9, 0.1, 0], true);
        $this->place('Garden', [0.5, 0.5, 0], false);
        [$trip] = $this->trip($stop);

        $json = $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id])
            ->getJson(route('plan.alternatives', $stop->id))->assertOk()->json('alternatives');

        $byName = collect($json)->keyBy('name');
        $this->assertTrue($byName['Mini Zoo']['sample']);
        $this->assertFalse($byName['Garden']['sample']);
    }

    public function test_the_swap_script_shows_the_tag_for_a_sample_option(): void
    {
        $js = file_get_contents(public_path('js/itinerary-swap.js'));

        $this->assertStringContainsString('alt.sample', $js);
        $this->assertStringContainsString('Sample data, not DOT-verified', $js);
    }

    public function test_the_closest_open_substitute_of_a_closed_stop_is_tagged_when_it_is_sample_data(): void
    {
        $stop = $this->place('Zoo', [1, 0, 0], false);
        $this->place('Mini Zoo', [0.9, 0.1, 0], true);
        Advisory::create(['title' => 'Closed today', 'message' => 'x', 'severity' => 'danger', 'listing_kind' => 'destination', 'listing_id' => $stop->id]);
        [$trip, $pref] = $this->trip($stop);

        $html = $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $pref->id])
            ->get(route('plan.itinerary'))->assertOk()->getContent();

        $this->assertStringContainsString('Closest open substitute', $html);
        $this->assertMatchesRegularExpression('/<strong>Mini Zoo<\/strong>\s*<span class="sample-tag"/', $html);
    }

    public function test_a_placeholder_operators_package_is_shown_as_sample_data_in_the_approval_screens(): void
    {
        $sample = $this->inReview($this->operator('Placeholder Tours', true), 'Placeholder Trip');
        $real = $this->inReview($this->operator('Island Explorers', false), 'Real Trip');
        $admin = $this->admin();

        $review = $this->actingAs($admin, 'admin')->get("/portal/admin/package-approvals/{$sample->id}?modal=1")->assertOk()->getContent();
        $this->assertStringContainsString('Operator accreditation is a placeholder', $review);
        $this->assertStringContainsString('Sample data, not DOT-verified', $review);
        $this->assertStringNotContainsString('Operator accreditation is current', $review);

        $realReview = $this->actingAs($admin, 'admin')->get("/portal/admin/package-approvals/{$real->id}?modal=1")->assertOk()->getContent();
        $this->assertStringContainsString('Operator accreditation is current', $realReview);
        $this->assertStringNotContainsString('Sample data', $realReview);

        $queue = $this->actingAs($admin, 'admin')->get('/portal/admin/package-approvals')->assertOk()->getContent();
        $this->assertSame(1, substr_count($queue, 'class="sample-tag"'), 'Only the placeholder operator is tagged in the queue.');
    }

    public function test_the_label_does_not_block_a_decision_that_was_possible_before(): void
    {
        $package = $this->inReview($this->operator('Placeholder Tours', true), 'Placeholder Trip');

        $this->actingAs($this->admin(), 'admin')->postJson("/portal/admin/package-approvals/{$package->id}/decide", ['decision' => 'approve'])->assertOk();
    }

    public function test_the_partner_portal_says_when_its_listing_is_sample_data(): void
    {
        $sample = $this->operator('Placeholder Tours', true);
        $real = $this->operator('Island Explorers', false);
        $account = fn (TourOperator $o, string $email) => EstablishmentAccount::create([
            'business_name' => $o->name, 'listing_kind' => 'tour_operator', 'matched_listing_id' => $o->id, 'portal_key' => (string) Str::uuid(),
            'email' => $email, 'password_hash' => Hash::make('x'), 'contact_person' => 'Owner', 'contact_number' => '09170000000',
            'status' => 'approved', 'submitted_at' => now(),
        ]);

        $this->actingAs($account($sample, 's@test.example.com'), 'establishment')->get('/portal/establishment')->assertOk()
            ->assertSee('This listing is sample data, not DOT-verified.');
        $this->actingAs($account($real, 'r@test.example.com'), 'establishment')->get('/portal/establishment')->assertOk()
            ->assertDontSee('This listing is sample data');
    }

    public function test_the_admin_listing_table_tags_sample_data(): void
    {
        $this->place('Sample Falls', [1, 0, 0], true);
        $this->place('Real Falls', [0, 1, 0], false);

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.listings.index', 'destinations'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'class="sample-tag"'));
    }
}
