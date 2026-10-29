<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\AccreditationRecord;
use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\Package;
use App\Models\Region;
use App\Models\TourOperator;
use App\Models\TouristPreference;
use App\Support\SampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Places where a sample could be read as DOT-verified: the tour-operator card and package page, the admin
 * accreditation list and its report, the counts that say "accredited", and the QR code and check-in that would
 * turn a sample into a place with real visits.
 */
class SampleDataSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private function region(): int
    {
        return Region::firstOrCreate(['name' => 'Davao City'])->id;
    }

    private int $recordCount = 0;

    private function record(string $kind, int $id, string $number, bool $placeholder): void
    {
        AccreditationRecord::create([
            'listing_kind' => $kind, 'listing_id' => $id, 'accreditation_number' => $number.'-'.(++$this->recordCount), 'status' => 'Active',
            'issue_date' => now()->subMonths(3), 'expiration_date' => now()->addYear(), 'is_placeholder' => $placeholder,
        ]);
    }

    private function destination(string $slug, bool $sample): Destination
    {
        $d = Destination::create([
            'slug' => $slug, 'name' => ucfirst($slug), 'location' => 'Malagos, Davao City', 'region_id' => $this->region(),
            'type' => 'Wildlife', 'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Budget-Friendly',
            'latitude' => 7.15, 'longitude' => 125.40,
        ]);
        $this->record('destination', $d->id, $sample ? 'SAMPLE-0001' : 'DOT-R11-REAL-0001', $sample);

        return $d;
    }

    private function operator(string $slug, bool $sample): TourOperator
    {
        $o = TourOperator::create([
            'slug' => $slug, 'name' => ucfirst($slug).' Tours', 'location' => 'Davao City', 'region_id' => $this->region(),
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'contact_number' => '0917 111 2222',
            'latitude' => 7.07, 'longitude' => 125.61,
        ]);
        $this->record('tour_operator', $o->id, $sample ? 'DOT-XI-2025-0099' : 'DOT-R11-TRA-00001-2025', $sample);

        return $o;
    }

    private function admin(): AdminUser
    {
        return AdminUser::create(['email' => 'a@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Admin', 'role' => 'super_admin']);
    }

    private function card(array $operators): string
    {
        return view('partials.itinerary-planned-around', [
            'preference' => null,
            'operators' => collect($operators)->map(fn ($o) => ['operator' => $o, 'km' => 3.2]),
            'fmtKm' => fn ($km) => number_format($km, 1),
        ])->render();
    }

    public function test_a_sample_tour_operator_on_the_pickup_card_is_not_called_dot_accredited(): void
    {
        $html = $this->card([$this->operator('real', false), $this->operator('placeholder', true)]);

        $this->assertStringContainsString('Placeholder Tours', $html);
        $this->assertStringContainsString('Sample data, not DOT-verified', $html);
        $this->assertStringContainsString('Operators marked sample data are not DOT-verified', $html);
        $this->assertStringNotContainsString('DOT-accredited, close to your start', $html);
    }

    public function test_a_pickup_card_of_real_operators_still_says_dot_accredited(): void
    {
        $html = $this->card([$this->operator('real', false)]);

        $this->assertStringContainsString('DOT-accredited, close to your start', $html);
        $this->assertStringNotContainsString('Sample data', $html);
    }

    public function test_a_package_by_a_sample_operator_says_so_and_never_claims_an_unknown_provider_is_accredited(): void
    {
        $sampleOp = $this->operator('placeholder', true);
        $package = Package::create([
            'slug' => 'trek', 'name' => 'Summit Trek', 'location' => 'Davao del Sur', 'region_id' => $this->region(),
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_per_pax' => 6500,
            'duration_label' => '3 Days, 2 Nights', 'price_tier' => 'Premium', 'type' => 'Adventure', 'tour_operator_id' => $sampleOp->id,
        ]);
        $this->assertStringContainsString('Placeholder Tours (sample data, not DOT-verified)', $this->get(route('packages.show', $package))->assertOk()->getContent());

        $orphan = Package::create([
            'slug' => 'orphan', 'name' => 'Orphan Trip', 'location' => 'Davao City', 'region_id' => $this->region(),
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_per_pax' => 1000,
            'duration_label' => '1 Day', 'price_tier' => 'Budget-Friendly', 'type' => 'Adventure',
        ]);
        $this->assertStringNotContainsString('DOT-accredited operator', $this->get(route('packages.show', $orphan))->assertOk()->getContent());
    }

    public function test_the_admin_accreditation_page_and_report_mark_placeholder_records(): void
    {
        $this->destination('real-place', false);
        $this->destination('sample-place', true);
        $admin = $this->admin();

        $page = $this->actingAs($admin, 'admin')->get(route('admin.accreditation'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($page, 'class="sample-tag"'), 'Only the placeholder record carries the tag.');

        $params = ['report_type' => 'Accreditation Status', 'from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()];
        $csv = $this->actingAs($admin, 'admin')->get(route('admin.reports.export-csv', $params))->assertOk()->streamedContent();
        $this->assertStringContainsString('Data', explode("\n", $csv)[0]);
        $this->assertSame(1, substr_count($csv, 'Sample data, not DOT-verified'));
        $this->assertSame(1, substr_count($csv, 'DOT record'));
    }

    public function test_counts_that_say_accredited_are_split_from_the_sample_flag(): void
    {
        $this->destination('real-one', false);
        $this->destination('real-two', false);
        $this->destination('sample-one', true);

        $this->assertSame(['total' => 3, 'sample' => 1, 'real' => 2], app(SampleData::class)->split(Destination::publiclyVisible()));

        $home = $this->get(route('home'))->assertOk()->getContent();
        $this->assertStringContainsString('including 1 sample data', $home);

        $pref = TouristPreference::create(['travel_days' => 1, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Budget-Friendly', 'accommodation_pref' => 'Any', 'distance_pref' => 'far']);
        $trip = Itinerary::create(['preference_id' => $pref->id, 'total_days' => 1, 'generated_at' => now()]);
        ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => 1, 'sort_order' => 1, 'slot' => 'Morning', 'kind' => 'activity',
            'title' => 'Visit', 'starts_at' => '10:00:00', 'ends_at' => '12:00:00', 'destination_id' => Destination::first()->id]);

        $plan = $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $pref->id])
            ->get(route('plan.itinerary'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/3 destinations \(2 DOT-accredited,\s+1 sample data, not DOT-verified\)/', $plan);
    }

    public function test_a_catalogue_with_no_samples_keeps_the_plain_wording(): void
    {
        $this->destination('real-one', false);

        $this->assertStringNotContainsString('including', $this->get(route('home'))->assertOk()->getContent());
    }

    public function test_a_sample_has_no_qr_code_and_no_check_in_but_a_real_place_does(): void
    {
        $real = $this->destination('real-place', false);
        $sample = $this->destination('sample-place', true);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get(route('admin.listings.qr-code', ['destinations', $real->id]))->assertOk();
        $this->actingAs($admin, 'admin')->get(route('admin.listings.qr-code', ['destinations', $sample->id]))->assertNotFound();

        $this->get(URL::signedRoute('check-in', ['type' => 'destinations', 'id' => $real->id]))->assertRedirect();
        $this->assertDatabaseCount('tourist_visits', 1);

        $this->get(URL::signedRoute('check-in', ['type' => 'destinations', 'id' => $sample->id]))->assertNotFound();
        $this->assertDatabaseCount('tourist_visits', 1);
    }

    public function test_the_admin_form_says_a_sample_has_no_qr_code(): void
    {
        $sample = $this->destination('sample-place', true);
        $real = $this->destination('real-place', false);
        $admin = $this->admin();

        $edit = fn ($d) => $this->actingAs($admin, 'admin')->get(route('admin.listings.edit', ['destinations', $d->id]))->assertOk()->getContent();

        $this->assertStringContainsString('Sample data: no QR code', $edit($sample));
        $this->assertStringNotContainsString('Download QR Code', $edit($sample));
        $this->assertStringContainsString('Download QR Code', $edit($real));
    }
}
