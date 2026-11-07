<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * One stay-distance rule on every path: a stay more than 60 km from any stop of the trip is passed over (the paper's
 * wording), whether it came from past travellers' pairings, the multi-night planner, or the one-stay catalogue pick.
 * A trip that would be left with no stay at all keeps the closest ones.
 */
class StayDistanceLimitTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 7.0731;

    private const LNG = 125.6128;

    private const KM = 111.0;

    private function region(): int
    {
        return Region::firstOrCreate(['name' => 'Davao City'])->id;
    }

    private function stop(string $name, float $northKm): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region(),
            'type' => 'Nature & Adventure', 'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Mid-range',
            'hours' => '8:00 AM-5:00 PM', 'latitude' => self::LAT + $northKm / self::KM, 'longitude' => self::LNG,
        ]);
    }

    private function stay(string $name, float $northKm, float $rating = 4.0): Accommodation
    {
        return Accommodation::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region(),
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => $rating, 'review_count' => 1, 'price_tier' => 'Mid-range',
            'latitude' => self::LAT + $northKm / self::KM, 'longitude' => self::LNG,
        ]);
    }

    private function preference(int $days): TouristPreference
    {
        return TouristPreference::create([
            'travel_days' => $days, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'moderate', 'start_date' => now()->addDays(10)->toDateString(), 'arrival_time' => '08:00',
            'origin_lat' => self::LAT, 'origin_lng' => self::LNG,
        ]);
    }

    private ?ItineraryGenerationService $service = null;

    private function service(): ItineraryGenerationService
    {
        return $this->service ??= app(ItineraryGenerationService::class);
    }

    private function setTripStops(array $northKms): void
    {
        $stops = collect($northKms)->map(fn ($km) => ['lat' => self::LAT + $km / self::KM, 'lng' => self::LNG]);
        (new ReflectionProperty(ItineraryGenerationService::class, 'tripStops'))->setValue($this->service(), $stops);
    }

    /** @return array<int, Collection<int, Accommodation>> */
    private function eligible(TouristPreference $preference, int $nights): array
    {
        $m = new ReflectionMethod(ItineraryGenerationService::class, 'eligibleStays');

        return $m->invoke($this->service(), $preference, $nights);
    }

    public function test_the_multi_night_planner_never_offers_a_stay_over_60_km_from_a_stop_when_a_closer_one_exists(): void
    {
        $near = $this->stay('City Hotel', 5);
        $far = $this->stay('Distant Lodge', 100, 5.0);   // better rated, but 95 km from the stops
        $this->setTripStops([2, 8]);

        $stays = $this->eligible($this->preference(3), 2);

        foreach ([1, 2] as $night) {
            $ids = $stays[$night]->pluck('id')->all();
            $this->assertContains($near->id, $ids);
            $this->assertNotContains($far->id, $ids, "night $night offers a stay 95 km from a stop");
        }
    }

    public function test_a_stay_within_60_km_of_every_stop_is_kept_even_when_far_from_the_start(): void
    {
        $edge = $this->stay('Edge Hotel', 55);
        $this->setTripStops([30, 80]);   // 25 and 25 km away

        $this->assertContains($edge->id, $this->eligible($this->preference(2), 1)[1]->pluck('id')->all());
    }

    public function test_a_night_with_only_far_stays_keeps_them_rather_than_having_no_stay(): void
    {
        $far = $this->stay('Only Lodge', 100);
        $this->setTripStops([2]);

        $this->assertSame([$far->id], $this->eligible($this->preference(2), 1)[1]->pluck('id')->all());
    }

    public function test_a_remote_trips_multi_night_planner_keeps_a_stay_on_the_way(): void
    {
        $onTheWay = $this->stay('Roadside Inn', 10);
        $hub = $this->stay('Hub Inn', 140);
        $this->setTripStops([140, 142]);
        $preference = $this->preference(3);
        $preference->update(['distance_pref' => 'far']);

        $ids = $this->eligible($preference->fresh(), 2)[1]->pluck('id')->all();

        $this->assertContains($onTheWay->id, $ids);
        $this->assertContains($hub->id, $ids);
    }

    public function test_with_no_stops_known_nothing_is_filtered(): void
    {
        $far = $this->stay('Distant Lodge', 100);
        $near = $this->stay('City Hotel', 5);
        (new ReflectionProperty(ItineraryGenerationService::class, 'tripStops'))->setValue($this->service(), null);

        $this->assertEqualsCanonicalizing([$far->id, $near->id], $this->eligible($this->preference(2), 1)[1]->pluck('id')->all());
    }

    public function test_the_one_stay_pick_prefers_a_stay_within_reach_of_every_stop(): void
    {
        $near = $this->stay('City Hotel', 5);
        $this->stay('Distant Lodge', 100, 5.0);
        $stop = $this->stop('Garden', 2);

        $m = new ReflectionMethod(ItineraryGenerationService::class, 'pickAccommodation');
        $pick = $m->invoke($this->service(), [['row' => ['destination' => $stop], 'distance_km' => 1.0]], $this->preference(1));

        $this->assertSame($near->id, $pick['listing']->id);
    }

    public function test_the_one_stay_pick_still_offers_a_far_stay_when_it_is_the_only_one(): void
    {
        $only = $this->stay('Only Lodge', 100);
        $stop = $this->stop('Garden', 2);

        $m = new ReflectionMethod(ItineraryGenerationService::class, 'pickAccommodation');
        $pick = $m->invoke($this->service(), [['row' => ['destination' => $stop], 'distance_km' => 1.0]], $this->preference(1));

        $this->assertSame($only->id, $pick['listing']->id);
    }

    public function test_a_generated_trip_sleeps_within_60_km_of_all_its_stops(): void
    {
        $this->stop('Garden A', 3);
        $this->stop('Garden B', 6);
        $this->stop('Garden C', 9);
        $this->stop('Garden D', 12);
        $near = $this->stay('City Hotel', 5);
        $this->stay('Distant Lodge', 110, 5.0);

        $trip = $this->service()->generate($this->preference(3)->load('activities', 'amenities'))->fresh('items');

        $stayIds = $trip->items->whereNotNull('accommodation_id')->pluck('accommodation_id')->unique()->all();
        $this->assertNotEmpty($stayIds);
        $this->assertSame([$near->id], array_values($stayIds));
    }
}
