<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** One stay serves the whole trip, so it must not sit an hours-long drive from one day's stop. */
class StayChoiceTest extends TestCase
{
    use RefreshDatabase;

    private function stop(string $name, float $lat, float $lng): array
    {
        $d = Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Nature', 'is_accredited' => true,
            'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng,
        ]);

        return ['row' => ['destination' => $d], 'distance_km' => null];
    }

    private function stay(string $name, float $lat, float $lng): Accommodation
    {
        return Accommodation::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Hotel', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng,
        ]);
    }

    private function pick(array $stops): ?array
    {
        $method = new \ReflectionMethod(ItineraryGenerationService::class, 'pickAccommodation');
        $method->setAccessible(true);
        $preference = TouristPreference::create([
            'travel_days' => 3, 'travel_type' => 'Solo', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
        ]);

        return $method->invoke(app(ItineraryGenerationService::class), $stops, $preference);
    }

    public function test_the_stay_is_not_put_beside_two_stops_when_that_leaves_the_third_far_away(): void
    {
        // Two stops close together in the north and one about 78 km south.
        $stops = [$this->stop('North A', 7.45, 125.80), $this->stop('North B', 7.46, 125.81), $this->stop('South', 6.75, 125.80)];

        $north = $this->stay('North Inn', 7.455, 125.805);   // ~0 km from two stops, ~78 km from the third
        $middle = $this->stay('Middle Inn', 7.10, 125.80);   // ~40 km from every stop

        $this->assertSame($middle->id, $this->pick($stops)['listing']->id);
        $this->assertNotSame($north->id, $this->pick($stops)['listing']->id);
    }

    public function test_when_every_stop_is_close_the_nearest_stay_still_wins(): void
    {
        $stops = [$this->stop('A', 7.07, 125.60), $this->stop('B', 7.08, 125.61)];
        $near = $this->stay('Near Inn', 7.075, 125.605);
        $this->stay('Far Inn', 7.30, 125.90);

        $this->assertSame($near->id, $this->pick($stops)['listing']->id);
    }
}
