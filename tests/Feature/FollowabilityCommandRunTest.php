<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Region;
use App\Models\TouristPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Runs `itinerary:followability` for real: it builds its 48 fixed trips against a small but realistic catalogue (places near the
 * start, a day trip away and far away, with stays), reports the three ranges in separate columns, writes its figures, and
 * leaves nothing behind.
 */
class FollowabilityCommandRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 12:00:00');
        config(['dot.accreditation_test_grace_until' => null, 'itinerary.stays.force_mode' => null]);
        $this->catalogue();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Places in three rings around Ma-a (the calibration start), each ring in its own part of the region, and stays near them. */
    private function catalogue(): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City'])->id;
        $types = ['Nature & Adventure', 'Beach & Leisure', 'Cultural Heritage', 'Wildlife', 'Farm Tourism', 'Adventure & Hiking'];
        $rings = ['near' => [0.01, 0.03], 'moderate' => [0.18, 0.40], 'far' => [0.65, 0.95]];
        $n = 0;
        foreach ($rings as $ring => [$from, $to]) {
            foreach (range(0, 7) as $i) {
                $offset = $from + ($to - $from) * $i / 7;
                $angle = $i * 0.9;
                $lat = 7.0489 + $offset * sin($angle);
                $lng = 125.5736 + $offset * cos($angle);
                Destination::create(['slug' => "$ring-place-$i", 'name' => ucfirst($ring)." Place $i", 'location' => 'Davao City', 'region_id' => $region, 'type' => $types[$n++ % 6],
                    'is_accredited' => true, 'rating' => 4.9 - $i * 0.05, 'review_count' => 20, 'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng,
                    'distance_km' => round($offset * 111, 1)]);
            }
        }
        foreach ([['Ma-a Inn', 0.0, 0.01], ['Midway Lodge', 0.25, 0.1], ['Far Hotel', 0.7, 0.2], ['Far Resort', 0.8, -0.3]] as $i => [$name, $dLat, $dLng]) {
            $stay = Accommodation::create(['slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $region, 'type' => 'Hotel', 'is_accredited' => true,
                'rating' => 4, 'review_count' => 10, 'price_tier' => 'Mid-range', 'latitude' => 7.0489 + $dLat, 'longitude' => 125.5736 + $dLng]);
            AccreditationRecord::create(['listing_kind' => 'accommodation', 'listing_id' => $stay->id, 'accreditation_number' => 'DOT-T-'.$stay->id, 'issue_date' => '2024-01-01',
                'expiration_date' => '2028-12-31', 'status' => 'Active']);
        }
    }

    public function test_the_calibration_run_reports_the_three_ranges_separately_and_keeps_nothing(): void
    {
        $json = tempnam(sys_get_temp_dir(), 'followability');
        $itineraries = Itinerary::count();
        $preferences = TouristPreference::count();

        $this->assertSame(0, Artisan::call('itinerary:followability', ['--set' => 'calibration', '--json' => $json]));
        $output = Artisan::output();

        $this->assertStringContainsString('CALIBRATION SET (trips 0-47)', $output);
        $this->assertStringContainsString('no figure here is a rule', $output);
        $this->assertMatchesRegularExpression('/\|\s*Close Proximity\s*\|\s*Commutable\s*\|\s*Remote\s*\|/', $output);
        $this->assertMatchesRegularExpression('/Trips \/ days\s*\|\s*16 \/ \d+\s*\|\s*16 \/ \d+\s*\|\s*16 \/ \d+\s*\|/', $output, 'sixteen trips in each range');
        foreach (['COVERAGE: destinations placed', 'DRIVING: average per day', 'STAYS: nights / changes of stay', 'LATE CHECK-INS', 'MEALS:', 'ARRIVAL / TRANSFER DAYS', 'IDLE:'] as $row) {
            $this->assertStringContainsString($row, $output);
        }

        $figures = json_decode((string) file_get_contents($json), true);
        $this->assertSame('calibration', $figures['set']);
        $this->assertSame(['near', 'moderate', 'far'], array_keys($figures['summary']));
        foreach (['near', 'moderate', 'far'] as $band) {
            $this->assertSame(16, $figures['summary'][$band]['trips']);
            $this->assertCount(16, $figures['trips'][$band]);
            $this->assertSame($band, $figures['trips'][$band][0]['band'], 'each range holds only its own trips');
            $this->assertGreaterThan(0, $figures['summary'][$band]['coverage']['placed'], "the {$band} trips placed destinations");
            $coverage = $figures['summary'][$band]['coverage'];
            $this->assertSame($coverage['omitted'], array_sum($coverage['causes']), 'every omission is counted, under a recorded cause or as not recorded');
            $this->assertLessThanOrEqual($coverage['omitted'], $coverage['omitted_neutral']);
        }
        $this->assertSame(range(0, 47), collect($figures['trips'])->flatten(1)->pluck('trip')->sort()->values()->all(), 'exactly trips 0-47, no held-out trip');

        $this->assertSame($itineraries, Itinerary::count(), 'the 48 trips were rolled back');
        $this->assertSame($preferences, TouristPreference::count());
        @unlink($json);
    }

    public function test_the_same_run_twice_gives_the_same_report_and_the_held_out_run_is_labelled_report_only(): void
    {
        Artisan::call('itinerary:followability', ['--set' => 'calibration']);
        $first = Artisan::output();
        Artisan::call('itinerary:followability', ['--set' => 'calibration']);
        $this->assertSame($first, Artisan::output(), 'fixed trips, fixed figures');

        $this->assertSame(0, Artisan::call('itinerary:followability', ['--set' => 'holdout', '--details' => true]));
        $held = Artisan::output();
        $this->assertStringContainsString('HELD-OUT SET (trips 48-95): report only', $held);
        $this->assertStringNotContainsString('CALIBRATION SET', $held, 'the two sets are never in one report');
        $this->assertMatchesRegularExpression('/trip 48,/', $held);
        $this->assertDoesNotMatchRegularExpression('/trip 47,/', $held);
    }
}
