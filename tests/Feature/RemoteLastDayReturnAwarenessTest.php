<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Services\Recommendation\ItineraryScheduleBuilder;
use App\Support\OmissionReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Refinement 1 (R1-a): On a Remote ('far') trip's final day, every candidate sightseeing stop — including the first
 * stop of the last day and stops on a one-day Remote trip — is checked against the projected home arrival time
 * (counting any post-visit lunch, the 15-minute departure buffer, and the dinner break a late return needs).
 * When the projected home arrival would reach or exceed 20:00, the stop is skipped with 'would_return_after_2000'.
 * Close Proximity ('near') and Commutable ('moderate') trips are untouched.
 */
class RemoteLastDayReturnAwarenessTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN_LAT = 7.0731;

    private const ORIGIN_LNG = 125.6128;

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

    private function region(): int
    {
        return Region::firstOrCreate(['name' => 'Davao City'])->id;
    }

    private function destination(string $name, float $northKm, float $rating = 4.5, ?string $hours = null): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(),
            'name' => $name,
            'location' => 'Davao City',
            'region_id' => $this->region(),
            'type' => 'Nature & Adventure',
            'is_accredited' => true,
            'rating' => $rating,
            'review_count' => 15,
            'price_tier' => 'Mid-range',
            'latitude' => self::ORIGIN_LAT + $northKm / 111.195,
            'longitude' => self::ORIGIN_LNG,
            'hours' => $hours,
        ]);
    }

    private function stay(string $name, float $northKm): Accommodation
    {
        $stay = Accommodation::create([
            'slug' => str($name)->slug()->toString(),
            'name' => $name,
            'location' => 'Davao City',
            'region_id' => $this->region(),
            'type' => 'Hotel',
            'is_accredited' => true,
            'rating' => 4.2,
            'review_count' => 10,
            'price_tier' => 'Mid-range',
            'latitude' => self::ORIGIN_LAT + $northKm / 111.195,
            'longitude' => self::ORIGIN_LNG,
        ]);

        AccreditationRecord::create([
            'listing_kind' => 'accommodation',
            'listing_id' => $stay->id,
            'accreditation_number' => 'DOT-R1-'.$stay->id,
            'issue_date' => '2024-01-01',
            'expiration_date' => '2028-12-31',
            'status' => 'Active',
        ]);

        return $stay;
    }

    private function preference(int $days, string $distancePref, ?string $arrival = '08:00'): TouristPreference
    {
        $preference = TouristPreference::create([
            'travel_days' => $days,
            'travel_type' => 'Couple',
            'budget' => 'Mid-range',
            'accommodation_pref' => 'Any',
            'distance_pref' => $distancePref,
            'travel_purpose' => 'Leisure',
            'visitor_type' => 'First-time Visitor',
            'place_of_origin' => 'Manila',
            'start_date' => '2026-10-28',
            'arrival_time' => $arrival,
            'origin_lat' => self::ORIGIN_LAT,
            'origin_lng' => self::ORIGIN_LNG,
            'origin_label' => 'Davao City centre',
        ]);

        PreferenceActivity::create([
            'preference_id' => $preference->id,
            'activity' => 'Nature & Adventure',
        ]);

        return $preference;
    }

    /**
     * @param  list<Destination>  $stops
     * @param  array<int, array<int, string>>  $capacities
     */
    private function buildSchedule(
        array $stops,
        TouristPreference $preference,
        array $capacities,
        ?Accommodation $stay = null,
    ): array {
        $itinerary = Itinerary::create([
            'preference_id' => $preference->id,
            'total_days' => count($capacities),
            'generated_at' => now(),
        ]);

        $sequence = array_map(
            fn (Destination $d) => ['row' => ['destination' => $d->load('region'), 'drs' => 4.0], 'distance_km' => 1.0],
            $stops,
        );

        $stayArg = $stay ? ['listing' => $stay, 'rule' => null] : null;
        $builder = app(ItineraryScheduleBuilder::class);
        $builder->forgetRecordedAttempts();

        $builder->build(
            $itinerary,
            $sequence,
            $preference,
            $capacities,
            ['lat' => self::ORIGIN_LAT, 'lng' => self::ORIGIN_LNG, 'label' => 'Davao City centre'],
            $stayArg,
        );

        return [
            'itinerary' => $itinerary->fresh('items'),
            'attempts' => $builder->recordedAttempts($sequence, $stayArg, $capacities),
        ];
    }

    private function placedNames(Itinerary $itinerary, ?int $day = null): array
    {
        $items = $itinerary->items->where('kind', 'activity')->whereNotNull('destination_id');
        if ($day !== null) {
            $items = $items->where('day_number', $day);
        }

        return $items->sortBy('sort_order')->map(fn ($item) => Destination::find($item->destination_id)->name)->values()->all();
    }

    private function departureTime(Itinerary $itinerary): ?string
    {
        return $itinerary->items->where('kind', 'departure')->sortBy('sort_order')->last()?->starts_at;
    }

    public function test_one_day_remote_itinerary_skips_single_stop_when_dinner_pushes_home_arrival_past_eight(): void
    {
        // 40 km north => 135 min (2 h 15 min) slow car drive each way.
        // Arrival 13:00 => ready 13:30 => arrives 15:45 => 90-min visit ends 17:15 (before 18:00 cutoff).
        // Depart 17:30 + 135 min raw drive = 19:45 (> 19:30), so planDinnerLeg adds 60-min dinner at 18:00,
        // bringing projected home arrival to 21:15 (>= 20:00).
        $stop = $this->destination('Forty Km Ridge', 40.0);
        $preference = $this->preference(1, 'far', '13:00');

        ['itinerary' => $itinerary, 'attempts' => $attempts] = $this->buildSchedule(
            [$stop],
            $preference,
            [1 => ['Afternoon']],
        );

        $this->assertSame([], $this->placedNames($itinerary), 'the stop that would get home at 21:15 is skipped');
        $this->assertLessThan('20:00:00', (string) $this->departureTime($itinerary));
        $this->assertSame('would_return_after_2000', $attempts[$stop->id][0]['cause'] ?? null);
        $this->assertSame(
            'On day 1 the journey back would have reached your starting point at or after 20:00.',
            OmissionReason::sentence(['attempts' => $attempts[$stop->id]]),
        );
    }

    public function test_projected_home_arrival_before_eight_retains_the_stop(): void
    {
        // Same 40 km stop (135 min each way), arriving 15 minutes earlier at 12:45:
        // ready 13:15 => arrives 15:30 => 90-min visit ends 17:00 => depart 17:15 + 135 min = 19:30 (<= 19:30, no dinner).
        // Projected home arrival is 19:30 (< 20:00), so the stop is retained and Departure is 19:30:00.
        $stop = $this->destination('Forty Km Ridge', 40.0);
        $preference = $this->preference(1, 'far', '12:45');

        ['itinerary' => $itinerary, 'attempts' => $attempts] = $this->buildSchedule(
            [$stop],
            $preference,
            [1 => ['Afternoon']],
        );

        $this->assertSame(['Forty Km Ridge'], $this->placedNames($itinerary));
        $this->assertSame('19:30:00', $this->departureTime($itinerary));
        $this->assertArrayNotHasKey($stop->id, $attempts ?? []);
    }

    public function test_last_day_with_multiple_stops_keeps_first_stop_and_skips_final_stop_that_returns_after_eight(): void
    {
        // Day 1 (last day):
        // Stop 1 at 30 km north (105 min): 08:30 -> 10:15, visit cut to 12:00 for lunch. Return home alone would be < 20:00.
        // Stop 2 at 60 km north (30 km further => 105 min leg; 60 km to origin => 195 min return):
        //   Total drive = 105 + 105 + 195 = 405 min <= 420 min Remote drive budget.
        //   Lunch 12:00-13:00, travel 13:00-14:45, 90-min visit 14:45-16:15 (before 18:00 cutoff).
        //   Depart 16:30 + 195 min = 19:45 (> 19:30) => + 60-min dinner => 20:45 home arrival (>= 20:00).
        $first = $this->destination('Midway Falls', 30.0, 4.8);
        $second = $this->destination('Outer Ridge', 60.0, 4.5);
        $preference = $this->preference(1, 'far', null);

        ['itinerary' => $itinerary, 'attempts' => $attempts] = $this->buildSchedule(
            [$first, $second],
            $preference,
            [1 => ['Morning', 'Afternoon']],
        );

        $this->assertSame(['Midway Falls'], $this->placedNames($itinerary), 'first stop is kept, second stop is skipped');
        $this->assertLessThan('20:00:00', (string) $this->departureTime($itinerary));
        $this->assertSame('would_return_after_2000', $attempts[$second->id][0]['cause'] ?? null);
    }

    public function test_first_stop_on_last_day_of_multi_day_remote_trip_is_evaluated_and_skipped_when_return_is_late(): void
    {
        // 2-day Remote trip. Night 1 is spent at Far Stay (75 km north).
        // On Day 2 (the last day), candidate 1 is Distant Coast (125 km north: 50 km / 165 min north of Far Stay,
        // 125 km / 405 min from origin). Even as the FIRST stop of Day 2 (exempt from the drive budget),
        // visiting Distant Coast on Day 2 would finish at 12:15, take lunch 12:15-13:15, depart 13:30,
        // and with 405 min drive + 60 min dinner arrive home at 21:15 (>= 20:00).
        // Candidate 2 in lookahead is Homeward Canyon (30 km north: 45 km / 150 min south of Far Stay, 105 min from origin),
        // which finishes in the early afternoon and reaches home well before 20:00.
        $day1Stop = $this->destination('North Base Trail', 70.0, 4.9);
        $distantLastDayFirst = $this->destination('Distant Coast', 125.0, 4.8);
        $homewardAlternative = $this->destination('Homeward Canyon', 30.0, 4.6);
        $farStay = $this->stay('North Highlands Inn', 75.0);

        $preference = $this->preference(2, 'far', '08:00');

        ['itinerary' => $itinerary, 'attempts' => $attempts] = $this->buildSchedule(
            [$day1Stop, $distantLastDayFirst, $homewardAlternative],
            $preference,
            [1 => ['Morning'], 2 => ['Morning', 'Afternoon']],
            $farStay,
        );

        $this->assertSame(['North Base Trail'], $this->placedNames($itinerary, 1));
        $this->assertSame(['Homeward Canyon'], $this->placedNames($itinerary, 2), 'the late first stop on day 2 is skipped in favour of the feasible lookahead stop');
        $this->assertLessThan('20:00:00', (string) $this->departureTime($itinerary));
        $day2Attempt = collect($attempts[$distantLastDayFirst->id] ?? [])->firstWhere('day', 2);
        $this->assertSame('would_return_after_2000', $day2Attempt['cause'] ?? null);
        $this->assertFalse($day2Attempt['fresh'] ?? true, 'fresh-morning drop does not overwrite the last-day return rejection');
    }

    public function test_last_day_front_stop_rejected_by_return_rule_ends_day_cleanly_when_no_alternative_fits(): void
    {
        $day1Stop = $this->destination('North Base Trail', 70.0, 4.9);
        $distantOnly = $this->destination('Distant Coast', 125.0, 4.8);
        $farStay = $this->stay('North Highlands Inn', 75.0);
        $preference = $this->preference(2, 'far', '08:00');

        ['itinerary' => $itinerary, 'attempts' => $attempts] = $this->buildSchedule(
            [$day1Stop, $distantOnly],
            $preference,
            [1 => ['Morning'], 2 => ['Morning', 'Afternoon']],
            $farStay,
        );

        $this->assertSame([], $this->placedNames($itinerary, 2));
        $this->assertLessThan('20:00:00', (string) $this->departureTime($itinerary));
        $day2Attempt = collect($attempts[$distantOnly->id] ?? [])->firstWhere('day', 2);
        $this->assertSame('would_return_after_2000', $day2Attempt['cause'] ?? null);
    }

    public function test_eighteen_hundred_sightseeing_cutoff_is_preserved_when_home_arrival_would_be_before_eight(): void
    {
        // 10 km north => 45 min drive each way.
        // Arrival 16:00 => ready 16:30 => arrives at stop at 17:15 => only 45 min left before 18:00 (< 90 min minimum).
        // Even though a 90-min visit (ending 18:45) + 45 min drive home would reach origin at 19:45,
        // the 18:00 sightseeing cutoff rejects the stop first with 'would_end_after_cutoff'.
        $nearStop = $this->destination('Close Hilltop', 10.0);
        $preference = $this->preference(1, 'far', '16:00');

        ['itinerary' => $itinerary, 'attempts' => $attempts] = $this->buildSchedule(
            [$nearStop],
            $preference,
            [1 => ['Afternoon']],
        );

        $this->assertSame([], $this->placedNames($itinerary));
        $this->assertSame('would_end_after_cutoff', $attempts[$nearStop->id][0]['cause'] ?? null);
    }

    public function test_close_proximity_and_commutable_trips_do_not_apply_the_remote_last_day_return_rule(): void
    {
        $stop = $this->destination('Forty Km Ridge', 40.0);

        foreach (['near', 'moderate'] as $band) {
            $preference = $this->preference(1, $band, '13:00');

            ['itinerary' => $itinerary] = $this->buildSchedule(
                [$stop],
                $preference,
                [1 => ['Afternoon']],
            );

            $this->assertSame(['Forty Km Ridge'], $this->placedNames($itinerary), "$band keeps the stop that only Remote skips");
            $this->assertSame('21:15:00', $this->departureTime($itinerary));
            $this->assertTrue(
                $itinerary->items->contains(fn ($item) => $item->kind === 'meal' && str_contains((string) $item->title, 'Dinner')),
                "$band still includes the dinner break on the late return",
            );
        }
    }

    public function test_full_generation_records_would_return_after_2000_in_stay_plan_notices_on_one_day_remote_trip(): void
    {
        $this->destination('Forty Km Ridge', 40.0, 4.8);
        $this->stay('City Hotel', 2.0);
        $preference = $this->preference(1, 'far', '13:00');

        $trip = app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'))->fresh('items');

        $this->assertSame([], $this->placedNames($trip));
        $notices = collect(data_get($trip->stay_plan, 'notices', []))->where('type', 'unplaced_destination')->values();
        $this->assertCount(1, $notices);
        $this->assertSame('would_return_after_2000', $notices[0]['reason']);
        $this->assertSame(
            'On day 1 the journey back would have reached your starting point at or after 20:00.',
            OmissionReason::sentence($notices[0]),
        );
    }

    public function test_stage_a_first_stop_on_any_day_cannot_exceed_480_minute_daily_driving_ceiling(): void
    {
        // Day 1 of a 2-day Remote trip starting at origin (0 km) with Night 1 stay at 20 km south (-20 km).
        // Candidate 1 ('Extreme North Peak') is 85 km north:
        //   leg(origin -> 85 km) = 285 min (arriving at 14:15 with lunch, finishing 120-min visit at 16:15 <= 18:00),
        //   back(85 km -> -20 km stay) = 345 min => total = 630 min (> 480 min ceiling).
        //   Previously the first-stop exemption allowed 630 min of driving on Day 1.
        // Candidate 2 ('Reachable Foothills') is 20 km north:
        //   leg(origin -> 20 km) = 75 min, back(20 km -> -20 km stay) = 135 min => total = 210 min (<= 480 min ceiling).
        $extremeStop = $this->destination('Extreme North Peak', 85.0, 4.9);
        $reachableStop = $this->destination('Reachable Foothills', 20.0, 4.6);
        $midStay = $this->stay('South Base Lodge', -20.0);

        $preference = $this->preference(2, 'far', '08:00');

        ['itinerary' => $itinerary, 'attempts' => $attempts] = $this->buildSchedule(
            [$extremeStop, $reachableStop],
            $preference,
            [1 => ['Morning'], 2 => ['Morning']],
            $midStay,
        );

        $this->assertSame(['Reachable Foothills'], $this->placedNames($itinerary, 1), 'Day 1 skips the >480m first stop and places the reachable stop');
        $day1Attempt = collect($attempts[$extremeStop->id] ?? [])->firstWhere('day', 1);
        $this->assertSame('drive_budget_reached', $day1Attempt['cause'] ?? null);
        $this->assertSame(480, $day1Attempt['limit'] ?? null);
        $this->assertGreaterThan(480, $day1Attempt['minutes'] ?? 0);
    }

    public function test_stage_a_final_night_stay_reanchors_when_final_day_becomes_empty_in_pass_zero(): void
    {
        // 3-day Remote trip with late Day-1 arrival (15:00 => 1 slot on Day 1, 2 on Day 2, 2 on Day 3).
        // Top stops: two stops near 45 km north (visited on Day 2 when Day 1 is empty due to travel time),
        // one stop at 30 km north (reachable on Day 3 from a nearby stay, ~105 min to origin),
        // and one distant stop at 115 km north (around which pre-pass plannedAnchors anchors Night 2's stay).
        $this->destination('Cluster Ridge A', 44.0, 4.9);
        $this->destination('Cluster Ridge B', 46.0, 4.8);
        $this->destination('Homeward Valley', 30.0, 4.7);
        $this->destination('Farthermost Summit', 115.0, 4.6);

        $nearClusterStay = $this->stay('Cluster Inn', 40.0);
        $farSummitStay = $this->stay('Summit Outpost', 115.0);

        $preference = $this->preference(3, 'far', '15:00');
        $trip = app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'))->fresh('items');

        $day3Stops = $this->placedNames($trip, 3);
        $this->assertNotEmpty($day3Stops, 'Final day is recovered after re-anchoring final-night stay');
        $day3Drive = (int) $trip->items->where('day_number', 3)->where('kind', 'travel')->sum('travel_max_minutes');
        $this->assertLessThanOrEqual(ItineraryScheduleBuilder::MAX_DAILY_DRIVE_MINUTES, $day3Drive);
        $night2StayId = $trip->items->where('day_number', 2)->where('kind', 'overnight')->first()?->accommodation_id;
        $this->assertSame($nearClusterStay->id, $night2StayId, 'Night 2 re-anchors away from the distant summit stay to the reachable cluster stay');
        $this->assertNotSame($farSummitStay->id, $night2StayId);
    }

    public function test_stage_b_day_one_promotes_the_first_reachable_stop_past_unreachable_ones(): void
    {
        // Arriving at 15:00, three stops ~70 km away cannot be reached and visited before 18:00 today (but could be
        // from a fresh morning), and the reachable stop sits fourth in the queue, beyond the usual 3-stop lookahead.
        $far = [
            $this->destination('Far Ridge One', 70.0),
            $this->destination('Far Ridge Two', 72.0),
            $this->destination('Far Ridge Three', 74.0),
        ];
        $near = $this->destination('Near Garden', 8.0);
        $stay = $this->stay('Garden Inn', 8.0);

        ['itinerary' => $itinerary] = $this->buildSchedule(
            [...$far, $near],
            $this->preference(2, 'far', '15:00'),
            [1 => ['Afternoon'], 2 => ['Morning', 'Afternoon']],
            $stay,
        );

        $this->assertSame(['Near Garden'], $this->placedNames($itinerary, 1), 'Day 1 is not left empty behind unreachable stops');
    }

    public function test_stage_b_day_one_widening_is_remote_only(): void
    {
        $far = [
            $this->destination('Far Ridge One', 70.0),
            $this->destination('Far Ridge Two', 72.0),
            $this->destination('Far Ridge Three', 74.0),
        ];
        $near = $this->destination('Near Garden', 8.0);
        $stay = $this->stay('Garden Inn', 8.0);

        ['itinerary' => $itinerary] = $this->buildSchedule(
            [...$far, $near],
            $this->preference(2, 'moderate', '15:00'),
            [1 => ['Afternoon'], 2 => ['Morning', 'Afternoon']],
            $stay,
        );

        $this->assertNotContains('Near Garden', $this->placedNames($itinerary, 1), 'Commutable trips keep the 3-stop lookahead');
    }

    public function test_stage_b_feasibility_cost_counts_empty_days_and_over_ceiling_driving(): void
    {
        $stop = $this->destination('Lone Stop', 8.0);
        $stay = $this->stay('Garden Inn', 8.0);

        ['itinerary' => $itinerary] = $this->buildSchedule(
            [$stop],
            $this->preference(3, 'far', '08:00'),
            [1 => ['Morning'], 2 => ['Morning'], 3 => ['Morning']],
            $stay,
        );

        $method = new \ReflectionMethod(ItineraryGenerationService::class, 'feasibilityCost');
        $method->setAccessible(true);
        $cost = $method->invoke(app(ItineraryGenerationService::class), $itinerary);

        // One stop over three days: Days 2 and 3 have no sightseeing stop.
        $this->assertGreaterThanOrEqual(2, $cost);
    }}
