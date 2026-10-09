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
 * What the day-by-day schedule will and will not do: it keeps to a place's opening hours, it will not
 * spend a day mostly driving, and it only adds a souvenir stop that is close and early enough.
 *
 * These drive the schedule builder directly with hand-placed stops so each rule is checked on its own.
 * Coordinates are kilometres from Davao City centre, turned into degrees (1 degree of latitude is about
 * 111 km), so the journeys are easy to reason about: ten kilometres out is about 40 minutes of driving.
 */
class TripPlannerLimitsTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN_LAT = 7.0731;

    private const ORIGIN_LNG = 125.6128;

    private function at(float $northKm, float $eastKm = 0.0): array
    {
        return [self::ORIGIN_LAT + $northKm / 111.0, self::ORIGIN_LNG + $eastKm / 110.1];
    }

    private function region(): int
    {
        return Region::firstOrCreate(['name' => 'Davao City'])->id;
    }

    private function destination(string $name, float $northKm, float $eastKm = 0.0, ?string $hours = null): Destination
    {
        [$lat, $lng] = $this->at($northKm, $eastKm);

        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => $this->region(), 'type' => 'Nature & Adventure', 'is_accredited' => true,
            'rating' => 4, 'review_count' => 10, 'price_tier' => 'Mid-range',
            'latitude' => $lat, 'longitude' => $lng, 'hours' => $hours,
        ]);
    }

    private function shop(string $name, float $northKm): SouvenirCenter
    {
        [$lat, $lng] = $this->at($northKm);

        return SouvenirCenter::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => $this->region(), 'is_accredited' => true, 'rating' => 4, 'review_count' => 1,
            'latitude' => $lat, 'longitude' => $lng,
        ]);
    }

    /**
     * @param  list<Destination>  $stops
     * @param  int  $slotsPerDay
     */
    private function schedule(array $stops, int $days = 1, string $distancePref = 'moderate', ?string $arrival = null, int $slotsPerDay = 3): Itinerary
    {
        $preference = TouristPreference::create([
            'travel_days' => $days, 'travel_type' => 'Family', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any',
            'distance_pref' => $distancePref, 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'place_of_origin' => 'Manila', 'arrival_time' => $arrival,
        ]);
        $itinerary = Itinerary::create(['preference_id' => $preference->id, 'total_days' => $days, 'generated_at' => now()]);

        $sequence = array_map(fn (Destination $d) => ['row' => ['destination' => $d->load('region'), 'drs' => 4.0], 'distance_km' => 1.0], $stops);
        $capacities = [];
        for ($day = 1; $day <= $days; $day++) {
            $capacities[$day] = array_slice(['Morning', 'Afternoon', 'Evening'], 0, $slotsPerDay);
        }

        app(ItineraryScheduleBuilder::class)->build(
            $itinerary, $sequence, $preference, $capacities,
            ['lat' => self::ORIGIN_LAT, 'lng' => self::ORIGIN_LNG, 'label' => 'Davao City centre'], null
        );

        return $itinerary->fresh('items');
    }

    private function visit(Itinerary $itinerary, Destination $destination)
    {
        return $itinerary->items->where('kind', 'activity')->firstWhere('destination_id', $destination->id);
    }

    // ---- opening hours

    public function test_a_visit_is_cut_short_to_end_at_closing_time(): void
    {
        $park = $this->destination('Morning Park', 10, hours: '8:00 AM–11:00 AM');

        $visit = $this->visit($this->schedule([$park], days: 2), $park);

        $this->assertNotNull($visit, 'There is still an hour and a half before it closes, so the stop stays.');
        $this->assertSame('09:15:00', $visit->starts_at);
        $this->assertSame('11:00:00', $visit->ends_at, 'It ends when the place closes, not 150 minutes later.');
    }

    public function test_a_visit_waits_for_opening_time(): void
    {
        $park = $this->destination('Late Opener', 10, hours: '10:00 AM–5:00 PM');

        $visit = $this->visit($this->schedule([$park], days: 2), $park);

        $this->assertSame('10:00:00', $visit->starts_at, 'Arriving at 9:15 for a 10:00 opening means the visit starts at 10:00.');
        $this->assertSame('12:30:00', $visit->ends_at);
    }

    public function test_a_place_that_closes_before_anyone_could_get_there_is_skipped_without_blocking_the_rest(): void
    {
        $closed = $this->destination('Sunrise Point', 10, hours: '5:00 AM–8:00 AM');
        $open = $this->destination('Open Park', 20);

        $itinerary = $this->schedule([$closed, $open]);

        $this->assertNull($this->visit($itinerary, $closed), 'It would be shut on arrival, so it is not scheduled.');
        $this->assertNotNull($this->visit($itinerary, $open), 'The stop behind it must still be scheduled.');
    }

    public function test_no_scheduled_visit_ever_runs_past_a_readable_closing_time(): void
    {
        $stops = [
            $this->destination('Farm', 12, hours: '8:00 AM–5:00 PM'),
            $this->destination('Garden', 30, hours: '9:00 AM–4:00 PM'),
            $this->destination('Museum', 8, hours: '9:00 AM–12:00 PM; 1:00 PM–5:00 PM'),
        ];

        $itinerary = $this->schedule($stops, days: 2);

        foreach ($stops as $stop) {
            $visit = $this->visit($itinerary, $stop);
            if (! $visit) {
                continue;
            }
            [$open, $close] = [
                ['Farm' => '08:00:00', 'Garden' => '09:00:00', 'Museum' => '09:00:00'][$stop->name],
                ['Farm' => '17:00:00', 'Garden' => '16:00:00', 'Museum' => '17:00:00'][$stop->name],
            ];
            $this->assertGreaterThanOrEqual($open, $visit->starts_at, $stop->name.' starts after it opens');
            $this->assertLessThanOrEqual($close, $visit->ends_at, $stop->name.' ends before it closes');
        }
    }

    public function test_hours_that_cannot_be_read_put_no_limit_on_the_visit(): void
    {
        $venue = $this->destination('Event Hall', 10, hours: 'Event-dependent; assumed window 9:00 AM–6:00 PM');

        $visit = $this->visit($this->schedule([$venue], days: 2), $venue);

        $this->assertSame('09:15:00', $visit->starts_at);
        $this->assertSame('11:45:00', $visit->ends_at, 'The full 150 minutes, exactly as before.');
    }

    public function test_a_place_that_opens_in_the_afternoon_is_visited_after_the_morning_stop(): void
    {
        $afternoon = $this->destination('Afternoon Park', 3, hours: '1:00 PM–10:00 PM');
        $morning = $this->destination('Morning Park', 10);

        // The afternoon place is first in the route, but it is not open yet.
        $itinerary = $this->schedule([$afternoon, $morning], days: 2);

        $first = $itinerary->items->where('kind', 'activity')->where('day_number', 1)->sortBy('sort_order')->values();

        $this->assertSame($morning->id, $first[0]->destination_id, 'The morning stop goes first.');
        $this->assertSame($afternoon->id, $first[1]->destination_id, 'The afternoon place follows once it is open.');
        $this->assertGreaterThanOrEqual('13:00:00', $first[1]->starts_at);
    }

    public function test_when_the_only_stop_opens_later_the_day_does_not_begin_with_a_long_wait(): void
    {
        $afternoon = $this->destination('Afternoon Park', 3, hours: '1:00 PM–10:00 PM');

        $itinerary = $this->schedule([$afternoon], days: 2);

        $visit = $this->visit($itinerary, $afternoon);
        $travel = $itinerary->items->where('kind', 'travel')->where('day_number', 1)->sortBy('sort_order')->first();

        $this->assertNotNull($visit);
        $this->assertGreaterThanOrEqual('13:00:00', $visit->starts_at);
        $this->assertGreaterThanOrEqual('12:00:00', $travel->starts_at, 'Set off around midday, not at 8:30 to stand outside until 1 pm.');
    }

    // ---- driving

    public function test_a_day_does_not_take_a_second_stop_that_would_mean_too_much_driving(): void
    {
        $near = $this->destination('Near Park', 10);
        $far = $this->destination('Far Park', 28.75, 40.9);   // 45 km beyond the first stop and 50 km from the centre

        $itinerary = $this->schedule([$near, $far], distancePref: 'moderate');

        $this->assertNotNull($this->visit($itinerary, $near));
        $this->assertNull($this->visit($itinerary, $far), 'Out, further out, and all the way back is more than a moderate day of driving.');
    }

    public function test_a_traveller_who_is_willing_to_go_far_gets_the_longer_day(): void
    {
        $near = $this->destination('Near Park', 10);
        $far = $this->destination('Far Park', 28.75, 40.9);

        $itinerary = $this->schedule([$near, $far], distancePref: 'far');

        $this->assertNotNull($this->visit($itinerary, $far), 'The same two stops fit when the traveller said far.');
    }

    public function test_the_first_stop_of_a_day_is_never_refused_for_distance(): void
    {
        $far = $this->destination('Remote Falls', 45, 25);   // a long way out, but doable in a day

        $this->assertNotNull($this->visit($this->schedule([$far], distancePref: 'near'), $far));
    }

    // ---- souvenirs

    public function test_a_souvenir_stop_is_added_when_a_shop_is_close_to_the_last_stop(): void
    {
        $park = $this->destination('Near Park', 10);
        $this->shop('Local Crafts', 14);   // 4 km from the park

        $itinerary = $this->schedule([$park]);

        $this->assertTrue($itinerary->items->contains(fn ($item) => $item->title === 'Souvenir shopping'));
    }

    public function test_no_souvenir_stop_is_added_when_the_only_shops_are_far_from_the_last_stop(): void
    {
        $park = $this->destination('Near Park', 10);
        $this->shop('Far Away Shop', 70);   // 60 km from the park

        $itinerary = $this->schedule([$park]);

        $this->assertFalse($itinerary->items->contains(fn ($item) => $item->title === 'Souvenir shopping'),
            'A shop an hour or two away is a second excursion, not a souvenir stop.');
        $this->assertSame('departure', $itinerary->items->sortBy('sort_order')->last()->kind, 'The day still ends properly.');
    }

    public function test_no_souvenir_stop_is_added_when_the_visit_would_run_into_the_night(): void
    {
        $park = $this->destination('Near Park', 10);
        $this->shop('Local Crafts', 14);

        // A 3 pm arrival: the park ends at 5:45 pm, and shopping after that would end near 7:30 pm.
        $itinerary = $this->schedule([$park], arrival: '15:00');

        $this->assertNotNull($this->visit($itinerary, $park));
        $this->assertFalse($itinerary->items->contains(fn ($item) => $item->title === 'Souvenir shopping'));
    }

    public function test_the_whole_day_never_runs_to_the_small_hours_with_these_limits(): void
    {
        $stops = [$this->destination('A', 10), $this->destination('B', 24.8, 24.7), $this->destination('C', 60, 40)];
        $this->shop('Local Crafts', 62);

        $itinerary = $this->schedule($stops, days: 2);

        foreach ($itinerary->items as $item) {
            $this->assertLessThanOrEqual('22:30:00', $item->starts_at, $item->title.' starts at a sensible hour');
        }
    }

    public function test_dinner_waits_for_the_traveller_when_the_drive_back_runs_long(): void
    {
        $stay = \App\Models\Accommodation::create([
            'slug' => 'far-resort', 'name' => 'Far Resort', 'location' => 'Davao City', 'region_id' => $this->region(),
            'type' => 'Resort', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
            'latitude' => $this->at(80, 0)[0], 'longitude' => $this->at(80, 0)[1],
        ]);
        $park = $this->destination('Near Park', 10);
        $second = $this->destination('Second Park', 28);

        $preference = TouristPreference::create([
            'travel_days' => 2, 'travel_type' => 'Family', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any',
            'distance_pref' => 'far', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Manila',
        ]);
        $itinerary = Itinerary::create(['preference_id' => $preference->id, 'total_days' => 2, 'generated_at' => now()]);

        app(ItineraryScheduleBuilder::class)->build(
            $itinerary,
            [
                ['row' => ['destination' => $park->load('region'), 'drs' => 4.0], 'distance_km' => 1.0],
                ['row' => ['destination' => $second->load('region'), 'drs' => 4.0], 'distance_km' => 1.0],
            ],
            $preference,
            [1 => ['Morning', 'Afternoon'], 2 => ['Morning']],
            ['lat' => self::ORIGIN_LAT, 'lng' => self::ORIGIN_LNG, 'label' => 'Davao City centre'],
            ['listing' => $stay, 'rule' => null],
        );

        $items = $itinerary->fresh('items')->items->where('day_number', 1)->sortBy('sort_order')->values();
        $travelBack = $items->where('kind', 'travel')->last();
        $dinner = $items->firstWhere('title', 'Dinner — Far Resort');
        $overnight = $items->firstWhere('kind', 'overnight');

        $arrives = \Illuminate\Support\Carbon::parse($travelBack->starts_at)->addMinutes($travelBack->travel_max_minutes)->format('H:i:s');

        $this->assertGreaterThan('18:30:00', $arrives, 'The scenario needs a drive that gets in after the usual dinner hour.');
        $this->assertGreaterThanOrEqual($arrives, $dinner->starts_at, 'Dinner is not served before the traveller arrives.');
        $this->assertGreaterThan($dinner->starts_at, $overnight->starts_at);
    }
}
