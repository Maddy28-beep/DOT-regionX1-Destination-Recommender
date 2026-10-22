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
     * Every minute from 05:00 to 08:30 (the 5:59/6:00/6:01 and 7:29/7:30 boundaries included), checked
     * against the rule written out here: departure, the free-time row's presence and times, and the
     * first activity (the next quarter hour at or after departure + the 15 minute leg).
     */
    public function test_departure_free_time_and_first_activity_follow_the_rule_for_every_minute(): void
    {
        $this->shortLegFixture();
        $earliest = Carbon::parse('2026-10-20 08:00');
        $previousDeparture = null;

        for ($minute = 5 * 60; $minute <= 8 * 60 + 30; $minute++) {
            $arrival = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
            $at = Carbon::parse("2026-10-20 {$arrival}");
            $departure = $at->copy()->addMinutes(30)->max($earliest);
            $activity = $departure->copy()->addMinutes(15);
            $activity = $activity->minute % 15 === 0 ? $activity : $activity->addMinutes(15 - $activity->minute % 15);

            $day1 = $this->day($this->generate($this->preference($arrival)), 1);

            $this->assertSame($departure->format('H:i'), $this->firstOf($day1, 'travel')['start'], "departure for arrival {$arrival}");
            $this->assertSame($activity->format('H:i'), $this->firstOf($day1, 'activity')['start'], "first activity for arrival {$arrival}");

            $free = $this->firstOf($day1, 'free');
            if ($at->lessThan($earliest->copy()->subMinutes(30))) {
                $this->assertNotNull($free, "free time for arrival {$arrival}");
                $this->assertSame([$arrival, '08:00'], [$free['start'], $free['end']], "free time spans arrival to 08:00 for {$arrival}");
            } else {
                $this->assertNull($free, "no free time for arrival {$arrival}");
            }

            // Departure never goes backwards as the arrival gets later, and moves by at most a minute per minute.
            if ($previousDeparture !== null) {
                $step = $departure->diffInMinutes($previousDeparture, false);
                $this->assertGreaterThanOrEqual(0, -$step, "departure must not decrease at {$arrival}");
                $this->assertLessThanOrEqual(1, -$step, "departure must move at most 1 minute per minute at {$arrival}");
            }
            $previousDeparture = $departure;
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

    public function test_the_boundary_at_0730_and_just_after_it(): void
    {
        $this->shortLegFixture();

        $at0729 = $this->day($this->generate($this->preference('07:29')), 1);
        $at0730 = $this->day($this->generate($this->preference('07:30')), 1);
        $at0731 = $this->day($this->generate($this->preference('07:31')), 1);

        $this->assertSame('08:00', $this->firstOf($at0729, 'travel')['start']);
        $this->assertNotNull($this->firstOf($at0729, 'free'));
        $this->assertSame('08:00', $this->firstOf($at0730, 'travel')['start'], '07:30 + 30 minutes = 08:00 exactly');
        $this->assertNull($this->firstOf($at0730, 'free'), 'nothing left to fill at 07:30');
        $this->assertSame('08:01', $this->firstOf($at0731, 'travel')['start'], 'from 07:30 departure is arrival + 30, unrounded');
        $this->assertNull($this->firstOf($at0731, 'free'));
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
    public function test_every_arrival_before_0730_plans_the_same_day_as_0400_from_the_first_journey_on(string $fixture): void
    {
        $this->{$fixture}();

        $fromJourney = function (array $day1): array {
            $start = array_search('travel', array_column($day1, 'kind'), true);

            return array_slice($day1, $start);
        };

        $reference = $this->generate($this->preference('04:00'));
        $expectedDay1 = $fromJourney($this->day($reference, 1));
        $expectedDay2 = $this->day($reference, 2);

        foreach (['05:59', '06:00', '06:01', '07:00', '07:29', '07:30'] as $arrival) {
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

        $response->assertSee('Planned arrival');
        $response->assertSee('Starting point for travel times: Davao City centre.');
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
            ->assertSee('No arrival time provided; assuming 8:00 AM.');

        $given = $this->preference('09:00');
        $this->page($given, $this->generate($given))->assertOk()
            ->assertDontSee('No arrival time provided');
    }

    public function test_the_planning_form_describes_the_rule_it_uses(): void
    {
        $response = $this->get(route('plan.edit'))->assertOk();

        $response->assertSee('Planned arrival time (optional)');
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
}
