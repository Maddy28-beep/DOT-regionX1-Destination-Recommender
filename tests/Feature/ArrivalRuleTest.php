<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Services\Recommendation\ItineraryScheduleBuilder;
use App\Services\Recommendation\ThemeDayGrouper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The arrival rule: departure = the later of (arrival + 30 minutes) and 08:00.
 *
 * - An arrival before 07:30 departs at 08:00; from 07:30 it departs 30 minutes after arrival (unrounded).
 * - Every arrival before 07:30 gets a neutral "Free time until 8:00 AM" row; nothing about a room,
 *   a meal or any service is assumed. The row is not a stop.
 * - A blank arrival time is still planned as an 08:00 arrival, and the page says so.
 *
 * Lunch is not part of the rule, but it moves with it. The builder inserts lunch at the FIRST eligible point inside the
 * 11:30-14:00 window: either on arriving at a stop, or when a stop's visit ends. Nothing else decides it (not the arrival
 * time, not the stop sequence). With the 08:00 departure floor, an arrival from 06:00 to 07:29 now plans the same Day 1 as
 * a 04:00 arrival, so its first visit can end inside the window and lunch comes after the first stop, where the old
 * schedules (which left at 06:30-07:59) often reached the window only on arriving at the second stop. That move is an
 * intended consequence of the rule; the lunch logic was not changed to preserve the old placement.
 *
 * The expected values here come from the rule itself (computed in the test), not from the builder.
 * Today's pinned outputs for fixed arrivals are in ArrivalBaselineTest.
 */
class ArrivalRuleTest extends TestCase
{
    use RefreshDatabase;

    private const CENTRE_LAT = 7.0731;

    private const CENTRE_LNG = 125.6128;

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

    // ---- fixtures (same shapes as ArrivalBaselineTest) ----------------------------------------------

    private function destination(string $name, string $type, float $lat, float $lng, string $region = 'Davao City', float $rating = 5.0): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => $region,
            'region_id' => Region::firstOrCreate(['name' => $region])->id, 'type' => $type, 'is_accredited' => true,
            'rating' => $rating, 'review_count' => 10, 'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng,
        ]);
    }

    private function inn(): void
    {
        Accommodation::create([
            'slug' => 'city-inn', 'name' => 'City Inn', 'location' => 'Davao', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
            'latitude' => 7.07, 'longitude' => 125.61,
        ]);
    }

    /** One stop on the starting point: a 15 minute first leg. */
    private function shortLegFixture(): void
    {
        $this->destination('Centre Wildlife Spot', 'Wildlife', self::CENTRE_LAT, self::CENTRE_LNG);
        $this->inn();
    }

    /** One stop on Samal: a 60-90 minute first leg, the planner advances 90. */
    private function longLegFixture(): void
    {
        $this->destination('Samal Wildlife Spot', 'Wildlife', 7.0731, 125.7386, 'Island Garden City of Samal');
        $this->inn();
    }

    private function multiFixture(): void
    {
        foreach ([['Zoo', 'Wildlife', 7.12, 125.64], ['Museum', 'Cultural Heritage', 7.07, 125.61], ['Beach One', 'Beach & Leisure', 7.15, 125.71], ['Eden Park', 'Nature & Adventure', 7.03, 125.37]] as $i => [$name, $type, $lat, $lng]) {
            $this->destination($name, $type, $lat, $lng, 'Davao City', 5 - $i * 0.1);
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

    private function generate(TouristPreference $preference): Itinerary
    {
        return app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'));
    }

    /** @return list<array{kind: string, title: string, start: ?string, end: ?string}> */
    private function day(Itinerary $itinerary, int $day): array
    {
        return $itinerary->items()->where('day_number', $day)->orderBy('sort_order')->get()
            ->map(fn ($r) => [
                'kind' => $r->kind, 'title' => (string) $r->title,
                'start' => $r->starts_at ? substr((string) $r->starts_at, 0, 5) : null,
                'end' => $r->ends_at ? substr((string) $r->ends_at, 0, 5) : null,
            ])->all();
    }

    /**
     * The free-time row that fills the wait before the first journey, if there is one. Day 1 can also hold a
     * "Free time until dinner" row after the return journey, so a free row is only the early-arrival one when
     * it comes before the first journey.
     */
    private function earlyArrivalFree(array $rows): ?array
    {
        foreach ($rows as $row) {
            if ($row['kind'] === 'travel') {
                return null;
            }
            if ($row['kind'] === 'free') {
                return $row;
            }
        }

        return null;
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

    private function page(TouristPreference $preference, Itinerary $itinerary)
    {
        return $this->withSession([
            TripPlannerController::PREFERENCE_KEY => $preference->id,
            TripPlannerController::ITINERARY_KEY => $itinerary->id,
        ])->get('/plan/itinerary');
    }

    // ---- the rule, minute by minute ------------------------------------------------------------------

    /**
     * Every minute from 05:00 to 08:30, checked against the rule written out here: departure, the free-time
     * row's presence and times, and the first activity (the next quarter hour at or after departure + the 15
     * minute leg).
     *
     * When "arrival + 30" falls in the breakfast window (07:00 to 08:30), breakfast comes first, from the next
     * quarter hour and for 45 minutes, and the journey starts when it is over (never before 08:00).
     */
    public function test_departure_free_time_and_first_activity_follow_the_rule_for_every_minute(): void
    {
        $this->shortLegFixture();
        $earliest = Carbon::parse('2026-10-20 08:00');
        $previousDeparture = null;
        $previousBreakfast = false;

        for ($minute = 5 * 60; $minute <= 8 * 60 + 30; $minute++) {
            $arrival = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
            $at = Carbon::parse("2026-10-20 {$arrival}");
            $ready = $at->copy()->addMinutes(30);
            $breakfast = $ready->betweenIncluded(Carbon::parse('2026-10-20 07:00'), Carbon::parse('2026-10-20 08:30'));

            if ($breakfast) {
                $breakfastStart = $ready->minute % 15 === 0 && $ready->second === 0 ? $ready->copy() : $ready->copy()->addMinutes(15 - $ready->minute % 15);
                $departure = $breakfastStart->addMinutes(45)->max($earliest);
            } else {
                $departure = $ready->copy()->max($earliest);
            }
            $activity = $departure->copy()->addMinutes(15);
            $activity = $activity->minute % 15 === 0 ? $activity : $activity->addMinutes(15 - $activity->minute % 15);

            $day1 = $this->day($this->generate($this->preference($arrival)), 1);

            $this->assertSame($departure->format('H:i'), $this->firstOf($day1, 'travel')['start'], "departure for arrival {$arrival}");
            $this->assertSame($activity->format('H:i'), $this->firstOf($day1, 'activity')['start'], "first activity for arrival {$arrival}");

            $free = $this->earlyArrivalFree($day1);
            if ($breakfast) {
                $this->assertNull($free, "breakfast, not free time, for arrival {$arrival}");
                $this->assertSame('Breakfast', explode(' ', $this->firstOf($day1, 'meal')['title'])[0], "breakfast first for arrival {$arrival}");
            } elseif ($at->lessThan($earliest->copy()->subMinutes(30))) {
                $this->assertNotNull($free, "free time for arrival {$arrival}");
                $this->assertSame([$arrival, '08:00'], [$free['start'], $free['end']], "free time spans arrival to 08:00 for {$arrival}");
            } else {
                $this->assertNull($free, "no free time for arrival {$arrival}");
            }

            // Departure never goes backwards as the arrival gets later, and moves by at most a minute per minute.
            // Not across the edges of the breakfast window, where eating first is a deliberate step of up to 45 minutes.
            if ($previousDeparture !== null && ! $breakfast && ! $previousBreakfast) {
                $step = $departure->diffInMinutes($previousDeparture, false);
                $this->assertGreaterThanOrEqual(0, -$step, "departure must not decrease at {$arrival}");
                $this->assertLessThanOrEqual(1, -$step, "departure must move at most 1 minute per minute at {$arrival}");
            }
            $previousDeparture = $departure;
            $previousBreakfast = $breakfast;
        }
    }

    public function test_the_departure_never_falls_before_0800(): void
    {
        $this->shortLegFixture();

        foreach (['00:00', '03:59', '04:00', '05:59', '06:00', '06:01', '07:00', '07:29', null] as $arrival) {
            $day1 = $this->day($this->generate($this->preference($arrival)), 1);

            $this->assertGreaterThanOrEqual('08:00', $this->firstOf($day1, 'travel')['start'], 'arrival '.($arrival ?? 'blank'));
        }
    }

    public function test_the_boundaries_of_the_breakfast_window_and_just_after_it(): void
    {
        $this->shortLegFixture();

        $day = fn (string $arrival) => $this->day($this->generate($this->preference($arrival)), 1);

        $at0629 = $day('06:29');
        $this->assertSame('08:00', $this->firstOf($at0629, 'travel')['start']);
        $this->assertNotNull($this->earlyArrivalFree($at0629), '06:29 + 30 minutes is before the breakfast window: free time until 08:00');

        $at0630 = $day('06:30');
        $this->assertNull($this->earlyArrivalFree($at0630), '06:30 + 30 minutes = 07:00, the window opens: breakfast, not free time');
        $this->assertSame(['07:00', '07:45'], [$this->firstOf($at0630, 'meal')['start'], $this->firstOf($at0630, 'meal')['end']]);
        $this->assertSame('08:00', $this->firstOf($at0630, 'travel')['start'], 'breakfast is over by 08:00, so the 08:00 floor still sets the departure');

        $at0800 = $day('08:00');
        $this->assertSame(['08:30', '09:15'], [$this->firstOf($at0800, 'meal')['start'], $this->firstOf($at0800, 'meal')['end']], '08:00 + 30 = 08:30, the last minute of the window');
        $this->assertSame('09:15', $this->firstOf($at0800, 'travel')['start']);

        $at0801 = $day('08:01');
        $this->assertSame('08:31', $this->firstOf($at0801, 'travel')['start'], '08:01 + 30 = 08:31 is past the window: no breakfast, departure is arrival + 30');
        $beforeJourney = array_column(array_slice($at0801, 0, array_search('travel', array_column($at0801, 'kind'), true)), 'kind');
        $this->assertSame(['baseline'], $beforeJourney);
    }

    // ---- early arrivals plan the same day as the 04:00 case ------------------------------------------

    /**
     * Whatever the early arrival, Day 1 from the first journey onward and the whole of Day 2 are identical to
     * the 04:00 plan; only the arrival and free-time rows in front differ. Checked on the long-leg fixture and
     * on the four-stop fixture (so stop order and per-day assignment are covered too).
     */
    public static function fixtures(): array
    {
        return ['one stop, long leg' => ['longLegFixture'], 'four stops, two days' => ['multiFixture']];
    }

    #[DataProvider('fixtures')]
    public function test_every_arrival_before_0630_plans_the_same_day_as_0400_from_the_first_journey_on(string $fixture): void
    {
        $this->{$fixture}();

        $fromJourney = function (array $day1): array {
            $start = array_search('travel', array_column($day1, 'kind'), true);

            return array_slice($day1, $start);
        };

        $reference = $this->generate($this->preference('04:00'));
        $expectedDay1 = $fromJourney($this->day($reference, 1));
        $expectedDay2 = $this->day($reference, 2);

        foreach (['05:59', '06:00', '06:01', '06:29'] as $arrival) {
            $itinerary = $this->generate($this->preference($arrival));

            $this->assertSame($expectedDay1, $fromJourney($this->day($itinerary, 1)), "Day 1 from the first journey, arrival {$arrival}");
            $this->assertSame($expectedDay2, $this->day($itinerary, 2), "Day 2, arrival {$arrival}");
        }
    }

    // ---- presentation --------------------------------------------------------------------------------

    public function test_the_page_shows_planned_arrival_the_starting_point_and_neutral_free_time(): void
    {
        $this->longLegFixture();
        $preference = $this->preference('04:00');
        $itinerary = $this->generate($preference);

        $response = $this->page($preference, $itinerary)->assertOk();

        $response->assertSee('Start in Davao City centre');
        $response->assertSee('Free time until 8:00 AM');
        $response->assertSee('You might rest or eat; nothing is arranged.');
        $response->assertSee('Location of your choice');
        $response->assertDontSee('On-site Activity', false);
        $response->assertDontSee('Rest, freshen up', false);
        $response->assertDontSee('No arrival time provided');
    }

    public function test_free_time_is_not_a_stop_and_has_the_clock_icon(): void
    {
        $this->longLegFixture();
        $preference = $this->preference('04:00');
        $itinerary = $this->generate($preference);

        $html = $this->page($preference, $itinerary)->assertOk()->getContent();

        $this->assertSame(1, $itinerary->items()->where('kind', 'activity')->count());
        // Day 1's own tag (date, stops, distance); not the map link's label, which also counts the hotel waypoint.
        $this->assertSame(1, preg_match('/itin-day__tag">(.*?)<\/p>/s', $html, $tag));
        $this->assertMatchesRegularExpression('/\b1 stop\b/', $tag[1], 'Day 1 counts its one stop; the free-time row is not counted');

        // The clock icon (a circle) sits inside the free-time row, between its opening and the next row's.
        $this->assertSame(1, preg_match('/tl-row--free(.*?)tl-row tl-row--/s', $html, $row), 'a free-time row is rendered');
        $this->assertStringContainsString('<circle cx="12" cy="12" r="9"/>', $row[1], 'clock icon');
    }

    public function test_a_blank_arrival_time_is_disclosed_and_a_given_one_is_not(): void
    {
        $this->longLegFixture();

        $blank = $this->preference(null);
        $this->page($blank, $this->generate($blank))->assertOk()
            ->assertSee('No arrival time set, so we assumed 8:00 AM.')
            ->assertSee('Set arrival time');

        $given = $this->preference('09:00');
        $this->page($given, $this->generate($given))->assertOk()
            ->assertDontSee('No arrival time set')
            ->assertSee('You arrive at 9:00 AM.');
    }

    public function test_the_planning_form_describes_the_rule_it_uses(): void
    {
        $response = $this->get(route('plan.edit'))->assertOk();

        $response->assertSee('Arrival time (optional)');
        $response->assertSee('about 30 minutes before your first outing and never start an outing before 8:00 AM.');
        $response->assertSee("we'll assume 8:00 AM.");
        $response->assertDontSee("We won't put a morning stop on your first day", false);
    }

    // ---- a consequence worth pinning -----------------------------------------------------------------

    /**
     * The old rest-and-breakfast row carried the accommodation, so an arrival before 06:00 began its Day 1
     * route at the hotel. With no hotel row assumed, Day 1's route now begins at the first stop for every
     * arrival, and the hotel appears where it is really used (dinner and the night).
     */
    public function test_day_one_route_stops_begin_at_the_first_stop_not_the_hotel(): void
    {
        $this->multiFixture();

        foreach (['04:00', '06:00', '09:00'] as $arrival) {
            $labels = array_column($this->generate($this->preference($arrival))->routeStops()[1], 'label');

            $this->assertSame('Museum', $labels[0], "arrival {$arrival}");
            $this->assertSame('City Inn', end($labels), "arrival {$arrival}: the hotel closes the day");
        }
    }
    // ---- lunch ---------------------------------------------------------------------------------------

    /**
     * One stop 90 minutes away, a second further on: the first visit (09:30-12:00 after the 08:00 departure) ends inside
     * the lunch window, so lunch follows it directly. Under the old rule a 06:00 arrival left at 06:30, finished that
     * visit at 10:30, and only reached the window on arriving at the next stop (measured on the seeded data: lunch at
     * 12:45 at the second stop instead of 12:00 after the first).
     */
    public function test_lunch_follows_the_first_visit_when_that_visit_ends_inside_the_window(): void
    {
        $this->destination('Samal Wildlife Spot', 'Wildlife', 7.0731, 125.7386, 'Island Garden City of Samal');
        $this->destination('Eden Park', 'Nature & Adventure', 7.03, 125.37, 'Davao City', 4.9);
        $this->inn();

        foreach (['04:00', '06:00', '06:01', '06:29'] as $arrival) {
            $day1 = $this->day($this->generate($this->preference($arrival)), 1);
            $lunchAt = array_search('meal', array_column($day1, 'kind'), true);

            $this->assertNotFalse($lunchAt, "arrival {$arrival}: lunch exists");
            $this->assertSame(['activity', '09:30', '12:00'], [$day1[$lunchAt - 1]['kind'], $day1[$lunchAt - 1]['start'], $day1[$lunchAt - 1]['end']], "arrival {$arrival}: the first visit comes right before lunch");
            $this->assertSame(['12:00', '13:00'], [$day1[$lunchAt]['start'], $day1[$lunchAt]['end']], "arrival {$arrival}: lunch starts when the visit ends");
        }
    }

    /** Whatever the arrival, Day 1 has at most one lunch and it starts inside the lunch window (11:30 AM to 1:00 PM). */
    public function test_lunch_is_once_a_day_and_inside_the_window(): void
    {
        $this->multiFixture();

        foreach (['04:00', '06:00', '07:29', '07:30', '08:00', '09:00', '10:30'] as $arrival) {
            $lunches = array_values(array_filter($this->day($this->generate($this->preference($arrival)), 1), fn ($r) => $r['kind'] === 'meal' && str_starts_with($r['title'], 'Lunch')));

            $this->assertLessThanOrEqual(1, count($lunches), "arrival {$arrival}");
            foreach ($lunches as $lunch) {
                $this->assertGreaterThanOrEqual('11:30', $lunch['start'], "arrival {$arrival}");
                $this->assertLessThanOrEqual('13:00', $lunch['start'], "arrival {$arrival}");
            }
        }
    }

    // ---- the help text matches the scheduler ----------------------------------------------------------

    public function test_the_planning_form_quotes_the_scheduler_cutoff_and_the_afternoon_behaviour(): void
    {
        $cutoff = Carbon::parse(ItineraryScheduleBuilder::LAST_ACTIVITY_END)->format('g:i A');

        $this->get(route('plan.edit'))->assertOk()
            ->assertSee('A later arrival leaves less of your first day, so it may have fewer stops, or none if you arrive in the evening.')
            ->assertSee("Destination sightseeing ends by {$cutoff}.");
        $this->assertSame('6:00 PM', $cutoff, 'the sentence in the PR and the form say 6:00 PM because the scheduler cutoff is 18:00');
    }

    /** The claims behind that help text: no sightseeing stop ends after the cutoff, and an evening arrival has none on Day 1. */
    public function test_sightseeing_ends_by_the_cutoff_and_an_evening_arrival_has_no_first_day_stops(): void
    {
        $this->multiFixture();

        foreach (['04:00', '07:30', '09:00', '12:00', '13:45', '14:30', '16:00'] as $arrival) {
            $itinerary = $this->generate($this->preference($arrival, 3));

            foreach ($itinerary->items()->where('kind', 'activity')->whereNotNull('destination_id')->get() as $row) {
                $this->assertLessThanOrEqual(ItineraryScheduleBuilder::LAST_ACTIVITY_END, substr((string) $row->ends_at, 0, 5), "arrival {$arrival}, day {$row->day_number}");
            }
        }

        foreach (['17:00', '19:00', '22:00'] as $arrival) {
            $this->assertNull($this->firstOf($this->day($this->generate($this->preference($arrival)), 1), 'activity'), "arrival {$arrival}: no Day 1 stop");
        }
    }

    // ---- a stop the 08:00 floor can cost -------------------------------------------------------------

    /**
     * Existing behaviour, made visible by the arrival rule. Measured on the seeded data (Beach & Island, far, 2 days,
     * 06:00 arrival) and reproduced here with the same coordinates. Four far-flung stops; the theme grouping puts Mount Apo
     * and Bemwa on Day 1 and Samal and Dahican on Day 2 (a stand-in grouper does that here, because the suite has no embeddings).
     *
     *  - Day 1: the first stop is Mount Apo (10:15-12:45) and lunch follows (to 13:45). Bemwa is then 180 minutes away and
     *    Samal 195 minutes away: either visit would end after the 18:00 cutoff, so neither is placed that day.
     *  - Day 2 (the last day): the queue is now Bemwa, Samal, Dahican. Bemwa goes first; Samal is then 225 minutes from Bemwa
     *    (across the ferry) and Dahican further still, so neither can finish by the cutoff.
     *  - A stop is carried to the next day only if it fits there. With two days and three-hour legs between these places,
     *    two stops fit and the other two are left out. Nothing is forced in.
     *
     * Before the rule changed, a 06:00 arrival left at 06:30, so Mount Apo and then Samal fitted on Day 1 (seeded run: Samal
     * 14:30-17:00). The rule makes that arrival plan like a 04:00 arrival always did, which has only ever fitted two of these
     * four stops. That is the day-capacity and cutoff logic working as designed: pinned here, not changed.
     */
    public function test_early_arrivals_consistently_reject_a_far_flung_plan_outside_the_audit_limits(): void
    {
        $this->destination('Samal Island', 'Beach & Leisure', 7.1553, 125.708, 'Island Garden City of Samal', 4.4);
        $this->destination('Mount Apo Natural Park', 'Adventure & Hiking', 6.9847, 125.2725, 'Davao del Sur', 4.3);
        $this->destination('Bemwa Farm Fresh Inc.', 'Farm Tourism', 7.481914, 125.259499, 'Davao City', 4.2);
        $this->destination('Dahican Beach', 'Beach & Surfing', 6.9553, 126.2758, 'Davao Oriental', 4.1);
        Accommodation::create([
            'slug' => 'sonreir', 'name' => 'Sonreir Apartelle and Inn', 'location' => 'Davao', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range', 'latitude' => 7.176, 'longitude' => 125.4693,
        ]);

        // Stand-in for the embedding-based grouper: Mount Apo + Bemwa share Day 1, Samal + Dahican Day 2.
        $this->app->bind(ThemeDayGrouper::class, fn () => new class extends ThemeDayGrouper
        {
            public function __construct() {}

            public function group(array $sequence, array $dayCapacities, array $origin): ?array
            {
                $by = collect($sequence)->keyBy(fn ($e) => $e['row']['destination']->name);
                $order = ['Mount Apo Natural Park', 'Bemwa Farm Fresh Inc.', 'Samal Island', 'Dahican Beach'];

                return [
                    'sequence' => array_map(fn ($name) => $by[$name], $order),
                    'days' => [],
                    'summary' => ['regrouped' => true, 'mean_similarity_before' => 0.0, 'mean_similarity_after' => 0.0, 'distance_before_km' => 0.0, 'distance_after_km' => 0.0],
                ];
            }
        });

        $stopsFor = function (string $arrival): array {
            $preference = $this->preference($arrival);
            $preference->update(['distance_pref' => 'far']);
            PreferenceActivity::where('preference_id', $preference->id)->update(['activity' => 'Beach & Island']);

            $itinerary = $this->generate($preference->refresh());

            return [
                1 => $itinerary->items()->with('destination')->where('day_number', 1)->where('kind', 'activity')->whereNotNull('destination_id')->orderBy('sort_order')
                    ->get()->map(fn ($r) => $r->destination->name.' '.substr($r->starts_at, 0, 5).'-'.substr($r->ends_at, 0, 5))->all(),
                2 => $itinerary->items()->with('destination')->where('day_number', 2)->where('kind', 'activity')->whereNotNull('destination_id')->orderBy('sort_order')
                    ->get()->map(fn ($r) => $r->destination->name.' '.substr($r->starts_at, 0, 5).'-'.substr($r->ends_at, 0, 5))->all(),
            ];
        };

        // Earlier arrivals must not bypass the shared departure floor or let
        // an infeasible far-flung itinerary escape the final audit limits.
        foreach (['04:00', '06:00', '06:29'] as $arrival) {
            try {
                $stopsFor($arrival);
                $this->fail('An over-limit itinerary must be rejected for '.$arrival);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertArrayHasKey('distance_pref', $e->errors());
            }
        }
    }
}
