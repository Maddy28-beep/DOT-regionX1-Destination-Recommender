<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * On a day that ends at the accommodation, the time between getting back and the fixed dinner hour is shown as
 * neutral free time ("Free time until dinner"), instead of an unexplained jump from the return journey to dinner.
 *
 * The row is only added when the gap, measured from the quarter-hour-rounded arrival at the accommodation, is at
 * least 30 minutes. It is placed after the return journey and before dinner, carries no listing, and claims
 * nothing about a room, a meal or any service. Dinner stays where it was, and the early-arrival free time is
 * untouched.
 *
 * Every time below is deterministic: the clock is frozen, the trip date is fixed, and each test first checks that
 * the fixture produces the gap it is about, so a change elsewhere cannot silently turn a boundary test into
 * something else.
 */
class FreeTimeBeforeDinnerTest extends TestCase
{
    use RefreshDatabase;

    private const TITLE = 'Free time until dinner';

    private const NOTE = 'Nothing is arranged for this time.';

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

    // ---------------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------------

    private function region(string $name): Region
    {
        return Region::firstOrCreate(['name' => $name]);
    }

    private function destination(string $name, string $type, float $lat, float $lng, float $rating): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => $this->region('Davao City')->id, 'type' => $type, 'is_accredited' => true,
            'rating' => $rating, 'review_count' => 10, 'price_tier' => 'Mid-range',
            'latitude' => $lat, 'longitude' => $lng,
        ]);
    }

    /** Five stops of differing rating (a fixed ranking) and one inn. */
    private function fixture(bool $withInn = true): void
    {
        foreach ([['Zoo', 'Wildlife', 7.12, 125.64, 5.0], ['Museum', 'Cultural Heritage', 7.07, 125.61, 4.9], ['Beach One', 'Beach & Leisure', 7.15, 125.71, 4.8], ['Eden Park', 'Nature & Adventure', 7.03, 125.37, 4.7], ['Spare Garden', 'Wildlife', 7.09, 125.63, 3.0]] as [$name, $type, $lat, $lng, $rating]) {
            $this->destination($name, $type, $lat, $lng, $rating);
        }

        if ($withInn) {
            Accommodation::create([
                'slug' => 'city-inn', 'name' => 'City Inn', 'location' => 'Davao', 'region_id' => $this->region('Davao City')->id,
                'type' => 'Hotel', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
                'latitude' => 7.07, 'longitude' => 125.61,
            ]);
        }
    }

    private function preference(string $arrival, int $days = 2, string $distance = 'near'): TouristPreference
    {
        $preference = TouristPreference::create([
            'travel_days' => $days, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any',
            'distance_pref' => $distance, 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'place_of_origin' => 'Cebu City', 'start_date' => '2026-10-28', 'arrival_time' => $arrival,
        ]);
        PreferenceActivity::create(['preference_id' => $preference->id, 'activity' => 'Wildlife']);

        return $preference;
    }

    private function generate(string $arrival, int $days = 2, string $distance = 'near'): Itinerary
    {
        $preference = $this->preference($arrival, $days, $distance);

        return app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'), null, null, []);
    }

    /** @return list<ItineraryItem> */
    private function rows(Itinerary $itinerary, int $day): array
    {
        return $itinerary->items()->where('day_number', $day)->orderBy('sort_order')->get()->all();
    }

    private function minutes(?string $time): ?int
    {
        return $time === null ? null : (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    private function hhmm(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }

    /**
     * What the free time has to fill on an overnight day: when the traveller is back (the return journey's start plus
     * its slow estimate, rounded up to the quarter hour, as the scheduler does) and when dinner starts.
     *
     * @return array{returned: int, dinner: int, gap: int, travelIndex: int, dinnerIndex: int, rows: list<ItineraryItem>}|null null when the day has no dinner
     */
    private function returnFacts(Itinerary $itinerary, int $day): ?array
    {
        $rows = $this->rows($itinerary, $day);
        $dinnerIndex = null;
        foreach ($rows as $i => $row) {
            if ($row->kind === 'meal' && str_starts_with((string) $row->title, 'Dinner')) {
                $dinnerIndex = $i;
                break;
            }
        }
        if ($dinnerIndex === null) {
            return null;
        }

        // The return journey is the last travel row before dinner; a free row, if any, sits between the two.
        $travelIndex = $dinnerIndex - 1;
        if ($rows[$travelIndex]->kind === 'free') {
            $travelIndex--;
        }
        $travel = $rows[$travelIndex];
        $this->assertSame('travel', $travel->kind);

        $back = $this->minutes($travel->starts_at) + (int) $travel->travel_max_minutes;
        $returned = $back % 15 === 0 ? $back : $back + (15 - $back % 15);
        $dinner = $this->minutes($rows[$dinnerIndex]->starts_at);

        return ['returned' => $returned, 'dinner' => $dinner, 'gap' => $dinner - $returned, 'travelIndex' => $travelIndex, 'dinnerIndex' => $dinnerIndex, 'rows' => $rows];
    }

    // ---------------------------------------------------------------------------------------------
    // The threshold: 0, 15, 30 and 45 minutes
    // ---------------------------------------------------------------------------------------------

    /** arrival of the trip (chosen so Day 1 ends back at the inn at the gap's time), gap in minutes, whether free time is expected */
    public static function gaps(): array
    {
        return [
            'back at 18:30, no gap' => ['11:00', 0, false],
            '15 minutes is only slack' => ['10:45', 15, false],
            'exactly 30 minutes' => ['10:30', 30, true],
            '45 minutes' => ['10:15', 45, true],
        ];
    }

    #[DataProvider('gaps')]
    public function test_the_free_time_row_appears_from_thirty_minutes(string $arrival, int $gap, bool $expected): void
    {
        $this->fixture();

        $facts = $this->returnFacts($this->generate($arrival), 1);

        $this->assertNotNull($facts, 'Day 1 ends at the inn with dinner');
        $this->assertSame($gap, $facts['gap'], 'the fixture produces the gap this case is about');

        $free = array_values(array_filter($facts['rows'], fn ($r) => $r->kind === 'free' && $r->title === self::TITLE));

        if (! $expected) {
            $this->assertSame([], $free, "no free time for a {$gap}-minute gap");
            // Nothing sits between the return journey and dinner.
            $this->assertSame($facts['travelIndex'] + 1, $facts['dinnerIndex']);

            return;
        }

        $this->assertCount(1, $free);
        $row = $free[0];

        $this->assertSame(self::NOTE, $row->note);
        $this->assertSame([$facts['returned'], $facts['dinner']], [$this->minutes($row->starts_at), $this->minutes($row->ends_at)], 'from the rounded return to the start of dinner');
        $this->assertSame($gap, $this->minutes($row->ends_at) - $this->minutes($row->starts_at));
        $this->assertSame('18:30', $this->hhmm($facts['rows'][$facts['dinnerIndex']]->starts_at), 'dinner stays at its fixed hour');

        // Placed after the return journey and before dinner, with nothing in between.
        $this->assertSame($facts['travelIndex'] + 1, array_search($row, $facts['rows'], false));
        $this->assertSame($facts['dinnerIndex'] - 1, array_search($row, $facts['rows'], false));
    }

    public function test_the_free_time_is_neutral(): void
    {
        $this->fixture();

        $itinerary = $this->generate('10:30');
        $row = $itinerary->items()->where('title', self::TITLE)->firstOrFail();

        $this->assertSame('free', $row->kind);
        foreach (['destination_id', 'accommodation_id', 'restaurant_id', 'souvenir_center_id'] as $column) {
            $this->assertNull($row->{$column}, "no listing is attached ({$column})");
        }
        $this->assertNull($row->distance_km);
        $this->assertNull($row->rule_basis, 'no association rule is claimed');
        // The shared description of a free row is unchanged, and still true here.
        $this->assertSame('Location of your choice', $row->travelSummary());
    }

    // ---------------------------------------------------------------------------------------------
    // Returning late, or not returning
    // ---------------------------------------------------------------------------------------------

    public function test_a_return_after_the_usual_dinner_hour_moves_dinner_and_adds_no_free_time(): void
    {
        $this->fixture();

        $itinerary = $this->generate('17:45', 2, 'far');
        $facts = $this->returnFacts($itinerary, 1);

        $this->assertNotNull($facts);
        $this->assertGreaterThan(18 * 60 + 30, $facts['dinner'], 'dinner moved to the return time (existing behaviour)');
        $this->assertSame($facts['returned'], $facts['dinner']);
        $this->assertSame($facts['travelIndex'] + 1, $facts['dinnerIndex'], 'dinner follows the return journey directly');
        $this->assertSame(0, $itinerary->items()->where('title', self::TITLE)->count());
    }

    public function test_a_late_check_in_has_no_dinner_and_no_free_time(): void
    {
        $this->fixture();

        $rows = $this->rows($this->generate('21:15', 2, 'far'), 1);
        $kinds = array_map(fn ($r) => $r->kind, $rows);

        $this->assertNotContains('meal', $kinds, 'no dinner for a return after 21:30');
        $this->assertNotContains('free', array_slice($kinds, 1), 'and no free time before it');
        $this->assertSame('overnight', end($rows)->kind);
    }

    public function test_the_final_day_is_unaffected(): void
    {
        $this->fixture();

        $itinerary = $this->generate('09:00', 3, 'moderate');
        $last = array_map(fn ($r) => $r->kind, $this->rows($itinerary, 3));

        $this->assertNotContains('free', $last);
        $this->assertSame('departure', end($last));
        $this->assertNotContains('overnight', $last);
        $this->assertSame(0, $itinerary->items()->where('day_number', 3)->where('title', self::TITLE)->count());
    }

    public function test_a_trip_without_an_accommodation_gets_no_free_time(): void
    {
        $this->fixture(withInn: false);

        $itinerary = $this->generate('09:00', 3, 'moderate');

        $this->assertSame(0, $itinerary->items()->where('kind', 'free')->count());
        $this->assertSame(0, $itinerary->items()->where('kind', 'overnight')->count(), 'there is no night to describe without an accommodation');
    }

    // ---------------------------------------------------------------------------------------------
    // It cannot collide with anything, and it changes nothing else
    // ---------------------------------------------------------------------------------------------

    public function test_the_free_time_never_overlaps_the_journey_or_dinner_in_any_plan(): void
    {
        $this->fixture();

        $seen = 0;
        foreach (['08:00', '09:00', '10:15', '10:30', '12:00', '13:00', '15:00'] as $arrival) {
            foreach ([2, 3] as $days) {
                foreach (['near', 'moderate', 'far'] as $distance) {
                    $itinerary = $this->generate($arrival, $days, $distance);

                    foreach (range(1, $days - 1) as $day) {
                        $facts = $this->returnFacts($itinerary, $day);
                        if ($facts === null) {
                            continue;
                        }

                        $free = $facts['rows'][$facts['dinnerIndex'] - 1];
                        if ($free->kind !== 'free') {
                            continue;
                        }

                        $seen++;
                        $label = "{$arrival}, {$days} days, {$distance}, day {$day}";
                        $this->assertGreaterThanOrEqual($facts['returned'], $this->minutes($free->starts_at), "starts after the journey ends ({$label})");
                        $this->assertSame($facts['dinner'], $this->minutes($free->ends_at), "ends exactly when dinner starts ({$label})");
                        $this->assertGreaterThanOrEqual(30, $facts['gap'], "only for gaps of 30 minutes or more ({$label})");

                        // Chronological: no row of the day starts before the row before it.
                        $previous = null;
                        foreach ($facts['rows'] as $row) {
                            if ($row->starts_at === null) {
                                continue;
                            }
                            $this->assertGreaterThanOrEqual($previous ?? '00:00:00', $row->starts_at, "rows stay in time order ({$label})");
                            $previous = $row->starts_at;
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(10, $seen, 'the sweep actually exercised the new row');
    }

    public function test_the_free_time_changes_neither_the_stops_nor_the_route(): void
    {
        $this->fixture();

        $itinerary = $this->generate('10:30');
        $stopsBefore = $itinerary->items()->where('kind', 'activity')->pluck('destination_id')->all();
        $routeBefore = $itinerary->fresh('items')->routeStops();

        $this->assertSame(1, $itinerary->items()->where('kind', 'free')->count(), 'the plan has the new row');

        $itinerary->items()->where('kind', 'free')->delete();

        $this->assertSame($stopsBefore, $itinerary->items()->where('kind', 'activity')->pluck('destination_id')->all());
        $this->assertSame($routeBefore, $itinerary->fresh('items')->routeStops(), 'the map route is the same with or without the row');
    }

    public function test_the_early_arrival_free_time_is_untouched_and_the_two_are_distinct(): void
    {
        $this->fixture();

        $rows = $this->rows($this->generate('04:00'), 1);

        $this->assertSame(['baseline', 'free', 'travel'], [$rows[0]->kind, $rows[1]->kind, $rows[2]->kind]);
        $this->assertSame('Free time until 8:00 AM', $rows[1]->title);
        $this->assertSame(['04:00', '08:00'], [$this->hhmm($rows[1]->starts_at), $this->hhmm($rows[1]->ends_at)]);
        $this->assertSame('You might rest or eat; nothing is arranged.', $rows[1]->note);

        // The same day can also end with the evening one: they never share a title.
        $titles = array_map(fn ($r) => $r->title, array_filter($rows, fn ($r) => $r->kind === 'free'));
        $this->assertSame(['Free time until 8:00 AM', self::TITLE], array_values($titles));
    }

    public function test_the_page_shows_the_free_time(): void
    {
        $this->fixture();

        $this->post('/plan', [
            'travel_days' => 2, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'near',
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Cebu City', 'start_date' => '2026-10-28',
            'arrival_time' => '10:30', 'activities' => ['Wildlife'], 'amenities' => ['Parking Area'],
        ])->assertRedirect(route('plan.itinerary'));

        $this->assertSame(1, Itinerary::query()->latest('id')->firstOrFail()->items()->where('title', self::TITLE)->count(), 'the trip has the row');

        $this->get(route('plan.itinerary'))->assertOk()->assertSee(self::TITLE)->assertSee('Nothing planned &mdash; rest at your', false);
    }
}
