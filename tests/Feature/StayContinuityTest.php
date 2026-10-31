<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Where the traveller sleeps follows how far they said they are willing to go: Close Proximity keeps one stay for the
 * whole trip, Commutable keeps one unless a real comparison shows it is impractical, Remote may change where the
 * geography justifies it. The stay is chosen for the stops the schedule really placed, never for stops it dropped, and
 * only from stays accredited on the night in question. Nothing is dropped silently: what could not be placed is listed.
 */
class StayContinuityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 12:00:00');
        config(['dot.accreditation_test_grace_until' => null, 'itinerary.stays.force_mode' => null, 'itinerary.stays.transfer_penalty_minutes.far' => 30]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---- fixtures ------------------------------------------------------------------------------------

    private function region(): int
    {
        return Region::firstOrCreate(['name' => 'Davao City'])->id;
    }

    /** A place $km north of the starting point (7.07, 125.61). */
    private function place(string $name, float $km, float $rating = 4.0): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region(), 'type' => 'Nature & Leisure',
            'is_accredited' => true, 'rating' => $rating, 'review_count' => 20, 'price_tier' => 'Mid-range',
            'latitude' => 7.07 + $km / 111.2, 'longitude' => 125.61, 'distance_km' => $km,
        ]);
    }

    private function stay(string $name, float $km, string $expires = '2028-01-01', float $rating = 4.0): Accommodation
    {
        $stay = Accommodation::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region(), 'type' => 'Hotel',
            'is_accredited' => true, 'rating' => $rating, 'review_count' => 10, 'latitude' => 7.07 + $km / 111.2, 'longitude' => 125.61,
        ]);
        AccreditationRecord::create([
            'listing_kind' => 'accommodation', 'listing_id' => $stay->id, 'accreditation_number' => 'DOT-T-'.$stay->id,
            'issue_date' => '2024-01-01', 'expiration_date' => $expires, 'status' => 'Active',
        ]);

        return $stay;
    }

    private function preference(string $range, int $days, array $over = []): TouristPreference
    {
        return TouristPreference::create($over + [
            'travel_days' => $days, 'travel_type' => 'Family', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => $range,
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Davao City', 'start_date' => '2026-11-13',
            'arrival_time' => '08:00', 'origin_lat' => 7.07, 'origin_lng' => 125.61, 'origin_label' => 'Start',
        ]);
    }

    private function plan(string $range, int $days, array $over = []): Itinerary
    {
        return app(ItineraryGenerationService::class)->generate($this->preference($range, $days, $over)->load('activities', 'amenities'), 7.07, 125.61);
    }

    /** @return array<int, int> night (day number) => accommodation id */
    private function nights(Itinerary $trip): array
    {
        return $trip->items()->where('kind', 'overnight')->whereNotNull('accommodation_id')->orderBy('day_number')->pluck('accommodation_id', 'day_number')
            ->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<int, array<int, int>> day => destination ids */
    private function stops(Itinerary $trip): array
    {
        return $trip->items()->where('kind', 'activity')->whereNotNull('destination_id')->orderBy('day_number')->orderBy('sort_order')->get()
            ->groupBy('day_number')->map(fn ($rows) => $rows->pluck('destination_id')->map(fn ($id) => (int) $id)->all())->all();
    }

    private function manyPlaces(array $kms): void
    {
        foreach ($kms as $i => $km) {
            $this->place('Place '.($i + 1).' ('.$km.' km)', $km, 4.8 - $i * 0.1);
        }
    }

    // ---- Close Proximity: strict -----------------------------------------------------------------------

    public function test_close_proximity_keeps_the_same_stay_on_every_night(): void
    {
        $this->manyPlaces([3, 6, 9, 12, 15, 18]);
        $this->stay('Stay A', 5);
        $this->stay('Stay B', 12);
        $this->stay('Stay C', 19);

        $trip = $this->plan('near', 3);
        $nights = $this->nights($trip);

        $this->assertCount(2, $nights, 'a three-day trip has two nights');
        $this->assertCount(1, array_unique($nights), 'one stay for the whole trip');
        $this->assertSame('near', $trip->stay_plan['mode']);
        $this->assertSame(0, $trip->stay_plan['continuity']['transfers']);
        $this->assertSame(1, $trip->stay_plan['continuity']['distinct_stays']);
    }

    public function test_the_stay_plan_is_versioned_and_does_not_copy_what_the_rows_already_say(): void
    {
        $this->manyPlaces([3, 6, 9, 12]);
        $this->stay('Stay A', 5);

        $plan = $this->plan('near', 2)->fresh()->stay_plan;

        $this->assertSame(1, $plan['version']);
        $this->assertSame(['version', 'mode', 'continuity', 'notices', 'guard'], array_keys($plan));
        $this->assertArrayNotHasKey('stays', $plan, 'the stay of each night is on the overnight rows');
    }

    // ---- Commutable: preferred ---------------------------------------------------------------------------

    public function test_commutable_keeps_one_stay_when_that_is_practical(): void
    {
        $this->manyPlaces([10, 13, 16, 40, 43, 46]);
        $this->stay('City Stay', 12);
        $this->stay('Far Stay', 43);

        $trip = $this->plan('moderate', 4);
        $nights = $this->nights($trip);

        $this->assertCount(3, $nights);
        $this->assertCount(1, array_unique($nights), 'a saving in travelling alone does not change the stay');
        $this->assertSame('moderate', $trip->stay_plan['mode']);
        $this->assertLessThanOrEqual(config('itinerary.stays.max_extra_builds'), $trip->stay_plan['guard']['extra_builds']);
    }

    // ---- Remote: flexible, but not every day ----------------------------------------------------------------

    public function test_remote_changes_stay_only_between_far_apart_areas_and_not_every_day(): void
    {
        $this->manyPlaces([6, 8, 10, 12]);
        foreach ([100, 102, 104, 106] as $i => $km) {
            $this->place('Northern '.($i + 1), $km, 4.2 - $i * 0.1);
        }
        $this->stay('City Stay', 9);
        $this->stay('Northern Stay', 103);

        $trip = $this->plan('far', 5);
        $nights = array_values($this->nights($trip));

        $this->assertCount(4, $nights);
        $this->assertSame(1, $trip->stay_plan['continuity']['transfers'], 'one change for two areas, never a change a day');
        $this->assertSame([$nights[0], $nights[0], $nights[3], $nights[3]], $nights, 'two nights in each area');
        $this->assertSame('City Stay', Accommodation::find($nights[0])->name);
        $this->assertSame('Northern Stay', Accommodation::find($nights[3])->name);
    }

    // ---- the stay is chosen for the stops that were placed ---------------------------------------------------

    public function test_the_stay_mode_never_changes_which_destinations_are_ranked(): void
    {
        $this->manyPlaces([4, 8, 12, 16, 20, 24]);
        $this->stay('Stay A', 8);

        config(['itinerary.stays.force_mode' => 'near']);
        $near = $this->plan('far', 3);
        config(['itinerary.stays.force_mode' => 'far']);
        $far = $this->plan('far', 3);

        $rank = fn (Itinerary $t) => $t->matches()->orderBy('rank')->pluck('destination_id')->all();
        $this->assertSame($rank($near), $rank($far), 'Content-Based ranking is untouched by how stays are chosen');
    }

    public function test_the_same_inputs_give_the_same_stops_and_stays(): void
    {
        $this->manyPlaces([4, 8, 12, 16, 20, 24]);
        $this->stay('Stay A', 8);
        $this->stay('Stay B', 20);

        $first = $this->plan('moderate', 4);
        $second = $this->plan('moderate', 4);
        $third = $this->plan('moderate', 4);

        $this->assertSame($this->stops($first), $this->stops($second));
        $this->assertSame($this->stops($first), $this->stops($third));
        $this->assertSame($this->nights($first), $this->nights($second));
        $this->assertSame($this->nights($first), $this->nights($third));
    }

    public function test_the_schedule_rules_still_hold(): void
    {
        $this->manyPlaces([3, 6, 9, 12, 15, 18, 21, 24]);
        $this->stay('Stay A', 10);

        $trip = $this->plan('near', 4, ['arrival_time' => '10:15']);
        $items = $trip->items()->orderBy('day_number')->orderBy('sort_order')->get();

        foreach ($items->where('kind', 'activity') as $row) {
            $this->assertLessThanOrEqual('18:00', substr((string) $row->ends_at, 0, 5), 'no sightseeing after the 18:00 cutoff');
        }
        $firstLeg = $items->where('day_number', 1)->whereIn('kind', ['travel', 'activity'])->sortBy('sort_order')->first();
        $this->assertGreaterThanOrEqual('10:45', substr((string) $firstLeg->starts_at, 0, 5), 'depart no earlier than arrival plus 30 minutes');
        $this->assertSame($items->where('kind', 'activity')->pluck('destination_id')->unique()->count(), $items->where('kind', 'activity')->count(), 'no place twice');
    }

    // ---- accreditation: never an expired or invalid stay --------------------------------------------------------

    public function test_a_stay_is_offered_only_for_nights_it_is_accredited_on_and_the_gap_is_named(): void
    {
        $this->manyPlaces([3, 6, 9, 12, 15, 18]);
        // Accredited through the first night (13 Nov) only.
        $this->stay('Short Stay', 8, '2026-11-13');

        $trip = $this->plan('near', 3)->fresh();

        $this->assertSame([1], array_keys($this->nights($trip)), 'a stay for night 1 only');
        $this->assertContains(['type' => 'no_stay_for_night', 'night' => 2], $trip->stay_plan['notices']);
    }

    public function test_with_no_accredited_stay_for_the_dates_none_is_offered_and_the_page_says_so(): void
    {
        $this->manyPlaces([3, 6, 9, 12, 15, 18]);
        // Both accreditations ended long before the trip: nothing may be recommended, whatever the fallback used to do.
        $this->stay('Lapsed One', 5, '2026-10-31');
        $this->stay('Lapsed Two', 9, '2026-10-31');

        $trip = $this->plan('near', 3, ['start_date' => '2030-01-01'])->fresh();

        $this->assertSame([], $this->nights($trip), 'no expired stay is booked');
        $this->assertSame(0, ItineraryItem::where('itinerary_id', $trip->id)->whereNotNull('accommodation_id')->count());
        $this->assertCount(2, array_filter($trip->stay_plan['notices'], fn ($n) => $n['type'] === 'no_stay_for_night'));

        $html = $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $trip->preference_id])
            ->get(route('plan.itinerary'))->assertOk()->getContent();
        $this->assertStringContainsString('No DOT-accredited accommodation is available for your trip.', $html);
        $this->assertStringNotContainsString('Lapsed One', $html);
    }

    public function test_the_test_grace_period_still_covers_the_nights_it_is_meant_to(): void
    {
        $this->manyPlaces([3, 6, 9, 12, 15, 18]);
        $this->stay('Grace Stay', 8, '2026-10-31');
        config(['dot.accreditation_test_grace_until' => '2026-12-31']);

        $this->assertCount(2, $this->nights($this->plan('near', 3)), 'the existing grace setting is respected');

        $this->assertSame([], $this->nights($this->plan('near', 3, ['start_date' => '2027-01-10'])), 'and does not reach past its last day');
    }

    // ---- the notice page ------------------------------------------------------------------------------------

    public function test_the_page_lists_what_could_not_be_placed_and_why_for_close_proximity(): void
    {
        $pref = $this->preference('near', 3);
        $trip = Itinerary::create(['preference_id' => $pref->id, 'total_days' => 3, 'generated_at' => now(), 'stay_plan' => [
            'version' => 1, 'mode' => 'near', 'continuity' => ['nights' => 2, 'distinct_stays' => 1, 'transfers' => 0],
            'notices' => [
                ['type' => 'unplaced_destination', 'destination_id' => 1, 'name' => 'Faraway Falls', 'reason' => 'drive_budget_reached',
                    'attempts' => [['day' => 2, 'cause' => 'drive_budget_reached', 'minutes' => 360, 'limit' => 210, 'fresh' => false]]],
                ['type' => 'unplaced_destination', 'destination_id' => 2, 'name' => 'Late Lagoon', 'reason' => 'would_end_after_cutoff',
                    'attempts' => [['day' => 2, 'cause' => 'would_end_after_cutoff', 'minutes' => null, 'limit' => null, 'fresh' => false]]],
            ],
            'guard' => ['decision' => 'not_needed'],
        ]]);
        ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => 1, 'sort_order' => 1, 'slot' => 'Evening', 'kind' => 'overnight', 'title' => 'Overnight stay', 'starts_at' => '20:00:00']);
        ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => 2, 'sort_order' => 1, 'slot' => 'Evening', 'kind' => 'overnight', 'title' => 'Overnight stay', 'starts_at' => '20:00:00']);
        ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => 3, 'sort_order' => 1, 'slot' => 'Morning', 'kind' => 'departure', 'title' => 'Departure']);

        $html = $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $pref->id])
            ->get(route('plan.itinerary'))->assertOk()->getContent();

        $this->assertStringContainsString('2 places we set out to include could not be fitted into your plan.', $html);
        $this->assertStringContainsString('keeps the same place to stay for the whole trip', $html);
        $this->assertStringContainsString('Faraway Falls', $html);
        $this->assertStringContainsString('On day 2 it would have taken the day to about 6 h of driving, over the 3 h 30 min limit for your range.', $html);
        $this->assertStringContainsString('Late Lagoon', $html);
        $this->assertStringContainsString('On day 2 the visit would have ended after the 18:00 sightseeing cutoff.', $html);
    }

    public function test_an_itinerary_made_before_the_column_existed_still_renders(): void
    {
        $pref = $this->preference('near', 2);
        $trip = Itinerary::create(['preference_id' => $pref->id, 'total_days' => 2, 'generated_at' => now()]);
        ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => 1, 'sort_order' => 1, 'slot' => 'Evening', 'kind' => 'overnight', 'title' => 'Overnight stay', 'starts_at' => '20:00:00']);

        $this->assertNull($trip->fresh()->stay_plan);
        $html = $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $pref->id])
            ->get(route('plan.itinerary'))->assertOk()->getContent();
        $this->assertStringNotContainsString('could not be fitted into your plan', $html);
    }

    public function test_unplaced_places_carry_the_reasons_the_builder_recorded_and_nothing_else(): void
    {
        $stay = $this->stay('Home Base', 5);
        $near = $this->place('Close Cove', 8);
        $far = $this->place('Distant Peak', 150);
        $final = ['placed' => [], 'stays' => [1 => $stay->id, 2 => $stay->id]];
        $planned = [['row' => ['destination' => $near->load('region')], 'distance_km' => null], ['row' => ['destination' => $far->load('region')], 'distance_km' => null]];
        $recorded = [$near->id => [['day' => 2, 'cause' => 'would_end_after_cutoff', 'minutes' => null, 'limit' => null, 'fresh' => false]]];

        $method = new \ReflectionMethod(ItineraryGenerationService::class, 'stayNotices');
        $notices = collect($method->invoke(app(ItineraryGenerationService::class), $final, $planned, 3, 'near', $recorded))->keyBy('name');

        $this->assertSame('would_end_after_cutoff', $notices['Close Cove']['reason']);
        $this->assertSame($recorded[$near->id], $notices['Close Cove']['attempts']);
        $this->assertSame('not_recorded', $notices['Distant Peak']['reason'], 'no recorded decision, so no cause is claimed, however far it is');
        $this->assertSame([], $notices['Distant Peak']['attempts']);
    }

    // ---- the guarded rebuild, with a stand-in schedule so each branch can be forced --------------------------------

    /**
     * @param  array<int, array<int, Destination>>  $placed  day => stops the stand-in schedule places
     * @param  array<int, Accommodation>  $initial  night => stay used by the first build
     * @param  callable|null  $dropFor  given the stays of a build, true to make that build lose its last stop
     * @return array{trip: Itinerary, plan: array, calls: int, stays: array}
     */
    private function settle(string $range, array $placed, array $initial, array $planned, ?callable $dropFor = null): array
    {
        $days = count($placed);
        $preference = $this->preference($range, $days);
        $trip = Itinerary::create(['preference_id' => $preference->id, 'total_days' => $days, 'generated_at' => now()]);
        $calls = 0;
        $stays = array_map(fn (Accommodation $s) => ['listing' => $s, 'rule' => null], $initial);

        $build = function (array $stops) use (&$stays, &$calls, $trip, $placed, $days, $dropFor): void {
            $calls++;
            $drop = $dropFor && $dropFor($stays);
            $order = 0;
            foreach (range(1, $days) as $day) {
                $todays = $placed[$day];
                if ($drop && $day === $days) {
                    $todays = [];
                }
                foreach ($todays as $destination) {
                    ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => $day, 'sort_order' => ++$order, 'slot' => 'Morning', 'kind' => 'activity',
                        'title' => 'Visit — '.$destination->name, 'starts_at' => '10:00:00', 'ends_at' => '12:00:00', 'destination_id' => $destination->id]);
                }
                ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => $day, 'sort_order' => ++$order, 'slot' => 'Afternoon', 'kind' => 'travel', 'title' => 'Travel',
                    'starts_at' => '12:00:00', 'travel_min_minutes' => 30, 'travel_max_minutes' => 60]);
                if ($day < $days && isset($stays[$day])) {
                    ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => $day, 'sort_order' => ++$order, 'slot' => 'Evening', 'kind' => 'overnight',
                        'title' => 'Overnight stay', 'starts_at' => '20:00:00', 'accommodation_id' => $stays[$day]['listing']->id]);
                }
            }
        };
        $build([]);
        $calls = 0;

        $caps = array_map(fn () => ['Morning', 'Afternoon'], $placed);
        $method = new \ReflectionMethod(ItineraryGenerationService::class, 'settleStays');
        $args = [$trip, $build, &$stays, [], $planned, $preference, $caps, ['lat' => 7.07, 'lng' => 125.61]];
        $plan = $method->invokeArgs(app(ItineraryGenerationService::class), $args);

        return ['trip' => $trip->fresh(), 'plan' => $plan, 'calls' => $calls, 'stays' => $stays];
    }

    private function entry(Destination $d): array
    {
        return ['row' => ['destination' => $d->load('region')], 'distance_km' => null];
    }

    public function test_the_stay_follows_the_stops_that_were_placed_and_not_one_that_was_dropped(): void
    {
        [$a, $b, $c] = [$this->place('Placed A', 5), $this->place('Placed B', 6), $this->place('Placed C', 5.5)];
        $dropped = $this->place('Dropped Far', 60);
        $nearStay = $this->stay('Near Stay', 5.5);
        $farStay = $this->stay('Far Stay', 60);

        // The first plan was made for the stops the trip set out to visit, including the far one, so it chose the far stay.
        $r = $this->settle('near', [1 => [$a], 2 => [$b], 3 => [$c]], [1 => $farStay, 2 => $farStay], array_map([$this, 'entry'], [$a, $dropped, $b, $c]));

        $this->assertSame('accepted', $r['plan']['guard']['decision']);
        $this->assertSame(1, $r['plan']['guard']['extra_builds']);
        $this->assertSame([1 => $nearStay->id, 2 => $nearStay->id], $this->nights($r['trip']), 'both nights at the stay beside the stops that were visited');
        $this->assertLessThan($r['plan']['guard']['first']['journey_minutes'], $r['plan']['guard']['final']['journey_minutes']);
        $this->assertSame(['Dropped Far'], collect($r['plan']['notices'])->where('type', 'unplaced_destination')->pluck('name')->all(), 'the dropped stop is named, not hidden');
    }

    public function test_a_rebuild_that_loses_a_stop_is_rejected_and_the_first_build_is_restored(): void
    {
        [$a, $b, $c] = [$this->place('Placed A', 5), $this->place('Placed B', 6), $this->place('Placed C', 5.5)];
        $nearStay = $this->stay('Near Stay', 5.5);
        $farStay = $this->stay('Far Stay', 60);

        $r = $this->settle('near', [1 => [$a], 2 => [$b], 3 => [$c]], [1 => $farStay, 2 => $farStay], array_map([$this, 'entry'], [$a, $b, $c]),
            dropFor: fn (array $stays) => $stays[1]['listing']->id === $nearStay->id);

        $this->assertSame('rejected', $r['plan']['guard']['decision']);
        $this->assertStringStartsWith('H2', $r['plan']['guard']['failed']);
        $this->assertSame([1 => $farStay->id, 2 => $farStay->id], $this->nights($r['trip']), 'the first build is back exactly as it was');
        $this->assertSame(3, $r['trip']->items()->where('kind', 'activity')->count(), 'no stop was lost');
    }

    public function test_a_rebuild_that_is_no_better_keeps_the_first_build(): void
    {
        [$a, $b, $c] = [$this->place('Placed A', 5), $this->place('Placed B', 6), $this->place('Placed C', 5.5)];
        $first = $this->stay('Stay One', 5.5, rating: 3.0);
        $this->stay('Stay Two', 5.5, rating: 4.9);

        // Two stays in the same place: the better-rated one would be picked again, but it is not better by the tolerance.
        $r = $this->settle('near', [1 => [$a], 2 => [$b], 3 => [$c]], [1 => $first, 2 => $first], array_map([$this, 'entry'], [$a, $b, $c]));

        $this->assertSame('no_gain', $r['plan']['guard']['decision']);
        $this->assertSame([1 => $first->id, 2 => $first->id], $this->nights($r['trip']));
    }

    public function test_the_number_of_extra_builds_is_bounded_even_for_a_commutable_comparison(): void
    {
        [$a, $b, $c, $d] = [$this->place('Area One A', 10), $this->place('Area One B', 12), $this->place('Area Two A', 70), $this->place('Area Two B', 72)];
        $one = $this->stay('Stay One', 11);
        $this->stay('Stay Two', 71);

        $r = $this->settle('moderate', [1 => [$a], 2 => [$b], 3 => [$c], 4 => [$d]], [1 => $one, 2 => $one, 3 => $one], array_map([$this, 'entry'], [$a, $b, $c, $d]));

        $this->assertLessThanOrEqual(2, $r['calls'], 'at most one rebuild and one comparison build');
        $this->assertSame($r['calls'], $r['plan']['guard']['extra_builds']);
        $this->assertContains($r['plan']['guard']['decision'], ['kept_one_stay', 'accepted_flexible', 'accepted', 'no_gain', 'not_needed']);
    }
}
