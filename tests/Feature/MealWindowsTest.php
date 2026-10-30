<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryScheduleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Meals sit in fixed windows (breakfast 07:00-08:30, lunch 11:30-13:00 planned for 12:00, dinner 18:00-19:30, as START times) and are
 * always somewhere to eat: the destination's own restaurant when its amenities include one, an accredited restaurant
 * within 10 km (same region, open then), or "(your choice)". A sightseeing place is never the meal.
 */
class MealWindowsTest extends TestCase
{
    use RefreshDatabase;

    /** Ma-a, Talomo, Davao City. */
    private const MAA = ['lat' => 7.0489, 'lng' => 125.5736, 'label' => 'Ma-a, Talomo, Davao City'];

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

    private function region(string $name): Region
    {
        return Region::firstOrCreate(['name' => $name]);
    }

    private function place(string $name, string $region, float $lat, float $lng, string $type = 'Nature & Adventure'): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => $region, 'region_id' => $this->region($region)->id,
            'type' => $type, 'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Mid-range',
            'latitude' => $lat, 'longitude' => $lng,
        ]);
    }

    private function restaurant(string $name, string $region, float $lat, float $lng, ?string $hours = '10:00 AM–9:00 PM'): Restaurant
    {
        return Restaurant::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => $region, 'region_id' => $this->region($region)->id,
            'cuisine_type' => 'Cafe', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'latitude' => $lat, 'longitude' => $lng,
            'opening_hours' => $hours,
        ]);
    }

    /** @param  array<int, Destination>  $stops */
    private function plan(string $arrival, array $stops, array $capacities): Itinerary
    {
        $preference = TouristPreference::create([
            'travel_days' => count($capacities), 'travel_type' => 'Family', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any',
            'distance_pref' => 'far', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'place_of_origin' => 'Davao City', 'start_date' => '2026-10-28', 'arrival_time' => $arrival,
        ]);
        $itinerary = Itinerary::create(['preference_id' => $preference->id, 'total_days' => count($capacities), 'generated_at' => now()]);

        app(ItineraryScheduleBuilder::class)->build(
            $itinerary,
            array_map(fn (Destination $d) => ['row' => ['destination' => $d->load('region', 'tags'), 'drs' => 4.0], 'distance_km' => 1.0], $stops),
            $preference,
            $capacities,
            self::MAA,
            null,
        );

        return $itinerary;
    }

    /** @return list<array{day: int, kind: string, title: string, start: ?string, end: ?string, restaurant: ?int, destination: ?int}> */
    private function rows(Itinerary $itinerary, int $day = 1): array
    {
        return $itinerary->items()->where('day_number', $day)->orderBy('sort_order')->get()->map(fn ($r) => [
            'day' => $r->day_number, 'kind' => $r->kind, 'title' => (string) $r->title,
            'start' => $r->starts_at ? substr((string) $r->starts_at, 0, 5) : null, 'end' => $r->ends_at ? substr((string) $r->ends_at, 0, 5) : null,
            'restaurant' => $r->restaurant_id, 'destination' => $r->destination_id,
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function meals(array $rows): array
    {
        return array_values(array_filter($rows, fn ($r) => $r['kind'] === 'meal'));
    }

    private function first(array $rows, string $kind): array
    {
        foreach ($rows as $row) {
            if ($row['kind'] === $kind) {
                return $row;
            }
        }

        $this->fail("no {$kind} row");
    }

    public function test_a_noon_start_eats_first_near_the_start_and_never_makes_a_waterfall_the_lunch(): void
    {
        $falls = $this->place('Hagimit Falls', 'Island Garden City of Samal', 7.0666, 125.7230);
        $deli = $this->restaurant('Matina Swiss Deli', 'Davao City', 7.0600, 125.5800);

        $rows = $this->rows($this->plan('12:00', [$falls], [1 => ['Afternoon']]));
        $meals = $this->meals($rows);

        $this->assertCount(1, $meals);
        $this->assertSame(['Lunch — Matina Swiss Deli', '12:30', '13:30', $deli->id, null], [$meals[0]['title'], $meals[0]['start'], $meals[0]['end'], $meals[0]['restaurant'], $meals[0]['destination']]);
        $this->assertSame('13:30', $this->first($rows, 'travel')['start'], 'the journey starts once lunch is over');
        $this->assertSame($falls->id, $this->first($rows, 'activity')['destination']);
        $this->assertLessThanOrEqual('18:00', $this->first($rows, 'activity')['end']);
    }

    public function test_a_place_with_no_restaurant_nearby_gets_a_choice_slot_never_the_place_itself(): void
    {
        $falls = $this->place('Hagimit Falls', 'Island Garden City of Samal', 7.0666, 125.7230);
        // A restaurant on the mainland: close on the map, but in another region and so not on the island.
        $this->restaurant('Marina Tuna', 'Davao City', 7.1129, 125.6514);

        // Day 1 is not the last day, so the visit runs its full length (09:45-12:15) and lunch follows it.
        $rows = $this->rows($this->plan('07:00', [$falls], [1 => ['Morning', 'Afternoon'], 2 => ['Morning']]));
        $lunch = collect($this->meals($rows))->first(fn ($m) => str_starts_with($m['title'], 'Lunch'));

        $this->assertNotNull($lunch);
        $this->assertSame('Lunch break (your choice)', $lunch['title']);
        $this->assertNull($lunch['destination']);
        $this->assertNull($lunch['restaurant']);
        $this->assertGreaterThanOrEqual('12:00', $lunch['start']);
        $this->assertLessThanOrEqual('13:00', $lunch['start']);
    }

    public function test_an_early_start_has_breakfast_first_near_the_start(): void
    {
        $falls = $this->place('Hagimit Falls', 'Island Garden City of Samal', 7.0666, 125.7230);
        // Opens at 10, so it cannot be the 07:30 breakfast.
        $this->restaurant('Matina Swiss Deli', 'Davao City', 7.0600, 125.5800);

        $rows = $this->rows($this->plan('07:00', [$falls], [1 => ['Morning', 'Afternoon']]));
        $breakfast = $this->meals($rows)[0];

        $this->assertSame(['Breakfast break (your choice)', '07:30', '08:15'], [$breakfast['title'], $breakfast['start'], $breakfast['end']]);
        $this->assertSame('08:15', $this->first($rows, 'travel')['start']);
    }

    public function test_a_late_start_skips_breakfast_and_lunch(): void
    {
        $falls = $this->place('Hagimit Falls', 'Island Garden City of Samal', 7.0666, 125.7230);
        $this->restaurant('Matina Swiss Deli', 'Davao City', 7.0600, 125.5800);

        $rows = $this->rows($this->plan('15:00', [$falls], [1 => ['Afternoon']]));

        $this->assertSame([], $this->meals($rows), 'a 3 PM start has no breakfast or lunch to plan');
    }

    public function test_a_noon_start_has_no_breakfast_and_lunch_is_not_repeated_on_the_way_back(): void
    {
        $falls = $this->place('Hagimit Falls', 'Island Garden City of Samal', 7.0666, 125.7230);
        $this->restaurant('Matina Swiss Deli', 'Davao City', 7.0600, 125.5800);

        $titles = array_column($this->meals($this->rows($this->plan('12:00', [$falls], [1 => ['Afternoon']]))), 'title');

        $this->assertCount(1, $titles);
        $this->assertStringStartsWith('Lunch', $titles[0]);
    }

    public function test_a_long_transfer_across_lunch_is_split_and_lunch_is_on_the_way(): void
    {
        $far = $this->place('Aliwagwag Falls', 'Davao Oriental', 7.7430, 126.2989);

        $rows = $this->rows($this->plan('09:00', [$far], [1 => ['Afternoon']]));
        $travel = array_values(array_filter($rows, fn ($r) => $r['kind'] === 'travel'));
        $lunch = $this->meals($rows)[0];

        $this->assertSame('Travel toward Aliwagwag Falls', $travel[0]['title']);
        $this->assertSame('09:30', $travel[0]['start']);
        $this->assertSame(['12:00', '13:00'], [$lunch['start'], $lunch['end']], 'lunch starts when the window opens');
        $this->assertStringContainsString('on the way', $lunch['title']);
        $this->assertSame('13:00', $travel[1]['start'], 'the second part of the journey starts when lunch ends');
        $this->assertNull($lunch['destination']);

        $visit = $this->first($rows, 'activity');
        $this->assertSame('16:30', $visit['start']);
        $this->assertSame('18:00', $visit['end'], 'a short visit that still fits beats losing the stop');
    }

    public function test_a_destination_with_its_own_restaurant_is_the_meal_stop(): void
    {
        $eden = $this->place('Eden Nature Park', 'Davao City', 7.0206, 125.4103);
        $eden->tags()->create(['kind' => 'amenity', 'value' => 'Restaurant']);
        // Another restaurant is nearby, but the park's own is the one named.
        $this->restaurant('Kapehan sa Tambacan', 'Davao City', 7.0250, 125.4150);

        $rows = $this->rows($this->plan('09:00', [$eden], [1 => ['Morning', 'Afternoon']]));
        $lunch = $this->meals($rows)[0];

        $this->assertSame('Lunch — Eden Nature Park Restaurant', $lunch['title']);
        $this->assertSame($eden->id, $lunch['destination']);
        $this->assertNull($lunch['restaurant']);
        $this->assertGreaterThanOrEqual('12:00', $lunch['start']);
        $this->assertLessThanOrEqual('13:00', $lunch['start']);
    }

    public function test_a_nearby_restaurant_is_used_within_ten_km_and_not_beyond(): void
    {
        $park = $this->place('Hilltop Park', 'Davao City', 7.0206, 125.4103);
        $near = $this->restaurant('Ridge Cafe', 'Davao City', 7.0206 + 0.07, 125.4103);   // about 7.8 km
        $this->restaurant('Far Grill', 'Davao City', 7.0206 + 0.12, 125.4103);              // about 13 km

        $lunch = $this->meals($this->rows($this->plan('09:00', [$park], [1 => ['Morning', 'Afternoon']])))[0];
        $this->assertSame('Lunch — Ridge Cafe', $lunch['title']);
        $this->assertSame($near->id, $lunch['restaurant']);

        $near->delete();

        $lunch = $this->meals($this->rows($this->plan('09:00', [$park], [1 => ['Morning', 'Afternoon']])))[0];
        $this->assertSame('Lunch break (your choice)', $lunch['title'], 'nothing within 10 km');
    }

    public function test_a_restaurant_that_is_closed_at_that_hour_is_not_offered(): void
    {
        $park = $this->place('Hilltop Park', 'Davao City', 7.0206, 125.4103);
        $this->restaurant('Evening Grill', 'Davao City', 7.0250, 125.4150, '5:00 PM–11:00 PM');

        $lunch = $this->meals($this->rows($this->plan('09:00', [$park], [1 => ['Morning', 'Afternoon']])))[0];

        $this->assertSame('Lunch break (your choice)', $lunch['title']);
    }

    public function test_every_meal_row_is_a_food_stop_and_inside_its_window(): void
    {
        $falls = $this->place('Hagimit Falls', 'Island Garden City of Samal', 7.0666, 125.7230);
        $eden = $this->place('Eden Nature Park', 'Davao City', 7.0206, 125.4103);
        $far = $this->place('Aliwagwag Falls', 'Davao Oriental', 7.7430, 126.2989);
        $this->restaurant('Matina Swiss Deli', 'Davao City', 7.0600, 125.5800);

        foreach (['04:00', '07:00', '08:00', '09:30', '11:00', '12:00', '12:45', '13:30', '15:00'] as $arrival) {
            $itinerary = $this->plan($arrival, [$falls, $eden, $far], [1 => ['Morning', 'Afternoon'], 2 => ['Morning', 'Afternoon']]);

            foreach ([1, 2] as $day) {
                foreach ($this->meals($this->rows($itinerary, $day)) as $meal) {
                    $window = match (true) {
                        str_starts_with($meal['title'], 'Breakfast') => ['07:00', '08:30'],
                        str_starts_with($meal['title'], 'Lunch') => ['11:30', '13:00'],
                        default => ['18:00', '19:30'],
                    };

                    $this->assertGreaterThanOrEqual($window[0], $meal['start'], "arrival {$arrival}, day {$day}: {$meal['title']}");
                    $this->assertLessThanOrEqual($window[1], $meal['start'], "arrival {$arrival}, day {$day}: {$meal['title']}");

                    // A destination is attached only as its own restaurant (the title says so).
                    if ($meal['destination'] !== null) {
                        $this->assertStringEndsWith('Restaurant', $meal['title'], "arrival {$arrival}, day {$day}");
                    }
                }
            }
        }
    }

    public function test_a_day_without_a_stay_has_a_choice_breakfast_not_a_made_up_accommodation(): void
    {
        $falls = $this->place('Hagimit Falls', 'Island Garden City of Samal', 7.0666, 125.7230);
        $eden = $this->place('Eden Nature Park', 'Davao City', 7.0206, 125.4103);

        $day2 = $this->rows($this->plan('09:00', [$falls, $eden], [1 => ['Morning'], 2 => ['Morning']]), 2);

        $this->assertSame(['Breakfast (your choice)', '07:00'], [$day2[0]['title'], $day2[0]['start']]);
    }

    // ---- lunch may start from 11:30 (planned for 12:00) ----------------------------------------------

    /** A park a few minutes from the starting point, so the arrival time at it is easy to steer. */
    private function nearbyPark(): Destination
    {
        return $this->place('Nearby Park', 'Davao City', 7.0489 + 0.002, 125.5736);
    }

    public function test_arriving_just_before_lunch_eats_first_and_gives_the_place_its_full_visit(): void
    {
        $park = $this->nearbyPark();

        // 10:15 arrival: ready 10:45, at the park by 11:00, half an hour before lunch may start.
        $rows = $this->rows($this->plan('10:15', [$park], [1 => ['Morning', 'Afternoon'], 2 => ['Morning']]));
        $lunch = $this->meals($rows)[0];
        $visit = $this->first($rows, 'activity');

        $this->assertSame('11:30', $lunch['start'], 'lunch starts as soon as it may');
        $this->assertSame($visit['start'], $lunch['end'], 'the visit follows lunch directly');
        $this->assertSame(150, (strtotime($visit['end']) - strtotime($visit['start'])) / 60, 'a full visit, not an hour squeezed in before lunch');
    }

    public function test_arriving_earlier_ends_the_visit_when_lunch_is_planned(): void
    {
        $park = $this->nearbyPark();

        // 10:00 arrival: ready 10:30, at the park by 10:45, 45 minutes before lunch may start: a 75-minute visit, then lunch.
        $rows = $this->rows($this->plan('10:00', [$park], [1 => ['Morning', 'Afternoon'], 2 => ['Morning']]));
        $lunch = collect($this->meals($rows))->first(fn ($m) => str_starts_with($m['title'], 'Lunch'));
        $visit = $this->first($rows, 'activity');

        $this->assertSame(['10:45', '12:00'], [$visit['start'], $visit['end']]);
        $this->assertSame('12:00', $lunch['start']);
    }

    public function test_a_visit_that_ends_at_a_quarter_to_twelve_is_followed_by_lunch_at_once(): void
    {
        $park = $this->nearbyPark();

        // 07:15 arrival, ready 07:45 is inside breakfast (07:45-08:30 window), so use a plain 06:00 arrival:
        // departs 08:00, at the park by about 08:15-08:30, visit of 150 minutes ends before 11:30 or just after.
        $rows = $this->rows($this->plan('06:00', [$park], [1 => ['Morning', 'Afternoon'], 2 => ['Morning']]));
        $visit = $this->first($rows, 'activity');
        $lunch = collect($this->meals($rows))->first(fn ($m) => str_starts_with($m['title'], 'Lunch'));

        if ($visit['end'] >= '11:30') {
            $this->assertSame($visit['end'], $lunch['start'], 'lunch starts when the visit ends, not at 12:00');
        } else {
            $this->assertNull($lunch, 'a visit that ends before 11:30 is not followed by lunch yet');
        }
    }

    public function test_no_lunch_starts_before_1130_or_after_1300(): void
    {
        $park = $this->nearbyPark();
        $far = $this->place('Far Falls', 'Davao Oriental', 7.7430, 126.2989);

        foreach (['06:00', '07:30', '08:15', '09:00', '09:45', '10:15', '10:45', '11:15', '11:45', '12:30'] as $arrival) {
            foreach ($this->meals($this->rows($this->plan($arrival, [$park, $far], [1 => ['Morning', 'Afternoon'], 2 => ['Morning', 'Afternoon']]))) as $meal) {
                if (str_starts_with($meal['title'], 'Lunch')) {
                    $this->assertGreaterThanOrEqual('11:30', $meal['start'], "arrival {$arrival}");
                    $this->assertLessThanOrEqual('13:00', $meal['start'], "arrival {$arrival}");
                }
            }
        }
    }

    // ---- dinner on the closing journey ---------------------------------------------------------------

    /** @return array{steps: list<array>, arrival: Carbon} */
    private function dinnerLeg(string $setsOff, float $driveKm): array
    {
        $from = ['lat' => 7.07, 'lng' => 125.61, 'label' => 'Here', 'region' => 'Davao City'];
        $to = ['lat' => 7.07 + $driveKm / 111.2, 'lng' => 125.61, 'label' => 'There', 'region' => 'Davao City'];
        $method = new \ReflectionMethod(ItineraryScheduleBuilder::class, 'planDinnerLeg');

        return $method->invoke(app(ItineraryScheduleBuilder::class), Carbon::parse($setsOff), $from, $to);
    }

    private function kinds(array $plan): array
    {
        return array_map(fn ($s) => $s['type'] === 'meal' ? 'dinner@'.$s['at']->format('H:i').'('.$s['phase'].')' : 'travel@'.$s['start']->format('H:i').'+'.$s['minutes'], $plan['steps']);
    }

    public function test_setting_off_inside_the_dinner_window_on_a_long_journey_eats_first(): void
    {
        // About 4 hours home from 18:30: dinner first, then the drive.
        $plan = $this->dinnerLeg('18:30', 100);

        $this->assertSame('dinner@18:30(start)', $this->kinds($plan)[0]);
        $this->assertStringStartsWith('travel@19:30', $this->kinds($plan)[1]);
    }

    public function test_a_journey_that_spans_the_dinner_window_stops_on_the_way_at_half_past_six(): void
    {
        // Sets off at 16:30 for a 4-hour drive: travel until 18:30, dinner on the way, then the rest.
        $kinds = $this->kinds($this->dinnerLeg('16:30', 100));

        $this->assertCount(3, $kinds);
        $this->assertSame('travel@16:30+120', $kinds[0]);
        $this->assertSame('dinner@18:30(road)', $kinds[1]);
        $this->assertStringStartsWith('travel@19:30', $kinds[2]);
    }

    public function test_setting_off_a_little_before_the_window_waits_and_eats_first(): void
    {
        // 17:30 is 30 minutes before the window opens: eat first at 18:00.
        $this->assertSame('dinner@18:00(start)', $this->kinds($this->dinnerLeg('17:30', 100))[0]);
    }

    public function test_a_short_journey_or_one_that_ends_before_the_window_gets_no_dinner(): void
    {
        $this->assertSame(['travel@15:00+60'], $this->kinds($this->dinnerLeg('15:00', 15)), 'over before dinner time');
        $this->assertCount(1, $this->kinds($this->dinnerLeg('18:30', 8)), 'home within the hour of setting off at dinner time: eat at home');
        $this->assertCount(1, $this->kinds($this->dinnerLeg('19:45', 100)), 'already past the window');
    }

    public function test_a_day_that_ends_with_a_long_drive_home_now_has_a_dinner_row(): void
    {
        $far = $this->place('Aliwagwag Falls', 'Davao Oriental', 7.7430, 126.2989);

        $rows = $this->rows($this->plan('09:00', [$far], [1 => ['Afternoon']]));
        $dinner = collect($this->meals($rows))->first(fn ($m) => str_starts_with($m['title'], 'Dinner'));

        $this->assertNotNull($dinner, 'the visit ends at 18:00 and the drive home takes hours: dinner is planned');
        $this->assertGreaterThanOrEqual('18:00', $dinner['start']);
        $this->assertLessThanOrEqual('19:30', $dinner['start']);
        $this->assertNull($dinner['destination'], 'a place is never the dinner');
        $this->assertStringContainsString('(your choice)', $dinner['title']);
    }
}
