<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Characterization of how the itinerary generator handles the tourist's arrival time.
 *
 * These tests record what the code does, so that changes to arrival scheduling are reviewed as an
 * explicit diff of expectations: a change to behaviour has to edit the specific lines below, in the
 * same commit, and say why. History: they were first written against the original behaviour (PR #124);
 * the arrival-scheduling change then flipped, deliberately, only the expectations for arrivals before
 * 07:30 (the new rule: departure = max(arrival + 30 min, 08:00), with neutral free time before it).
 *
 * Where behaviour is a known defect the test name says KNOWN DEFECT and the comment says what the
 * intended behaviour is. Those expectations are meant to be flipped, deliberately, by the change that
 * fixes them; a flip anywhere else is a regression. (The midnight defect is fixed.)
 *
 * Fixed inputs throughout: the clock is frozen, the trip starts on a fixed date, the starting
 * point is the default (Davao City centre) and every fixture has a single deterministic ranking.
 */
class ArrivalBaselineTest extends TestCase
{
    use RefreshDatabase;

    /** The planner's default starting point (Davao City centre). */
    private const CENTRE_LAT = 7.0731;

    private const CENTRE_LNG = 125.6128;

    protected function setUp(): void
    {
        parent::setUp();

        // The builder's clock is a Carbon on "today"; freezing it keeps every run identical.
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

    private function destination(string $name, string $type, float $lat, float $lng, string $region = 'Davao City', float $rating = 5.0): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => $region,
            'region_id' => $this->region($region)->id, 'type' => $type, 'is_accredited' => true,
            'rating' => $rating, 'review_count' => 10, 'price_tier' => 'Mid-range',
            'latitude' => $lat, 'longitude' => $lng,
        ]);
    }

    private function inn(): Accommodation
    {
        return Accommodation::create([
            'slug' => 'city-inn', 'name' => 'City Inn', 'location' => 'Davao', 'region_id' => $this->region('Davao City')->id,
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
            'latitude' => 7.07, 'longitude' => 125.61,
        ]);
    }

    /** One stop on Samal, 13.9 km from the centre: an estimated 60-90 min leg including the ferry. */
    private function longLegFixture(): void
    {
        $this->destination('Samal Wildlife Spot', 'Wildlife', 7.0731, 125.7386, 'Island Garden City of Samal');
        $this->inn();
    }

    /** One stop on the starting point itself: a 15 min leg (the estimator's minimum, including overhead). */
    private function shortLegFixture(): void
    {
        $this->destination('Centre Wildlife Spot', 'Wildlife', self::CENTRE_LAT, self::CENTRE_LNG);
        $this->inn();
    }

    /** Four stops of differing rating, so the order is fixed without relying on a tie-break. */
    private function multiFixture(bool $withSpare = false): void
    {
        foreach ([['Zoo', 'Wildlife', 7.12, 125.64], ['Museum', 'Cultural Heritage', 7.07, 125.61], ['Beach One', 'Beach & Leisure', 7.15, 125.71], ['Eden Park', 'Nature & Adventure', 7.03, 125.37]] as $i => [$name, $type, $lat, $lng]) {
            $this->destination($name, $type, $lat, $lng, 'Davao City', 5 - $i * 0.1);
        }

        if ($withSpare) {
            $this->destination('Spare Garden', 'Wildlife', 7.09, 125.63, 'Davao City', 3.0);
        }

        $this->inn();
    }

    private function preference(?string $arrival, int $days = 2): TouristPreference
    {
        $preference = TouristPreference::create([
            'travel_days' => $days, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any',
            'distance_pref' => 'moderate', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'place_of_origin' => 'Cebu City', 'start_date' => '2026-10-28', 'arrival_time' => $arrival,
        ]);
        PreferenceActivity::create(['preference_id' => $preference->id, 'activity' => 'Wildlife']);

        return $preference;
    }

    private function generate(TouristPreference $preference, array $swaps = []): Itinerary
    {
        return app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'), null, null, $swaps);
    }

    /**
     * One day of the schedule as plain arrays, in display order (day, then sort_order).
     *
     * @return list<array{kind: string, title: string, start: ?string, end: ?string, accommodation: ?int}>
     */
    private function day(Itinerary $itinerary, int $day): array
    {
        return $itinerary->items()->where('day_number', $day)->orderBy('sort_order')->get()
            ->map(fn ($r) => [
                'kind' => $r->kind, 'title' => (string) $r->title,
                'start' => $r->starts_at ? substr((string) $r->starts_at, 0, 5) : null,
                'end' => $r->ends_at ? substr((string) $r->ends_at, 0, 5) : null,
                'accommodation' => $r->accommodation_id,
            ])->all();
    }

    private function firstOf(array $rows, string $kind): ?array
    {
        foreach ($rows as $row) {
            if ($row['kind'] === $kind) {
                return $row;
            }
        }

        return null;
    }

    /** @return list<string> destination names of the day's activity rows, in order */
    private function stops(Itinerary $itinerary, int $day): array
    {
        return $itinerary->items()->with('destination')->where('day_number', $day)->where('kind', 'activity')
            ->whereNotNull('destination_id')->orderBy('sort_order')->get()
            ->map(fn ($r) => $r->destination->name)->all();
    }

    // ---------------------------------------------------------------------------------------------
    // 1. Departure and first activity, long and short first legs
    // ---------------------------------------------------------------------------------------------

    /**
     * The rule (ItineraryScheduleBuilder::openDay): departure = the later of (arrival + 30 minutes,
     * unrounded) and 08:00. An arrival before 06:30 therefore departs at 08:00, and the time until
     * then is a neutral free-time row. When "arrival + 30" falls inside the breakfast window (07:00 to
     * 08:30) and the traveller gave an arrival time, breakfast comes first near the starting point (45
     * minutes, from the next quarter hour), and the journey starts when it is over. A blank time is planned as an 08:00 arrival. The first activity
     * is the next quarter hour at or after (departure + the slower travel estimate).
     *
     * columns: arrival, departure, activity (long leg), activity (short leg), row kinds before the first journey row
     */
    public static function arrivals(): array
    {
        return [
            'blank (planned as an 08:00 arrival)' => [null, '08:30', '10:00', '08:45', ['baseline']],
            '04:00' => ['04:00', '08:00', '09:30', '08:15', ['baseline', 'free']],
            '05:59' => ['05:59', '08:00', '09:30', '08:15', ['baseline', 'free']],
            '06:00 (was: departed 06:30, outing at 08:00)' => ['06:00', '08:00', '09:30', '08:15', ['baseline', 'free']],
            '06:01 (was: departed 06:31)' => ['06:01', '08:00', '09:30', '08:15', ['baseline', 'free']],
            '06:30 (ready 07:00: breakfast 07:00-07:45, then the 08:00 floor)' => ['06:30', '08:00', '09:30', '08:15', ['baseline', 'meal']],
            '07:00 (breakfast first, 07:30-08:15)' => ['07:00', '08:15', '09:45', '08:30', ['baseline', 'meal']],
            '07:29 (breakfast first, 08:00-08:45)' => ['07:29', '08:45', '10:15', '09:00', ['baseline', 'meal']],
            '07:30 (breakfast first, 08:00-08:45)' => ['07:30', '08:45', '10:15', '09:00', ['baseline', 'meal']],
            '08:00 (breakfast first, 08:30-09:15)' => ['08:00', '09:15', '10:45', '09:30', ['baseline', 'meal']],
            // Arriving at Samal at 11:00, 30 minutes before lunch may start: lunch first (11:30-12:30), then the full visit.
            '09:00 (arrives 11:00: lunch first, then the visit)' => ['09:00', '09:30', '12:30', '09:45', ['baseline']],
        ];
    }

    #[DataProvider('arrivals')]
    public function test_long_first_leg_departure_and_first_activity(?string $arrival, string $departure, string $activityLong, string $activityShort, array $kindsBeforeJourney): void
    {
        $this->longLegFixture();
        $day1 = $this->day($this->generate($this->preference($arrival)), 1);

        $journey = $this->firstOf($day1, 'travel');
        $this->assertSame($departure, $journey['start'], 'departure = start of the first journey row');
        $this->assertSame($activityLong, $this->firstOf($day1, 'activity')['start'], 'first activity, long (Samal) leg');

        $before = [];
        foreach ($day1 as $row) {
            if ($row['kind'] === 'travel') {
                break;
            }
            $before[] = $row['kind'];
        }
        $this->assertSame($kindsBeforeJourney, $before);
    }

    #[DataProvider('arrivals')]
    public function test_short_first_leg_departure_and_first_activity(?string $arrival, string $departure, string $activityLong, string $activityShort): void
    {
        $this->shortLegFixture();
        $day1 = $this->day($this->generate($this->preference($arrival)), 1);

        $this->assertSame($departure, $this->firstOf($day1, 'travel')['start'], 'departure does not depend on the length of the first leg');
        $this->assertSame($activityShort, $this->firstOf($day1, 'activity')['start'], 'first activity, 15 min leg');
    }

    public function test_the_long_fixture_has_the_leg_length_the_matrix_assumes(): void
    {
        $this->longLegFixture();
        $leg = $this->generate($this->preference('09:00'))->items()->where('day_number', 1)->where('kind', 'travel')->orderBy('sort_order')->first();

        $this->assertSame([60, 90], [(int) $leg->travel_min_minutes, (int) $leg->travel_max_minutes], 'Samal leg: 60-90 min, the planner advances by 90');
    }

    public function test_the_short_fixture_has_the_leg_length_the_matrix_assumes(): void
    {
        $this->shortLegFixture();
        $leg = $this->generate($this->preference('09:00'))->items()->where('day_number', 1)->where('kind', 'travel')->orderBy('sort_order')->first();

        $this->assertSame([15, 15], [(int) $leg->travel_min_minutes, (int) $leg->travel_max_minutes]);
    }

    // ---------------------------------------------------------------------------------------------
    // 2. The rows around arrival
    // ---------------------------------------------------------------------------------------------

    public function test_an_early_arrival_shows_neutral_free_time_not_a_hotel_row(): void
    {
        $this->longLegFixture();
        $day1 = $this->day($this->generate($this->preference('04:00')), 1);

        $this->assertSame(['kind' => 'baseline', 'title' => 'Planned arrival', 'start' => '04:00', 'end' => null, 'accommodation' => null], $day1[0]);
        $this->assertSame(['kind' => 'free', 'title' => 'Free time until 8:00 AM', 'start' => '04:00', 'end' => '08:00', 'accommodation' => null], $day1[1]);
        $this->assertSame('travel', $day1[2]['kind']);
        $this->assertSame('08:00', $day1[2]['start'], 'the free time ends exactly when the first journey starts');

        $this->assertNotContains('meal', array_column(array_slice($day1, 0, 3), 'kind'), 'no meal is assumed');
        $this->assertSame([null, null], array_column(array_slice($day1, 0, 2), 'accommodation'), 'no accommodation is implied');
    }

    public function test_an_arrival_after_0600_also_gets_free_time_so_no_span_is_unexplained(): void
    {
        $this->longLegFixture();
        $day1 = $this->day($this->generate($this->preference('06:00')), 1);

        $this->assertSame(['baseline', 'free', 'travel'], [$day1[0]['kind'], $day1[1]['kind'], $day1[2]['kind']]);
        $this->assertSame(['06:00', '06:00', '08:00'], [$day1[0]['start'], $day1[1]['start'], $day1[2]['start']]);
        $this->assertSame('08:00', $day1[1]['end']);
    }

    public function test_a_blank_arrival_is_planned_as_an_0800_arrival_and_says_so(): void
    {
        $this->longLegFixture();
        $itinerary = $this->generate($this->preference(null));
        $day1 = $this->day($itinerary, 1);

        $this->assertSame('Planned arrival', $day1[0]['title']);
        $this->assertSame('08:00', $day1[0]['start']);
        $this->assertSame('08:30', $day1[1]['start'], 'scheduling is unchanged: an 08:00 arrival departs at 08:30');
        $this->assertSame(
            'No arrival time provided; assuming 8:00 AM. Starting point for travel times: Davao City centre.',
            $itinerary->items()->where('kind', 'baseline')->value('note')
        );
    }

    public function test_day_two_always_opens_with_breakfast_at_0700_whatever_the_arrival(): void
    {
        $this->longLegFixture();

        foreach (['04:00', '06:00', '09:00', null] as $arrival) {
            $day2 = $this->day($this->generate($this->preference($arrival)), 2);

            $this->assertSame('meal', $day2[0]['kind'], "arrival {$arrival}");
            $this->assertSame('Breakfast — City Inn', $day2[0]['title']);
            $this->assertSame('07:00', $day2[0]['start']);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // 3. Stops, their order, their days, and the ranking underneath
    // ---------------------------------------------------------------------------------------------

    /** columns: arrival (the expected stops are in EXPECTED_STOPS below) */
    public static function stopAssignments(): array
    {
        return [
            '04:00' => ['04:00'], '05:59' => ['05:59'], '06:00' => ['06:00'], '07:29' => ['07:29'], '07:30' => ['07:30'],
            '08:00' => ['08:00'], '09:00' => ['09:00'], 'blank' => [null],
            '12:00 (Day 1 capacity drops to 1)' => ['12:00'], '14:00' => ['14:00'], '16:59' => ['16:59'],
            '17:00 (Day 1 capacity drops to 0)' => ['17:00'], '22:00' => ['22:00'],
        ];
    }

    #[DataProvider('stopAssignments')]
    public function test_the_stop_order_and_day_assignment_today(?string $arrival): void
    {
        $this->multiFixture();
        $itinerary = $this->generate($this->preference($arrival));

        $actual = [$this->stops($itinerary, 1), $this->stops($itinerary, 2)];

        $this->assertSame(self::EXPECTED_STOPS[$arrival ?? 'blank'], $actual);
    }

    /**
     * Recorded from the current implementation: a 2-day trip, four places. Days list their stops in
     * visiting order (nearest first from the starting point).
     *
     * Until midday the arrival changes timing, not which stops are chosen. From 12:00 the coarse hour
     * gate in dayCapacities() trims Day 1, which shrinks the number of stops the whole trip takes
     * (4 -> 3 at 12:00, 3 -> 2 at 17:00). The pool is chosen BEFORE the schedule exists, so a smaller
     * pool can contain different places: 16:59 and 17:00 are one minute apart and Day 2 gets a
     * different pair. That is existing behaviour (separate from the arrival rule) and must not move
     * as a side effect of the arrival-scheduling or midnight changes.
     *
     * The trimmed pools (12:00 to 16:59) follow the distance rules: this trip is "Commutable", which now means 5 to
     * 50 km by car, so the Museum, on the starting point itself, scores a bucket below the Zoo and Beach One.
     */
    private const EXPECTED_STOPS = [
        '04:00' => [['Museum', 'Zoo'], ['Beach One', 'Eden Park']],
        '05:59' => [['Museum', 'Zoo'], ['Beach One', 'Eden Park']],
        '06:00' => [['Museum', 'Zoo'], ['Beach One', 'Eden Park']],
        '07:29' => [['Museum', 'Zoo'], ['Beach One', 'Eden Park']],
        '07:30' => [['Museum', 'Zoo'], ['Beach One', 'Eden Park']],
        '08:00' => [['Museum', 'Zoo'], ['Beach One', 'Eden Park']],
        '09:00' => [['Museum', 'Zoo'], ['Beach One', 'Eden Park']],
        'blank' => [['Museum', 'Zoo'], ['Beach One', 'Eden Park']],
        '12:00' => [['Zoo'], ['Beach One', 'Eden Park']],
        '14:00' => [['Zoo'], ['Beach One', 'Eden Park']],
        '16:59' => [[], ['Zoo', 'Beach One']],
        '17:00' => [[], ['Zoo', 'Eden Park']],
        '22:00' => [[], ['Zoo', 'Eden Park']],
    ];

    public function test_ranking_rows_do_not_depend_on_the_arrival_time(): void
    {
        $this->multiFixture();

        $snapshot = function (?string $arrival): array {
            return $this->generate($this->preference($arrival))->matches()->with('destination')->orderBy('rank')->get()
                ->map(fn ($m) => [$m->destination->name, $m->rank, (string) $m->match_score, (string) $m->pm, (string) $m->rs, (string) $m->ps, (string) $m->ds, (string) $m->as])->all();
        };

        $reference = $snapshot('09:00');
        $this->assertNotEmpty($reference);

        foreach ([null, '04:00', '06:00', '07:30', '14:00', '17:00', '23:30'] as $arrival) {
            $this->assertSame($reference, $snapshot($arrival), 'ranking rows with arrival '.($arrival ?? 'blank'));
        }
    }

    // ---------------------------------------------------------------------------------------------
    // 4. dayCapacities(): the coarse hour gate (must stay as it is)
    // ---------------------------------------------------------------------------------------------

    /** columns: arrival, number of Day 1 slots */
    public static function capacities(): array
    {
        return [
            'blank' => [null, 2], '00:00' => ['00:00', 2], '04:00' => ['04:00', 2], '07:30' => ['07:30', 2], '08:00' => ['08:00', 2],
            '09:00' => ['09:00', 2], '11:59' => ['11:59', 2], '12:00' => ['12:00', 1], '13:45' => ['13:45', 1], '16:59' => ['16:59', 1],
            '17:00' => ['17:00', 0], '23:59' => ['23:59', 0],
        ];
    }

    #[DataProvider('capacities')]
    public function test_day_capacities_uses_the_integer_arrival_hour(?string $arrival, int $day1Slots): void
    {
        $method = new ReflectionMethod(ItineraryGenerationService::class, 'dayCapacities');
        $method->setAccessible(true);

        $capacities = $method->invoke(app(ItineraryGenerationService::class), new TouristPreference(['arrival_time' => $arrival, 'travel_days' => 3]), 3);

        $this->assertSame($day1Slots, count($capacities[1]), 'Day 1');
        $this->assertSame(2, count($capacities[2]), 'Day 2 is always a full day');
        $this->assertSame(2, count($capacities[3]), 'Day 3 is always a full day');
    }

    // ---------------------------------------------------------------------------------------------
    // 5. Midnight (KNOWN DEFECT)
    // ---------------------------------------------------------------------------------------------

    /**
     * The builder's clock runs past midnight, but the schedule columns are `time` (no date), so a row after
     * midnight used to be stored as, say, 00:15 inside Day 1: displayed as a morning time, with the day's
     * times running backwards. No row is stored with a time that precedes the row before it; rows past
     * midnight carry no time and a note.
     *
     * @return list<string> the rows stored with a time earlier than the row before them (must be empty)
     */
    private function wrappedTitles(Itinerary $itinerary, int $days): array
    {
        $wrapped = [];

        foreach (range(1, $days) as $day) {
            $previous = null;
            foreach ($this->day($itinerary, $day) as $row) {
                if ($row['start'] === null) {
                    continue;
                }
                if ($previous !== null && $row['start'] < $previous) {
                    $wrapped[] = "day {$day}: {$row['kind']}: {$row['title']} at {$row['start']}";
                }
                $previous = $row['start'];
            }
        }

        return $wrapped;
    }

    public function test_a_2330_arrival_no_longer_stores_times_that_run_backwards(): void
    {
        $this->multiFixture();

        foreach ([1, 2, 3] as $days) {
            $itinerary = $this->generate($this->preference('23:30', $days));

            $this->assertSame([], $this->wrappedTitles($itinerary, $days), "{$days}-day trip, arrival 23:30");

            // The rows that fall after midnight keep their place in the day but show no time, with a note.
            $afterMidnight = $itinerary->items()->where('day_number', 1)->whereNull('starts_at')->get();
            $this->assertCount(2, $afterMidnight, "{$days}-day trip: travel and the last row fall after midnight");
            foreach ($afterMidnight as $row) {
                $this->assertStringContainsString('after midnight', (string) $row->note);
            }
        }
    }

    public function test_an_evening_arrival_before_the_wrap_threshold_is_chronological(): void
    {
        $this->multiFixture();

        foreach ([[1, '21:00'], [3, '21:00'], [3, '20:00']] as [$days, $arrival]) {
            $this->assertSame([], $this->wrappedTitles($this->generate($this->preference($arrival, $days)), $days), "{$days}-day, {$arrival}");
        }
    }

    // ---------------------------------------------------------------------------------------------
    // 6. Regenerating and swapping
    // ---------------------------------------------------------------------------------------------

    public function test_regenerating_keeps_the_departure_for_the_same_arrival(): void
    {
        $this->longLegFixture();
        $preference = $this->preference('06:01');

        $first = $this->generate($preference);
        $preference->increment('variation');
        $second = $this->generate($preference->refresh());

        $this->assertNotSame($first->id, $second->id, 'regenerating builds a new itinerary');
        $this->assertSame('08:00', $this->firstOf($this->day($first, 1), 'travel')['start']);
        $this->assertSame('08:00', $this->firstOf($this->day($second, 1), 'travel')['start']);
        $this->assertSame($this->stops($first, 1), $this->stops($second, 1));
    }

    public function test_swapping_a_stop_keeps_the_departure_and_replaces_only_that_stop(): void
    {
        $this->multiFixture(withSpare: true);
        $preference = $this->preference('06:01');

        $before = $this->generate($preference);
        $names = fn (Itinerary $i) => array_merge($this->stops($i, 1), $this->stops($i, 2));

        // Five places exist and a 2-day trip holds four: swap the first stop for the one left out.
        $original = Destination::where('name', $names($before)[0])->firstOrFail();
        $replacement = Destination::whereNotIn('name', $names($before))->firstOrFail();
        $after = $this->generate($preference->refresh(), [$original->id => $replacement->id]);

        $this->assertSame(
            $this->firstOf($this->day($before, 1), 'travel')['start'],
            $this->firstOf($this->day($after, 1), 'travel')['start'],
            'a swap changes a place, never when the day starts'
        );
        $this->assertNotContains($original->name, $names($after));
        $this->assertContains($replacement->name, $names($after));
        $this->assertSame(
            array_map(fn ($n) => $n === $original->name ? $replacement->name : $n, $names($before)),
            $names($after),
            'the replacement takes the original stop\'s position; every other stop keeps its place'
        );
    }
}
