<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Region;
use App\Models\SouvenirCenter;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryScheduleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * On a Remote trip the way home is already long, so the last day's souvenir stop is only added when the traveller is
 * still back by 8 pm with it, counting the dinner break a late journey needs. Other trips are planned as before.
 */
class RemoteLastDaySouvenirTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN_LAT = 7.0731;

    private const ORIGIN_LNG = 125.6128;

    private function region(): int
    {
        return Region::firstOrCreate(['name' => 'Davao City'])->id;
    }

    private function destination(string $name, float $northKm): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => $this->region(), 'type' => 'Nature & Adventure', 'is_accredited' => true,
            // Fix the experience length so the test isolates the optional-shopping rule.
            'visit_duration' => '90 minutes',
            'rating' => 4, 'review_count' => 10, 'price_tier' => 'Mid-range',
            'latitude' => self::ORIGIN_LAT + $northKm / 111.0, 'longitude' => self::ORIGIN_LNG,
        ]);
    }

    private function shop(string $name, float $northKm): SouvenirCenter
    {
        return SouvenirCenter::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => $this->region(), 'is_accredited' => true, 'rating' => 4, 'review_count' => 1,
            'latitude' => self::ORIGIN_LAT + $northKm / 111.0, 'longitude' => self::ORIGIN_LNG,
        ]);
    }

    /** A one-day trip to a single stop, placed by hand so the souvenir rule is checked on its own. */
    private function schedule(Destination $stop, string $distancePref, string $arrival): Itinerary
    {
        $preference = TouristPreference::create([
            'travel_days' => 1, 'travel_type' => 'Family', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any',
            'distance_pref' => $distancePref, 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'place_of_origin' => 'Manila', 'arrival_time' => $arrival,
        ]);
        $itinerary = Itinerary::create(['preference_id' => $preference->id, 'total_days' => 1, 'generated_at' => now()]);

        app(ItineraryScheduleBuilder::class)->build(
            $itinerary,
            [['row' => ['destination' => $stop->load('region'), 'drs' => 4.0], 'distance_km' => 1.0]],
            $preference,
            [1 => ['Morning', 'Afternoon', 'Evening']],
            ['lat' => self::ORIGIN_LAT, 'lng' => self::ORIGIN_LNG, 'label' => 'Davao City centre'],
            null,
        );

        return $itinerary->fresh('items');
    }

    private function hasSouvenirStop(Itinerary $itinerary): bool
    {
        return $itinerary->items->contains(fn ($item) => $item->title === 'Souvenir shopping');
    }

    private function homeAt(Itinerary $itinerary): string
    {
        return $itinerary->items->sortBy('sort_order')->last()->starts_at;
    }

    private function setUpStopAndShop(float $stopKm = 50): Destination
    {
        $stop = $this->destination('Remote Falls', $stopKm);
        $this->shop('Local Crafts', $stopKm + 4);   // 4 km past the stop, so it passes the shop's own distance and time checks

        return $stop;
    }

    public function test_a_remote_trip_keeps_the_souvenir_stop_when_it_still_gets_home_by_eight(): void
    {
        $itinerary = $this->schedule($this->setUpStopAndShop(), 'far', '07:00');

        $this->assertTrue($this->hasSouvenirStop($itinerary));
        $this->assertLessThanOrEqual('20:00:00', $this->homeAt($itinerary));
    }

    public function test_a_remote_trip_leaves_out_the_souvenir_stop_that_would_get_it_home_after_eight(): void
    {
        $itinerary = $this->schedule($this->setUpStopAndShop(), 'far', '09:00');

        $this->assertNotNull($itinerary->items->firstWhere('kind', 'activity'), 'the trip itself is untouched');
        $this->assertFalse($this->hasSouvenirStop($itinerary));
        $this->assertSame('departure', $itinerary->items->sortBy('sort_order')->last()->kind, 'the day still ends properly');
        $this->assertLessThanOrEqual('20:00:00', $this->homeAt($itinerary));
    }

    public function test_the_same_day_on_a_moderate_trip_is_planned_as_before(): void
    {
        $itinerary = $this->schedule($this->setUpStopAndShop(), 'moderate', '09:00');

        $this->assertTrue($this->hasSouvenirStop($itinerary), 'only Remote trips are held to the 8 pm rule');
        $this->assertGreaterThan('20:00:00', $this->homeAt($itinerary));
        $this->assertTrue($itinerary->items->contains(fn ($item) => $item->kind === 'meal' && str_contains($item->title, 'Dinner')), 'the dinner break is still counted in that late return');
    }

    public function test_one_build_does_not_leak_remote_planning_into_the_next(): void
    {
        $stop = $this->setUpStopAndShop();

        $this->assertFalse($this->hasSouvenirStop($this->schedule($stop, 'far', '09:00')));
        $this->assertTrue($this->hasSouvenirStop($this->schedule($stop, 'moderate', '09:00')), 'the same builder, planning a moderate trip straight after, adds it');
    }

    public function test_the_guard_drops_optional_shopping_without_shortening_the_recorded_visit(): void
    {
        $stop = $this->setUpStopAndShop();
        $stop->update(['visit_duration' => '150 minutes']);

        $itinerary = $this->schedule($stop, 'far', '07:00');
        $visit = $itinerary->items->first(fn ($item) => $item->kind === 'activity' && $item->destination_id === $stop->id);

        $this->assertNotNull($visit);
        $this->assertSame(150, (int) \Illuminate\Support\Carbon::parse($visit->starts_at)->diffInMinutes(\Illuminate\Support\Carbon::parse($visit->ends_at)));
        $this->assertFalse($this->hasSouvenirStop($itinerary));
    }
}
