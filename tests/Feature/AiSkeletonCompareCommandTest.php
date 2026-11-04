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
 * Runs `itinerary:ai-skeleton-compare` for real over the 96 fixed FollowabilityReport trips (offline stand-in, no network). Catalogue as FollowabilityCommandRunTest: it builds its fixed trips against a small but realistic catalogue (places near the
 * start, a day trip away and far away, with stays), reports the three ranges in separate columns, writes its figures, and
 * leaves nothing behind.
 */
class AiSkeletonCompareCommandTest extends TestCase
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

    public function test_the_stage_is_bounded_on_all_96_scenarios_and_keeps_nothing(): void
    {
        $itineraries = Itinerary::count();
        $preferences = TouristPreference::count();

        $code = Artisan::call('itinerary:ai-skeleton-compare', ['--set' => 'all']);
        $output = Artisan::output();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('96 trips (all)', $output);
        $this->assertStringContainsString('All invariants hold.', $output);
        foreach (['fewer stops than baseline', 'outside the ranked candidates', 'more late check-ins than baseline', 'failed call differs from baseline'] as $check) {
            $this->assertMatchesRegularExpression('/'.preg_quote($check, '/').'\s*\|\s*0\s*\|/', $output);
        }
        $this->assertSame($itineraries, Itinerary::count(), 'rolled back');
        $this->assertSame($preferences, TouristPreference::count());
        $this->assertNull(config('services.groq.key'), 'config restored, stand-in not left bound as a real key');
    }
}
