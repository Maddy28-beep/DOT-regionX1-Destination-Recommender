<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Geocoding\AddressSuggestionService;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Services\Recommendation\TravelTimeEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Departure-aware scheduling: a traveller who gives a departure time (and where they leave from) gets a last day that
 * still reaches the departure point with the stated allowance to spare.
 *
 * The expected figures come from the rule itself, written out in the tests (deadline = departure - allowance; latest
 * set-off = deadline - estimated journey; a visit may end 15 minutes before that), and from TravelTimeEstimator for the
 * journey, not from the builder. The journey is the planner's own estimate -- straight line x 1.3, slowest pace, no
 * routing service -- and the departure row says so.
 */
class DepartureAwareSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private const AIRPORT_LAT = 7.130;

    private const AIRPORT_LNG = 125.645;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 12:00:00');
        config(['itinerary.departure.airport_buffer_minutes' => 120]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---- fixtures ----------------------------------------------------------------------------------

    /** A destination $km north of the airport (a degree of latitude is 111.2 km), optionally with hours. */
    private function destination(string $name, float $km, float $rating = 5.0, ?float $lng = null, string $type = 'Wildlife'): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => $type, 'is_accredited' => true,
            'rating' => $rating, 'review_count' => 10, 'price_tier' => 'Mid-range',
            'latitude' => self::AIRPORT_LAT + $km / 111.2, 'longitude' => $lng ?? self::AIRPORT_LNG,
        ]);
    }

    private function unlocated(string $name): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Wildlife', 'is_accredited' => true,
            'rating' => 5, 'review_count' => 10, 'price_tier' => 'Mid-range', 'latitude' => null, 'longitude' => null,
        ]);
    }

    /** A stay about 7 km from the airport. */
    private function inn(): void
    {
        Accommodation::create([
            'slug' => 'city-inn', 'name' => 'City Inn', 'location' => 'Davao', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
            'latitude' => 7.07, 'longitude' => 125.61,
        ]);
    }

    /** Starts and leaves from the airport; a one-day trip by default. */
    private function preference(array $over = []): TouristPreference
    {
        $preference = TouristPreference::create($over + [
            'travel_days' => 1, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any',
            'distance_pref' => 'moderate', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'place_of_origin' => 'Cebu City', 'start_date' => '2026-10-28', 'arrival_time' => '08:00',
            'origin_lat' => self::AIRPORT_LAT, 'origin_lng' => self::AIRPORT_LNG, 'origin_label' => 'Davao International Airport',
        ]);
        PreferenceActivity::create(['preference_id' => $preference->id, 'activity' => 'Wildlife']);

        return $preference;
    }

    /** The same preference, leaving from the airport at $time. */
    private function leaving(string $time, array $over = []): TouristPreference
    {
        return $this->preference($over + [
            'departure_time' => $time, 'departure_lat' => self::AIRPORT_LAT, 'departure_lng' => self::AIRPORT_LNG,
            'departure_label' => 'Davao International Airport',
        ]);
    }

    private function generate(TouristPreference $preference): Itinerary
    {
        return app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'));
    }

    /** @return \Illuminate\Support\Collection<int, \App\Models\ItineraryItem> */
    private function day(Itinerary $itinerary, int $day)
    {
        return $itinerary->items()->where('day_number', $day)->orderBy('sort_order')->get();
    }

    private function hhmm(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }

    /** The last day's departure row. */
    private function departureRow(Itinerary $itinerary)
    {
        return $this->day($itinerary, (int) $itinerary->total_days)->last();
    }

    /** The planner's slow-end journey time for a straight-line distance, from the estimator itself. */
    private function journey(float $km): int
    {
        return (new TravelTimeEstimator())->estimate($km)['max_minutes'];
    }

    // ---- 2 and 6. the journey to the airport is an estimate from the estimator, not the straight line -----------

    public function test_a_stop_20_km_from_the_airport_uses_the_estimated_road_journey_not_the_straight_line(): void
    {
        $this->destination('Farm Park', 20);
        $itinerary = $this->generate($this->leaving('17:00'));
        $rows = $this->day($itinerary, 1);

        $toAirport = $rows->last(fn ($r) => $r->kind === 'travel');
        $this->assertSame('Travel to Davao International Airport', $toAirport->title);

        // 20 km in a straight line is 26 km of road, at the slowest pace, plus the per-leg overhead.
        $expected = $this->journey(20.0);
        $this->assertSame(75, $expected, 'the estimator, not 20 km at some speed, is the source of the figure');
        $this->assertSame($expected, (int) $toAirport->travel_max_minutes);
        $this->assertGreaterThan(20.0, (float) $toAirport->distance_km, 'the road distance, not the straight line');
    }

    public function test_the_travel_time_to_the_departure_point_is_an_estimate_and_the_page_says_so(): void
    {
        $this->destination('Farm Park', 5);
        $itinerary = $this->generate($this->leaving('17:00'));
        $row = $this->departureRow($itinerary);

        $this->assertStringContainsString('an estimate', (string) $row->note);
        $this->assertStringContainsString('not a routed time', (string) $row->note);
    }

    // ---- 3, 4, 5. the last activity ends in time, and the allowance is enforced and disclosed ------------------

    public function test_the_final_activity_ends_before_the_latest_destination_departure(): void
    {
        $this->destination('Farm Park', 20);
        $itinerary = $this->generate($this->leaving('17:00'));
        $rows = $this->day($itinerary, 1);

        // deadline = 17:00 - 2 h = 15:00; the journey is 75 minutes, so the latest set-off is 13:45.
        $activity = $rows->last(fn ($r) => $r->kind === 'activity');
        $this->assertNotNull($activity, 'a 20 km stop fits this departure');
        $this->assertLessThanOrEqual('13:45', $this->hhmm($activity->ends_at));

        $setOff = $rows->last(fn ($r) => $r->kind === 'travel');
        $this->assertLessThanOrEqual('13:45', $this->hhmm($setOff->starts_at));

        $departure = $rows->last();
        $this->assertSame('departure', $departure->kind);
        $this->assertLessThanOrEqual('15:00', $this->hhmm($departure->starts_at), 'at the airport by the deadline');
        $this->assertSame('17:00', $this->hhmm($departure->ends_at), 'the departure itself');
    }

    public function test_the_airport_allowance_is_enforced_and_stated_on_the_itinerary(): void
    {
        $this->destination('Farm Park', 20);

        $default = $this->departureRow($this->generate($this->leaving('16:00')));
        $this->assertLessThanOrEqual('14:00', $this->hhmm($default->starts_at), '2 hours before 16:00');
        $this->assertStringContainsString('Be at Davao International Airport by 2:00 PM', (string) $default->note);
        $this->assertStringContainsString('2 hours before your 4:00 PM departure', (string) $default->note);
        $this->assertStringContainsString('planning allowance', (string) $default->note);

        config(['itinerary.departure.airport_buffer_minutes' => 180]);
        $bigger = $this->departureRow($this->generate($this->leaving('16:00')));
        $this->assertLessThanOrEqual('13:00', $this->hhmm($bigger->starts_at), 'a larger allowance is honoured: 3 hours before 16:00');
        $this->assertStringContainsString('3 hours before your 4:00 PM departure', (string) $bigger->note);
    }

    // ---- 7 and 9. a distant stop is left out; with no time the day is just the journey ------------------------

    public function test_a_distant_destination_is_excluded_from_the_final_day(): void
    {
        $this->destination('Far Hill', 60);
        $this->destination('Near Park', 5, 4.0);

        $itinerary = $this->generate($this->leaving('15:00'));
        $names = $this->day($itinerary, 1)->where('kind', 'activity')->map(fn ($r) => $r->destination?->name)->filter()->values()->all();

        $this->assertContains('Near Park', $names);
        $this->assertNotContains('Far Hill', $names, 'its journey to the airport alone would miss the allowance');
        $this->assertLessThanOrEqual('13:00', $this->hhmm($this->departureRow($itinerary)->starts_at));
    }

    public function test_with_too_little_time_the_day_is_only_the_journey_and_no_stop_is_forced(): void
    {
        $this->destination('Near Park', 5);

        // 10:30 departure: deadline 08:30, the same minute the day starts (08:00 arrival + 30). Nothing fits.
        $itinerary = $this->generate($this->leaving('10:30'));
        $rows = $this->day($itinerary, 1);

        $this->assertCount(0, $rows->where('kind', 'activity'), 'no sightseeing is forced');
        $this->assertSame('departure', $rows->last()->kind);
        $this->assertStringContainsString('Departure from Davao International Airport', $rows->last()->title);
    }

    public function test_when_even_the_direct_journey_cannot_make_the_allowance_the_page_says_so(): void
    {
        $this->destination('Near Park', 5);

        // 08:30 departure: the deadline (06:30) is before the arrival (08:00) itself.
        $row = $this->departureRow($this->generate($this->leaving('08:30')));

        $this->assertStringContainsString('after the allowance starts', (string) $row->note);
        $this->assertStringContainsString('Consider an earlier start or a later departure', (string) $row->note);
    }

    // ---- 8. swapping: stops that cannot be done on the last day go earlier -------------------------------------

    public function test_stops_that_cannot_make_the_departure_are_moved_off_the_final_day(): void
    {
        $this->inn();
        $this->destination('Near Park', 4, 3.0);
        $this->destination('Far Hill One', 55, 5.0);
        $this->destination('Far Hill Two', 60, 4.9);

        $itinerary = $this->generate($this->leaving('14:00', ['travel_days' => 2, 'start_date' => '2026-10-28']));
        $last = $this->day($itinerary, 2);
        $lastNames = $last->where('kind', 'activity')->map(fn ($r) => $r->destination?->name)->filter()->values()->all();

        $this->assertNotContains('Far Hill One', $lastNames);
        $this->assertNotContains('Far Hill Two', $lastNames);

        // Whatever the last day holds, the journey on still makes the allowance (14:00 - 2 h = 12:00).
        $this->assertLessThanOrEqual('12:00', $this->hhmm($last->last()->starts_at));
        $this->assertSame('departure', $last->last()->kind);

        // The stop that fits is still there for the last day, or it was used earlier; the far ones were not dropped for it.
        $all = $itinerary->items()->where('kind', 'activity')->get()->map(fn ($r) => $r->destination?->name)->filter()->all();
        $this->assertTrue(in_array('Far Hill One', $all, true) || in_array('Far Hill Two', $all, true), 'a far stop is moved to an earlier day instead of being lost');
    }

    public function test_a_visit_is_cut_short_to_fit_the_departure_instead_of_the_stop_being_dropped(): void
    {
        // Two stops, so the first is not the trimmed last stop of the day and would otherwise get the full 150 minutes.
        $this->destination('Near Park', 5);
        $this->destination('Second Park', 5, 4.0, self::AIRPORT_LNG + 0.02);

        // Lands 11:00 and lunches first; leaves 17:30: deadline 15:30, a 30 minute journey back, so a visit may end at
        // 14:45 -- 90 minutes from a 13:15 start, shorter than the usual 150 but a proper visit.
        $rows = $this->day($this->generate($this->leaving('17:30', ['arrival_time' => '11:00'])), 1);
        $activity = $rows->first(fn ($r) => $r->kind === 'activity');

        $this->assertNotNull($activity, 'the stop is kept, with a shorter visit');
        $this->assertLessThanOrEqual('14:45', $this->hhmm($activity->ends_at));
        $minutes = (strtotime((string) $activity->ends_at) - strtotime((string) $activity->starts_at)) / 60;
        $this->assertGreaterThanOrEqual(90, $minutes);
        $this->assertLessThan(150, $minutes);
    }

    // ---- 10. flexible tourists ---------------------------------------------------------------------------

    public function test_a_traveller_without_departure_details_is_planned_as_before(): void
    {
        $this->destination('Farm Park', 20);
        $itinerary = $this->generate($this->preference());
        $rows = $this->day($itinerary, 1);

        $this->assertSame('departure', $rows->last()->kind);
        $this->assertSame('Departure', $rows->last()->title, 'the existing closing row, with no airport language');
        $this->assertNull($rows->last()->note);
        $this->assertCount(0, $rows->filter(fn ($r) => str_contains((string) $r->title, 'Departure from')));
        $this->assertCount(0, $rows->filter(fn ($r) => str_contains((string) $r->note, 'Be at ')));
    }

    public function test_a_time_without_a_located_point_changes_nothing_and_says_so(): void
    {
        $this->destination('Farm Park', 20);
        $none = $this->generate($this->preference());
        $noPoint = $this->generate($this->preference(['departure_time' => '17:00']));

        $shape = fn (Itinerary $i) => $this->day($i, 1)->map(fn ($r) => [$r->kind, $r->title, $r->starts_at, $r->ends_at])->all();
        $this->assertSame($shape($none), $shape($noPoint), 'no point, no invented transfer, no restriction');
        $this->assertStringContainsString('no located departure point', (string) $this->departureRow($noPoint)->note);
    }

    // ---- 11. existing arrival and sightseeing rules still hold -------------------------------------------------

    public function test_the_arrival_rule_and_the_sightseeing_hours_still_apply_with_a_departure(): void
    {
        $this->destination('Near Park', 5);
        $rows = $this->day($this->generate($this->leaving('21:00', ['arrival_time' => '09:00'])), 1);

        // depart at max(arrival + 30 minutes, 08:00) = 09:30
        $first = $rows->first(fn ($r) => $r->kind === 'travel');
        $this->assertSame('09:30', $this->hhmm($first->starts_at));

        foreach ($rows->where('kind', 'activity') as $activity) {
            $this->assertGreaterThanOrEqual('08:00', $this->hhmm($activity->starts_at));
            $this->assertLessThanOrEqual('18:00', $this->hhmm($activity->ends_at), 'the sightseeing cutoff still applies');
        }
    }

    public function test_an_evening_flight_does_not_lengthen_sightseeing_past_the_cutoff(): void
    {
        $this->destination('Near Park', 5);
        $rows = $this->day($this->generate($this->leaving('23:30')), 1);

        foreach ($rows->where('kind', 'activity')->where('souvenir_center_id', null) as $activity) {
            $this->assertLessThanOrEqual('18:00', $this->hhmm($activity->ends_at));
        }
    }

    // ---- edge: the departure row is not planned when the plan has no destination coordinates ------------------

    public function test_a_stop_without_coordinates_gets_a_time_allowance_and_says_it_was_not_measured(): void
    {
        $this->unlocated('Unmapped Cave');
        $row = $this->departureRow($this->generate($this->leaving('20:00')));

        $this->assertSame('departure', $row->kind);
        $this->assertStringContainsString('Departure from Davao International Airport', $row->title);
    }

    // ---- early flight: no breakfast the journey cannot wait for ------------------------------------------------

    public function test_an_early_departure_makes_the_last_day_just_the_journey_without_a_breakfast_row(): void
    {
        $this->inn();
        $this->destination('Near Park', 4);

        $itinerary = $this->generate($this->leaving('09:00', ['travel_days' => 2]));
        $last = $this->day($itinerary, 2);

        $this->assertCount(0, $last->where('kind', 'activity'));
        $this->assertCount(0, $last->filter(fn ($r) => str_starts_with((string) $r->title, 'Breakfast')), 'the journey must start before breakfast could end');
        $this->assertLessThanOrEqual('07:00', $this->hhmm($last->last()->starts_at), 'at the airport by 09:00 - 2 h');
    }
}
