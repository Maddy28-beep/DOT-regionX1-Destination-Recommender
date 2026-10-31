<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A day with no sightseeing stop is a wasted day. When the places ranked for the trip were all too far to reach and
 * visit that day, the next places in the ranking (kept in reserve) are tried at the front of it; the change is kept
 * only if it loses no stop anywhere and leaves fewer empty days.
 */
class EmptyDayReserveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function place(string $name, float $km, float $rating): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Nature & Leisure', 'is_accredited' => true, 'rating' => $rating, 'review_count' => 20, 'price_tier' => 'Mid-range',
            'latitude' => 7.07 + $km / 111.2, 'longitude' => 125.61, 'distance_km' => $km,
        ]);
    }

    private function plan(string $arrival, int $days = 2)
    {
        $preference = TouristPreference::create([
            'travel_days' => $days, 'travel_type' => 'Family', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'far',
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Davao City', 'start_date' => '2026-10-28',
            'arrival_time' => $arrival, 'origin_lat' => 7.07, 'origin_lng' => 125.61, 'origin_label' => 'Start',
        ]);

        return app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'), 7.07, 125.61);
    }

    private function stopsByDay($itinerary): array
    {
        return $itinerary->items()->where('kind', 'activity')->whereNotNull('destination_id')->with('destination')->orderBy('day_number')->orderBy('sort_order')->get()
            ->groupBy('day_number')->map(fn ($rows) => $rows->map(fn ($r) => $r->destination->name)->all())->all();
    }

    public function test_a_day_the_far_places_cannot_fill_gets_the_nearest_reserve_place(): void
    {
        // Three well-ranked places 150 km away (a 4-hour drive each way): none can be visited on a 14:00 arrival.
        foreach (['Far One', 'Far Two', 'Far Three'] as $i => $name) {
            $this->place($name, 150 + $i * 5, 5.0);
        }
        // Lower-ranked places close to the start.
        $this->place('Near One', 8, 2.0);
        $this->place('Near Two', 12, 2.0);
        $this->place('Near Three', 15, 2.0);

        $stops = $this->stopsByDay($this->plan('14:00'));

        $this->assertArrayHasKey(1, $stops, 'the arrival day has a stop');
        $this->assertStringStartsWith('Near', $stops[1][0], 'and it is a place that can actually be reached that afternoon');
    }

    public function test_a_trip_that_already_has_a_stop_every_day_is_left_alone(): void
    {
        foreach (['Close One', 'Close Two', 'Close Three', 'Close Four'] as $i => $name) {
            $this->place($name, 6 + $i * 2, 4.5);
        }
        $this->place('Reserve Place', 9, 1.0);

        $stops = $this->stopsByDay($this->plan('09:00'));

        $this->assertCount(2, $stops, 'both days have stops');
        $this->assertNotContains('Reserve Place', array_merge(...array_values($stops)), 'the reserve is not used when no day is empty');
    }

    public function test_when_every_reserve_option_exceeds_the_limits_no_invalid_success_is_returned(): void
    {
        // Everything is about 3 hours away: no reserve place can be reached on the arrival afternoon either.
        foreach (['Far A', 'Far B', 'Far C', 'Far D', 'Far E'] as $i => $name) {
            $this->place($name, 55 + $i * 4, 5.0 - $i * 0.1);
        }

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->plan('15:00');
    }
}
