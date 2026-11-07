<?php

namespace Tests\Feature;

use App\Models\AccreditationRecord;
use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Support\ItineraryCalendar;
use App\Support\SampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Plain-text surfaces (calendar file, CSV, print view, map data, pickers) cannot carry an HTML badge, so SampleData
 * gives them one central text marker. The 7 original sample places have no "[SAMPLE]" in their stored name and are
 * marked here instead; the stored name never changes.
 */
class SampleLabelPlainTextTest extends TestCase
{
    use RefreshDatabase;

    private function place(string $name, bool $sample, bool $record = true): Destination
    {
        $d = Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Wildlife', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Budget-Friendly', 'latitude' => 7.15, 'longitude' => 125.40,
        ]);

        if ($record) {
            AccreditationRecord::create([
                'listing_kind' => 'destination', 'listing_id' => $d->id, 'accreditation_number' => ($sample ? 'SAMPLE-' : 'DOT-R11-').$d->id,
                'status' => 'Active', 'issue_date' => now()->subMonth(), 'expiration_date' => now()->addYear(), 'is_placeholder' => $sample,
            ]);
        }

        return $d;
    }

    private function admin(): AdminUser
    {
        return AdminUser::create(['email' => 'a@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Admin', 'role' => 'super_admin']);
    }

    public function test_the_marker_is_central_and_idempotent(): void
    {
        $this->assertSame('', SampleData::suffix(SampleData::OFFICIAL));
        $this->assertSame('Eden Nature Park [SAMPLE]', SampleData::labelName('Eden Nature Park', SampleData::SAMPLE));
        $this->assertSame('Old Mill [NOT YET VERIFIED]', SampleData::labelName('Old Mill', SampleData::UNVERIFIED));
        $this->assertSame('Sample Falls [SAMPLE]', SampleData::labelName('Sample Falls [SAMPLE]', SampleData::SAMPLE), 'A name that already says so is not marked twice.');
        $this->assertSame('Real Park', SampleData::labelName('Real Park', SampleData::OFFICIAL));

        $core = $this->place('Samal Island', true);
        $official = $this->place('Real Farm', false);
        $sd = app(SampleData::class);
        $this->assertSame('Samal Island [SAMPLE]', $sd->labelledName($core));
        $this->assertSame('Real Farm', $sd->labelledName($official));
        $this->assertSame('Samal Island', $core->fresh()->name, 'The stored name is untouched.');
        $this->assertSame('Samal Island [SAMPLE]', $sd->labelledNameOf('destination', $core->id, 'Samal Island'));
    }

    public function test_the_calendar_file_marks_a_sample_stop_and_leaves_an_official_one(): void
    {
        $sample = $this->place('Eden Nature Park', true);
        $official = $this->place('Real Farm', false);
        $pref = TouristPreference::create(['travel_days' => 1, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Budget-Friendly', 'accommodation_pref' => 'Any', 'distance_pref' => 'near']);
        $trip = Itinerary::create(['preference_id' => $pref->id, 'total_days' => 1, 'generated_at' => now()]);
        foreach ([$sample, $official] as $i => $d) {
            ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => 1, 'sort_order' => $i + 1, 'slot' => 'Morning', 'kind' => 'activity',
                'title' => 'Visit — '.$d->name, 'starts_at' => ($i ? '13:00:00' : '09:00:00'), 'ends_at' => ($i ? '15:00:00' : '11:00:00'), 'destination_id' => $d->id]);
        }

        // Calendar lines fold at 75 bytes: unfold before reading the text.
        $ics = preg_replace('/?
 /', '', app(ItineraryCalendar::class)->build($trip->fresh('items'), now()->addDays(3)->toDateString()));

        $this->assertStringContainsString('SUMMARY:Eden Nature Park [SAMPLE]', $ics);
        $this->assertStringContainsString('SUMMARY:Real Farm'."\r\n", $ics);
        $this->assertStringNotContainsString('Real Farm [SAMPLE]', $ics);
        $this->assertStringContainsString('not an official DOT record', $ics);
        $this->assertSame(1, substr_count($ics, 'not an official DOT record'));
    }

    public function test_every_csv_says_whether_simulated_data_is_in_it(): void
    {
        $admin = $this->admin();
        $range = ['from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()];

        $without = $this->actingAs($admin, 'admin')->get(route('admin.reports.export-csv', $range + ['report_type' => 'Exit Survey Responses']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Simulated demonstration survey rows are NOT included', $without);

        $with = $this->actingAs($admin, 'admin')->get(route('admin.reports.export-csv', $range + ['report_type' => 'Exit Survey Responses', 'include_simulated' => 1]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Simulated demonstration survey rows ARE included', $with);

        foreach (['Destination Visits', 'Accreditation Status', 'Trip Plans Created', 'Verified Visits'] as $type) {
            $csv = $this->actingAs($admin, 'admin')->get(route('admin.reports.export-csv', $range + ['report_type' => $type]))->assertOk()->streamedContent();
            $this->assertStringContainsString('Data included', $csv, $type);
        }
    }

    public function test_the_print_view_and_screen_state_the_same_note(): void
    {
        $admin = $this->admin();
        $params = ['report_type' => 'Exit Survey Responses', 'from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()];

        $print = $this->actingAs($admin, 'admin')->get(route('admin.reports.print', $params))->assertOk()->getContent();
        $this->assertStringContainsString('Data included:', $print);
        $this->assertStringContainsString('NOT included', $print);

        $screen = $this->actingAs($admin, 'admin')->get(route('admin.reports', $params + ['include_simulated' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('Data included:', $screen);
        $this->assertStringContainsString('ARE included', $screen);
    }

    public function test_the_destinations_map_data_carries_the_marker(): void
    {
        $this->place('Eden Nature Park', true);
        $this->place('Real Farm', false);

        $html = $this->get(route('destinations.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Eden Nature Park [SAMPLE]', html_entity_decode($html));
        $this->assertStringNotContainsString('Real Farm [SAMPLE]', html_entity_decode($html));
    }
}
