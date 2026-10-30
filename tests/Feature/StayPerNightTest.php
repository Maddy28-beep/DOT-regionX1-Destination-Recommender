<?php

namespace Tests\Feature;

use App\Models\AccreditationRecord;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\ExitSurveyVisit;
use App\Models\Itinerary;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Services\Recommendation\ItineraryScheduleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * One stay per night, each chosen for that night alone: scored by the journey time from the day's last stop plus the
 * journey time on to the next day's first stop, kept from night to night unless another stay saves more than 30
 * minutes, and only offered for a night it is accredited on (including the test grace period).
 *
 * Journey-time facts the geometry below relies on (TravelTimeEstimator, a car, no ferry): every leg is at least 15
 * minutes; a leg of up to about 4 km straight-line is 15, 5 to 8 km is 30, 10 to 12 km is 45. So a stay at the stop
 * costs 30 minutes a night, one 5 km away 60 and one 10 km away 90.
 */
class StayPerNightTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-02 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---- fixtures ----------------------------------------------------------------------------------

    private function region(string $name = 'Davao City'): int
    {
        return Region::firstOrCreate(['name' => $name])->id;
    }

    private function stop(float $lat, float $lng, string $region = 'Davao City'): array
    {
        $d = Destination::create([
            'slug' => 'stop-'.(++$this->n), 'name' => 'Stop '.$this->n, 'location' => $region, 'region_id' => $this->region($region), 'type' => 'Nature & Leisure',
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'latitude' => $lat, 'longitude' => $lng,
        ]);

        return ['row' => ['destination' => $d->load('region')], 'distance_km' => null];
    }

    private function stay(string $name, float $lat, float $lng, array $over = [], ?array $record = null, string $region = 'Davao City'): Accommodation
    {
        $a = Accommodation::create($over + [
            'slug' => str($name)->slug().'-'.(++$this->n), 'name' => $name, 'location' => $region, 'region_id' => $this->region($region), 'type' => 'Hotel',
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'latitude' => $lat, 'longitude' => $lng,
        ]);

        $record ??= ['status' => 'Active', 'expiration_date' => '2028-01-01'];
        AccreditationRecord::create($record + [
            'listing_kind' => 'accommodation', 'listing_id' => $a->id, 'accreditation_number' => 'DOT-S-'.(++$this->n),
            'issue_date' => '2024-01-01',
        ]);

        return $a;
    }

    /** @param  array<int, int>  $slots  day => number of stop slots */
    private function pick(array $sequence, array $slots, array $answers = []): ?array
    {
        $capacities = array_map(fn (int $n) => array_fill(0, $n, 'Morning'), $slots);
        $preference = new TouristPreference($answers + [
            'travel_days' => count($slots), 'travel_type' => 'Family', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any',
            'distance_pref' => 'far', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'start_date' => '2026-11-13',
        ]);

        $method = new \ReflectionMethod(ItineraryGenerationService::class, 'pickStays');

        return $method->invoke(app(ItineraryGenerationService::class), $sequence, $capacities, $preference, ['lat' => 7.0, 'lng' => 125.6, 'label' => 'Start']);
    }

    /** @return array<int, ?string> night => stay name */
    private function names(?array $stays): array
    {
        return array_map(fn ($s) => $s['listing']->name ?? null, $stays ?? []);
    }

    // ---- 1. one cluster ---------------------------------------------------------------------------

    public function test_one_cluster_keeps_one_stay_for_every_night(): void
    {
        $here = [7.07, 125.61];
        $near = $this->stay('Near Inn', 7.071, 125.611);
        $this->stay('Far Inn', 7.35, 125.61);

        $stays = $this->pick([$this->stop(...$here), $this->stop(...$here), $this->stop(...$here), $this->stop(...$here)], [1 => 1, 2 => 1, 3 => 1, 4 => 1]);

        $this->assertSame([1 => 'Near Inn', 2 => 'Near Inn', 3 => 'Near Inn'], $this->names($stays));
        $this->assertSame($near->id, $stays[1]['listing']->id);
    }

    // ---- 2. two clusters --------------------------------------------------------------------------

    public function test_two_clusters_get_a_stay_near_each_and_one_change_in_the_middle(): void
    {
        $this->stay('Stay A', 7.071, 125.611);
        $this->stay('Stay B', 7.971, 125.611, region: 'Davao del Norte');

        // Days 1-2 in the city, days 3-4 a hundred kilometres north.
        $stays = $this->pick(
            [$this->stop(7.07, 125.61), $this->stop(7.075, 125.61), $this->stop(7.97, 125.61, 'Davao del Norte'), $this->stop(7.975, 125.61, 'Davao del Norte')],
            [1 => 1, 2 => 1, 3 => 1, 4 => 1],
        );

        // Night 2 sits between the two clusters and costs the same from either stay, so staying put wins; night 3
        // is spent beside the northern stops.
        $this->assertSame([1 => 'Stay A', 2 => 'Stay A', 3 => 'Stay B'], $this->names($stays));
    }

    // ---- 3. keep or switch ------------------------------------------------------------------------

    /** Night 1 is spent beside Z1, night 2 beside Z2, $km apart: a day with two stops ends in the second. */
    private function twoNights(float $km): array
    {
        $z1 = [7.07, 125.61];
        $z2 = [7.07 + $km / 111.2, 125.61];
        $this->stay('At Z1', ...$z1);
        $this->stay('At Z2', ...$z2);

        return $this->names($this->pick(
            [$this->stop(...$z1), $this->stop(...$z1), $this->stop(...$z2), $this->stop(...$z2)],
            [1 => 1, 2 => 2, 3 => 1],
        ));
    }

    public function test_a_stay_that_costs_exactly_thirty_minutes_more_is_kept(): void
    {
        // 5.2 km: the stay at Z1 costs 60 minutes on night 2 against 30 at Z2, a difference of exactly 30.
        $this->assertSame([1 => 'At Z1', 2 => 'At Z1'], $this->twoNights(5.2));
    }

    public function test_a_stay_that_costs_sixty_minutes_more_is_changed(): void
    {
        // 10.6 km: 90 minutes at Z1 against 30 at Z2.
        $this->assertSame([1 => 'At Z1', 2 => 'At Z2'], $this->twoNights(10.6));
    }

    // ---- 4. the ferry -----------------------------------------------------------------------------

    public function test_journey_time_not_straight_line_distance_decides_so_a_ferry_crossing_counts(): void
    {
        $z = [7.07, 125.61];
        // A stay on Samal 6 km away in a straight line (30 minutes, plus the ferry each way) against a city stay 10.6 km away (45).
        $this->stay('Samal Stay', 7.07 + 6 / 111.2, 125.61, region: 'Island Garden City of Samal');
        $this->stay('City Stay', 7.07 + 10.6 / 111.2, 125.61);

        $stays = $this->pick([$this->stop(...$z), $this->stop(...$z)], [1 => 1, 2 => 1]);

        $this->assertSame([1 => 'City Stay'], $this->names($stays));
    }

    // ---- 5. accreditation on the night ---------------------------------------------------------------

    public function test_a_stay_marked_expired_is_never_offered_even_when_it_is_the_closest(): void
    {
        $z = [7.07, 125.61];
        $this->stay('Closest But Expired', 7.0701, 125.6101, record: ['status' => 'Expired', 'expiration_date' => '2026-09-01']);
        $this->stay('Next Best', 7.1, 125.61);

        $this->assertSame([1 => 'Next Best'], $this->names($this->pick([$this->stop(...$z), $this->stop(...$z)], [1 => 1, 2 => 1])));
    }

    public function test_a_stay_whose_real_expiry_is_before_the_night_is_excluded_unless_the_grace_period_covers_it(): void
    {
        $z = [7.07, 125.61];
        // Still marked Active (the sync has not run), but its real date was 31 Oct: the trip is on 13-14 Nov.
        $this->stay('Lapsed On Paper', 7.0701, 125.6101, record: ['status' => 'Active', 'expiration_date' => '2026-10-31']);
        $this->stay('Valid Stay', 7.1, 125.61);
        $sequence = fn () => [$this->stop(...$z), $this->stop(...$z)];

        config(['dot.accreditation_test_grace_until' => null]);
        $this->assertSame([1 => 'Valid Stay'], $this->names($this->pick($sequence(), [1 => 1, 2 => 1])), 'no grace: the lapsed stay is out');

        config(['dot.accreditation_test_grace_until' => '2026-12-31']);
        $this->assertSame([1 => 'Lapsed On Paper'], $this->names($this->pick($sequence(), [1 => 1, 2 => 1])), 'grace covers 13 Nov: it stays on offer');

        $this->assertSame([1 => 'Valid Stay'], $this->names($this->pick($sequence(), [1 => 1, 2 => 1], ['start_date' => '2027-01-10'])), 'a trip after the grace date is not covered');
    }

    // ---- 6. the stated type -------------------------------------------------------------------------

    public function test_the_stated_stay_type_is_honoured(): void
    {
        $z = [7.07, 125.61];
        $this->stay('Closest Resort', 7.0701, 125.6101, ['type' => 'Resort']);
        $this->stay('A Hotel', 7.1, 125.61, ['type' => 'Hotel']);

        $stays = $this->pick([$this->stop(...$z), $this->stop(...$z)], [1 => 1, 2 => 1], ['accommodation_pref' => 'Hotel']);

        $this->assertSame([1 => 'A Hotel'], $this->names($stays));
    }

    // ---- 7. Apriori -------------------------------------------------------------------------------

    private function pairWithStay(Destination $destination, Accommodation $stay, int $surveys = 3): void
    {
        foreach (range(1, $surveys) as $i) {
            $survey = ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes', 'data_source' => 'real', 'submitted_at' => now()]);
            ExitSurveyVisit::create(['exit_survey_id' => $survey->id, 'listing_kind' => 'destination', 'listing_id' => $destination->id]);
            ExitSurveyVisit::create(['exit_survey_id' => $survey->id, 'listing_kind' => 'accommodation', 'listing_id' => $stay->id]);
        }
    }

    public function test_an_apriori_stay_within_fifteen_minutes_of_the_best_is_preferred_and_keeps_its_rule(): void
    {
        $z = [7.07, 125.61];
        $stopA = $this->stop(...$z);
        $this->stay('Plain Best', 7.07, 125.61);
        $suggested = $this->stay('Suggested Tie', 7.0705, 125.6105);
        $this->pairWithStay($stopA['row']['destination'], $suggested);

        $stays = $this->pick([$stopA, $this->stop(...$z)], [1 => 1, 2 => 1]);

        $this->assertSame([1 => 'Suggested Tie'], $this->names($stays));
        $this->assertSame($stopA['row']['destination']->name, $stays[1]['rule']['basis']);
        $this->assertGreaterThan(0, $stays[1]['rule']['confidence']);
    }

    public function test_an_apriori_stay_that_is_much_worse_than_the_best_is_not_chosen(): void
    {
        $z = [7.07, 125.61];
        $stopA = $this->stop(...$z);
        $this->stay('Plain Best', 7.07, 125.61);
        $far = $this->stay('Suggested But Far', 7.07 + 10.6 / 111.2, 125.61);
        $this->pairWithStay($stopA['row']['destination'], $far);

        $stays = $this->pick([$stopA, $this->stop(...$z)], [1 => 1, 2 => 1]);

        $this->assertSame([1 => 'Plain Best'], $this->names($stays));
        $this->assertNull($stays[1]['rule']);
    }

    // ---- 8. short trips ---------------------------------------------------------------------------

    public function test_a_one_day_trip_has_no_night_to_plan_and_a_two_day_trip_has_one(): void
    {
        $z = [7.07, 125.61];
        $this->stay('Only Inn', 7.07, 125.61);

        $this->assertNull($this->pick([$this->stop(...$z)], [1 => 2]), 'a day trip has no stay');
        $this->assertSame([1 => 'Only Inn'], $this->names($this->pick([$this->stop(...$z), $this->stop(...$z)], [1 => 1, 2 => 1])));
    }

    // ---- 9. the schedule uses each night's stay -------------------------------------------------------

    public function test_the_schedule_starts_each_morning_from_last_nights_stay_and_ends_each_day_at_that_nights(): void
    {
        $first = $this->stay('First Inn', 7.07, 125.61);
        $second = $this->stay('Second Inn', 7.17, 125.61);
        $stops = [$this->stop(7.07, 125.61), $this->stop(7.17, 125.61), $this->stop(7.17, 125.61)];

        $preference = TouristPreference::create([
            'travel_days' => 3, 'travel_type' => 'Family', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'far',
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Davao City', 'start_date' => '2026-11-13', 'arrival_time' => '08:00',
        ]);
        $itinerary = Itinerary::create(['preference_id' => $preference->id, 'total_days' => 3, 'generated_at' => now()]);

        app(ItineraryScheduleBuilder::class)->build(
            $itinerary, $stops, $preference, [1 => ['Morning'], 2 => ['Morning'], 3 => ['Morning']],
            ['lat' => 7.07, 'lng' => 125.61, 'label' => 'Start'],
            [1 => ['listing' => $first, 'rule' => null], 2 => ['listing' => $second, 'rule' => null]],
        );

        $rows = $itinerary->items()->orderBy('day_number')->orderBy('sort_order')->get();
        $night = fn (int $day) => $rows->where('day_number', $day)->firstWhere('kind', 'overnight');
        $breakfast = fn (int $day) => $rows->where('day_number', $day)->first(fn ($r) => str_starts_with((string) $r->title, 'Breakfast'));

        $this->assertSame($first->id, $night(1)->accommodation_id);
        $this->assertSame($second->id, $night(2)->accommodation_id);
        $this->assertSame('Breakfast — First Inn', $breakfast(2)->title, 'day 2 starts at last night\'s stay');
        $this->assertSame('Breakfast — Second Inn', $breakfast(3)->title);
        $this->assertNull($night(1)->note, 'no change on the first night');
        $this->assertStringContainsString('You change stays tonight', (string) $night(2)->note);
        $this->assertStringContainsString('First Inn', (string) $night(2)->note);
    }

    // ---- 10. the old, single-stay call keeps working ------------------------------------------------

    public function test_a_single_stay_for_the_whole_trip_still_works_as_before(): void
    {
        $inn = $this->stay('Only Inn', 7.07, 125.61);
        $stops = [$this->stop(7.07, 125.61), $this->stop(7.07, 125.61)];
        $preference = TouristPreference::create([
            'travel_days' => 2, 'travel_type' => 'Family', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'far',
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Davao City', 'start_date' => '2026-11-13', 'arrival_time' => '08:00',
        ]);
        $itinerary = Itinerary::create(['preference_id' => $preference->id, 'total_days' => 2, 'generated_at' => now()]);

        app(ItineraryScheduleBuilder::class)->build(
            $itinerary, $stops, $preference, [1 => ['Morning'], 2 => ['Morning']],
            ['lat' => 7.07, 'lng' => 125.61, 'label' => 'Start'], ['listing' => $inn, 'rule' => null],
        );

        $rows = $itinerary->items()->get();
        $this->assertSame(1, $rows->where('kind', 'overnight')->count());
        $this->assertSame($inn->id, $rows->firstWhere('kind', 'overnight')->accommodation_id);
        $this->assertNotNull($rows->firstWhere('title', 'Breakfast — Only Inn'));
    }
}
