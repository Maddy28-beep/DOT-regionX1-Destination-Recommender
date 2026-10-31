<?php

namespace Tests\Feature;

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
 * The one path the 48-trip audit never exercised: a Commutable trip changing stay because keeping one is impractical.
 *
 * A five-day trip: three days in one area, then two days 35 km away. Both hotels remain inside the local 40 km stay limit.
 * Staying in the first area all trip cannot reach the last
 * day's stop (the stand-in schedule below loses it, as the real one does when the drive back is over the daily limit);
 * moving for the last night can. Each test forces one branch of the decision with a stand-in schedule, so the outcome does
 * not depend on the data: the real settle logic, the real evaluator, the real stay planner.
 */
class StayCommutableTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 12:00:00');
        config(['dot.accreditation_test_grace_until' => null, 'itinerary.stays.force_mode' => null, 'itinerary.stays.transfer_penalty_minutes.far' => 30, 'itinerary.stays.max_extra_builds' => 2]);
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

    private function place(string $name, float $km): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region(), 'type' => 'Nature & Leisure',
            'is_accredited' => true, 'rating' => 4, 'review_count' => 20, 'latitude' => 7.07 + $km / 111.2, 'longitude' => 125.61, 'distance_km' => $km,
        ]);
    }

    private function stay(string $name, float $km): Accommodation
    {
        $stay = Accommodation::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region(), 'type' => 'Hotel',
            'is_accredited' => true, 'rating' => 4, 'review_count' => 10, 'latitude' => 7.07 + $km / 111.2, 'longitude' => 125.61,
        ]);
        AccreditationRecord::create(['listing_kind' => 'accommodation', 'listing_id' => $stay->id, 'accreditation_number' => 'DOT-T-'.$stay->id, 'issue_date' => '2024-01-01', 'expiration_date' => '2028-01-01', 'status' => 'Active']);

        return $stay;
    }

    private function entry(Destination $d): array
    {
        return ['row' => ['destination' => $d->load('region')], 'distance_km' => null];
    }

    /** @return array{areaOne: Destination[], areaTwo: Destination[], home: Accommodation, away: Accommodation, placed: array, planned: array} */
    private function scenario(): array
    {
        $areaOne = [$this->place('Area One A', 5), $this->place('Area One B', 6), $this->place('Area One C', 5.5)];
        $areaTwo = [$this->place('Area Two A', 35), $this->place('Area Two B', 36)];

        return [
            'areaOne' => $areaOne, 'areaTwo' => $areaTwo, 'home' => $this->stay('Home Stay', 5.5), 'away' => $this->stay('Away Stay', 35.5),
            'placed' => [1 => [$areaOne[0]], 2 => [$areaOne[1]], 3 => [$areaOne[2]], 4 => [$areaTwo[0]], 5 => [$areaTwo[1]]],
            'planned' => array_map([$this, 'entry'], [...$areaOne, ...$areaTwo]),
        ];
    }

    /**
     * Runs the real settle step. $dropDays is given the stays of a build and returns the days whose stops that build loses.
     *
     * @return array{trip: Itinerary, plan: array, builds: int}
     */
    private function settle(string $range, array $s, callable $dropDays): array
    {
        $days = 5;
        $preference = TouristPreference::create([
            'travel_days' => $days, 'travel_type' => 'Family', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => $range,
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Davao City', 'start_date' => '2026-11-13', 'arrival_time' => '08:00',
        ]);
        $trip = Itinerary::create(['preference_id' => $preference->id, 'total_days' => $days, 'generated_at' => now()]);
        $builds = 0;
        $stays = array_map(fn () => ['listing' => $s['home'], 'rule' => null], array_flip(range(1, $days - 1)));

        $build = function (array $stops) use (&$stays, &$builds, $trip, $s, $days, $dropDays): void {
            $builds++;
            $dropped = $dropDays($stays);
            $order = 0;
            foreach (range(1, $days) as $day) {
                foreach ($dropped && in_array($day, $dropped, true) ? [] : $s['placed'][$day] as $destination) {
                    ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => $day, 'sort_order' => ++$order, 'slot' => 'Morning', 'kind' => 'activity',
                        'title' => 'Visit — '.$destination->name, 'starts_at' => '10:00:00', 'ends_at' => '12:00:00', 'destination_id' => $destination->id]);
                }
                ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => $day, 'sort_order' => ++$order, 'slot' => 'Afternoon', 'kind' => 'travel', 'title' => 'Travel',
                    'starts_at' => '12:00:00', 'ends_at' => '13:00:00', 'travel_min_minutes' => 30, 'travel_max_minutes' => 60]);
                if ($day < $days && isset($stays[$day])) {
                    ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => $day, 'sort_order' => ++$order, 'slot' => 'Evening', 'kind' => 'overnight',
                        'title' => 'Overnight stay', 'starts_at' => '20:00:00', 'accommodation_id' => $stays[$day]['listing']->id]);
                }
            }
        };
        $build([]);
        $builds = 0;

        $caps = array_fill(1, $days, ['Morning', 'Afternoon']);
        $method = new \ReflectionMethod(ItineraryGenerationService::class, 'settleStays');
        $args = [$trip, $build, &$stays, $s['planned'], $s['planned'], $preference, $caps, ['lat' => 7.07, 'lng' => 125.61]];
        $plan = $method->invokeArgs(app(ItineraryGenerationService::class), $args);

        return ['trip' => $trip->fresh(), 'plan' => $plan, 'builds' => $builds];
    }

    /** @return array<int, int> night => stay id */
    private function nights(Itinerary $trip): array
    {
        return $trip->items()->where('kind', 'overnight')->orderBy('day_number')->pluck('accommodation_id', 'day_number')->map(fn ($id) => (int) $id)->all();
    }

    // ---- the branch the audit never reached ----------------------------------------------------------------

    public function test_commutable_changes_stay_when_one_stay_loses_a_destination_the_flexible_plan_places(): void
    {
        $s = $this->scenario();

        // Staying at the home stay for the last night loses the last day's stop; sleeping away places it.
        $r = $this->settle('moderate', $s, fn (array $stays) => $stays[4]['listing']->id === $s['home']->id ? [5] : []);

        $this->assertSame('accepted_flexible', $r['plan']['guard']['decision'], json_encode($r['plan']['guard']));
        $this->assertStringContainsString('misses a destination', $r['plan']['guard']['failed']);
        $this->assertSame([1 => $s['home']->id, 2 => $s['home']->id, 3 => $s['home']->id, 4 => $s['away']->id], $this->nights($r['trip']), 'one change, before the last night');
        $this->assertSame(1, $r['plan']['continuity']['transfers']);
        $this->assertSame(5, $r['trip']->items()->where('kind', 'activity')->count(), 'every stop is placed again');
        $this->assertSame([], collect($r['plan']['notices'])->where('type', 'unplaced_destination')->all(), 'nothing is left unplaced');
        $this->assertSame(1, $r['plan']['guard']['extra_builds']);
        $this->assertLessThanOrEqual(2, $r['builds']);
        $this->assertLessThan($r['plan']['guard']['first']['cost'], $r['plan']['guard']['final']['cost'], 'and the whole itinerary costs less');
    }

    public function test_commutable_keeps_one_stay_when_the_flexible_plan_only_saves_travelling(): void
    {
        $s = $this->scenario();

        // Both versions place every stop; moving would save journey minutes, which alone is not a reason to change stay.
        $r = $this->settle('moderate', $s, fn (array $stays) => []);

        $this->assertSame('kept_one_stay', $r['plan']['guard']['decision']);
        $this->assertSame(array_fill(1, 4, $s['home']->id), $this->nights($r['trip']));
        $this->assertSame(0, $r['plan']['continuity']['transfers']);
        $this->assertLessThanOrEqual(2, $r['builds']);
    }

    public function test_a_flexible_plan_that_breaks_a_hard_constraint_is_not_taken_even_when_one_stay_is_impractical(): void
    {
        $s = $this->scenario();

        // The one-stay version loses the last stop, but the flexible version loses the first one: nothing placed may be lost.
        $r = $this->settle('moderate', $s, fn (array $stays) => $stays[4]['listing']->id === $s['home']->id ? [5] : [1]);

        $this->assertSame('kept_one_stay', $r['plan']['guard']['decision']);
        $this->assertStringStartsWith('H2', $r['plan']['guard']['failed']);
        $this->assertSame(array_fill(1, 4, $s['home']->id), $this->nights($r['trip']), 'the first build is restored exactly');
        $this->assertSame(4, $r['trip']->items()->where('kind', 'activity')->count());
    }

    public function test_close_proximity_never_changes_stay_in_the_same_situation_and_names_the_stop_it_could_not_place(): void
    {
        $s = $this->scenario();

        $r = $this->settle('near', $s, fn (array $stays) => $stays[4]['listing']->id === $s['home']->id ? [5] : []);

        $this->assertSame(array_fill(1, 4, $s['home']->id), $this->nights($r['trip']), 'strict: one stay, whatever it costs');
        $this->assertSame(0, $r['plan']['continuity']['transfers']);
        $this->assertSame(0, $r['plan']['guard']['extra_builds'], 'no alternative stay plan is even tried');
        $unplaced = collect($r['plan']['notices'])->where('type', 'unplaced_destination')->values();
        $this->assertSame(['Area Two B'], $unplaced->pluck('name')->all(), 'the stop that could not be placed is named');
        $this->assertSame('not_recorded', $unplaced[0]['reason'], 'this stand-in schedule records no decisions, so no cause is claimed');
    }
}
