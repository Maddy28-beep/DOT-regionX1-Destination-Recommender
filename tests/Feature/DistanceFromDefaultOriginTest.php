<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ContentBasedRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "How far is this place?" when the traveller has not shared a position. The imported accredited places have
 * coordinates but no stored distance-from-city figure, and their distance used to count as unknown unless a
 * position was shared: every farm scored the same, and a traveller who asked for somewhere near got a place
 * seventy kilometres out. Now such a place is measured from the city centre, the point the plan starts from.
 */
class DistanceFromDefaultOriginTest extends TestCase
{
    use RefreshDatabase;

    private int $regionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->regionId = Region::create(['name' => 'Davao City'])->id;
    }

    private function place(string $name, float $lat, float $lng, ?float $storedKm = null): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao', 'region_id' => $this->regionId,
            'type' => 'Farm Tourism', 'is_accredited' => true, 'rating' => 4.0, 'review_count' => 5, 'price_tier' => 'Mid-range',
            'latitude' => $lat, 'longitude' => $lng, 'distance_km' => $storedKm,
        ]);
    }

    private function nearby(int $count): void
    {
        // a few kilometres from the city centre (7.0731, 125.6128)
        foreach (range(1, $count) as $i) {
            $this->place("Near Place $i", 7.0731 + $i * 0.01, 125.6128);
        }
    }

    private function preference(string $range, int $days = 1, array $extra = []): TouristPreference
    {
        $preference = TouristPreference::create([
            'travel_days' => $days, 'travel_type' => 'Solo', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => $range,
        ]);
        $preference->forceFill($extra)->save();

        return $preference->load('activities', 'amenities');
    }

    private function ranked(TouristPreference $preference, ?ContentBasedRecommendationService &$service = null)
    {
        $service = app(ContentBasedRecommendationService::class);

        return $service->rank($preference)->keyBy(fn ($row) => $row['destination']->name);
    }

    public function test_a_place_seventy_kilometres_out_is_left_out_of_a_near_trip_when_there_are_enough_nearby(): void
    {
        $this->nearby(3);
        $this->place('Far Farm', 6.85, 125.18);   // about 70 km from the centre, no stored distance

        $rows = $this->ranked($this->preference('near'), $service);

        $this->assertFalse($rows->has('Far Farm'), 'A traveller who asked for near is not sent 70 km away.');
        $this->assertCount(3, $rows);
        $this->assertSame('near', $service->lastRangeTierUsed);
        $this->assertFalse($service->lastRangeWidened);
    }

    public function test_the_range_still_widens_when_there_are_not_enough_places_that_close(): void
    {
        $this->nearby(1);
        $this->place('Far Farm', 6.85, 125.18);

        $rows = $this->ranked($this->preference('near'), $service);

        $this->assertTrue($rows->has('Far Farm'), 'Better a farther place than an empty trip.');
        $this->assertTrue($service->lastRangeWidened);
        $this->assertSame('moderate', $service->lastRangeTierUsed);
    }

    public function test_the_distance_score_now_tells_near_from_far_for_places_with_no_stored_distance(): void
    {
        $this->nearby(3);
        $this->place('Far Farm', 6.85, 125.18);

        $rows = $this->ranked($this->preference('far'));   // 'far' keeps both in play so both are scored

        // A traveller willing to go far: the place next door is two distance buckets off (1), the one 70 km out is the bucket they asked for (5).
        $this->assertSame(1.0, $rows['Near Place 1']['ds']);
        $this->assertSame(5.0, $rows['Far Farm']['ds']);
    }

    public function test_a_stored_distance_still_wins_over_the_coordinates(): void
    {
        $this->nearby(3);
        // stored as 10 km from the city although its coordinates are far out: the recorded figure is trusted, as before
        $this->place('Recorded Near', 6.85, 125.18, 10.0);

        $rows = $this->ranked($this->preference('near'));

        $this->assertTrue($rows->has('Recorded Near'));
    }

    public function test_a_shared_position_still_measures_from_that_position(): void
    {
        $this->nearby(3);
        $this->place('Far Farm', 6.85, 125.18);
        $this->place('Far Neighbour 1', 6.86, 125.18);   // enough places around the far point that the range need not widen
        $this->place('Far Neighbour 2', 6.87, 125.18);
        // the traveller is standing at the far farm
        $preference = $this->preference('near', 1, ['origin_lat' => 6.85, 'origin_lng' => 125.18]);

        $rows = $this->ranked($preference);

        $this->assertTrue($rows->has('Far Farm'), 'Near to where they are.');
        $this->assertFalse($rows->has('Near Place 1'), 'And the city is the far place now.');
    }

    public function test_places_with_no_coordinates_and_no_distance_are_still_never_excluded_outright(): void
    {
        $this->nearby(3);
        $unmapped = Destination::create([
            'slug' => 'unmapped', 'name' => 'Unmapped Place', 'location' => 'Somewhere', 'region_id' => Region::create(['name' => 'Nowhere'])->id,
            'type' => 'Farm Tourism', 'is_accredited' => true, 'rating' => 4.0, 'review_count' => 5, 'price_tier' => 'Mid-range',
        ]);

        $rows = $this->ranked($this->preference('near'));

        $this->assertTrue($rows->has($unmapped->name), 'Unknown means cannot judge, not too far.');
    }
}
