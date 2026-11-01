<?php

namespace Tests\Feature;

use App\Console\Commands\FollowabilityReport;
use App\Http\Controllers\TripPlannerController;
use App\Models\Accommodation;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Support\ItineraryDayNotes;
use App\Support\ItineraryFollowability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The notes on a saved itinerary (arrival/transfer day, late check-in, lunch not arranged, long driving day) state facts
 * that are in the rows and never a reason; the followability report counts the same things per range and enforces nothing.
 */
class ClarityDayNotesTest extends TestCase
{
    use RefreshDatabase;

    private function stay(string $name = 'Big 8 Corporate Hotel'): Accommodation
    {
        return Accommodation::firstOrCreate(['slug' => str($name)->slug()->toString()], ['name' => $name, 'location' => 'Davao', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range', 'latitude' => 7.07, 'longitude' => 125.61]);
    }

    private function trip(int $days, string $range = 'moderate'): Itinerary
    {
        $pref = TouristPreference::create(['travel_days' => $days, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => $range,
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Cebu City', 'start_date' => '2026-10-28']);

        return Itinerary::create(['preference_id' => $pref->id, 'total_days' => $days, 'generated_at' => now()]);
    }

    private function row(Itinerary $trip, int $day, int $order, string $kind, string $title, ?string $from = null, ?string $to = null, array $extra = []): ItineraryItem
    {
        return ItineraryItem::create(array_merge(['itinerary_id' => $trip->id, 'day_number' => $day, 'sort_order' => $order, 'slot' => 'Morning', 'kind' => $kind, 'title' => $title,
            'starts_at' => $from, 'ends_at' => $to], $extra))->load('accommodation');
    }

    private function notesFor(Itinerary $trip): array
    {
        $byDay = $trip->items()->with('accommodation')->orderBy('day_number')->orderBy('sort_order')->get()->groupBy('day_number');

        return ItineraryDayNotes::forItinerary($byDay, (int) $trip->total_days);
    }

    // ---- arrival / transfer day ----------------------------------------------------------------------------

    public function test_a_first_day_that_is_only_the_journey_to_the_stay_says_so_with_facts_only(): void
    {
        $stay = $this->stay();
        $trip = $this->trip(3, 'far');
        $this->row($trip, 1, 1, 'baseline', 'Planned arrival', '15:00:00');
        $this->row($trip, 1, 2, 'travel', 'Travel to Big 8 Corporate Hotel', '15:30:00', null, ['travel_min_minutes' => 120, 'travel_max_minutes' => 165]);
        $this->row($trip, 1, 3, 'overnight', 'Overnight stay', '18:15:00', null, ['accommodation_id' => $stay->id]);
        $this->row($trip, 2, 1, 'activity', 'Sightseeing', '09:00:00', '11:30:00', ['destination_id' => null]);
        $this->row($trip, 3, 1, 'departure', 'Departure');

        $note = $this->notesFor($trip)[1][0];

        $this->assertSame('arrival_transfer', $note['type']);
        $this->assertSame('Day 1 is mainly the journey to your stay. You arrive at 3:00 PM and travel up to 2 h 45 min to Big 8 Corporate Hotel. Your first sightseeing stop is on day 2.', $note['text']);
        foreach (['because', 'cutoff', 'too late', 'no time'] as $claim) {
            $this->assertStringNotContainsStringIgnoringCase($claim, $note['text'], 'no explanation is invented');
        }
    }

    public function test_a_first_day_with_sightseeing_or_a_one_day_trip_gets_no_arrival_note(): void
    {
        $stay = $this->stay();
        $trip = $this->trip(2);
        $this->row($trip, 1, 1, 'travel', 'Travel', '09:00:00', null, ['travel_max_minutes' => 60]);
        $this->row($trip, 1, 2, 'activity', 'Sightseeing', '10:00:00', '12:30:00');
        $this->row($trip, 1, 3, 'overnight', 'Overnight stay', '19:00:00', null, ['accommodation_id' => $stay->id]);
        $this->assertArrayNotHasKey(1, $this->notesFor($trip));

        $one = $this->trip(1);
        $this->row($one, 1, 1, 'travel', 'Travel', '09:00:00', null, ['travel_max_minutes' => 60]);
        $this->assertSame([], $this->notesFor($one));
    }

    // ---- late check-in --------------------------------------------------------------------------------------

    public function test_a_late_check_in_names_the_time_and_the_last_journey(): void
    {
        $stay = $this->stay('Adelina Hotel and Suites');
        $trip = $this->trip(2, 'far');
        $this->row($trip, 1, 1, 'activity', 'Sightseeing', '09:00:00', '15:00:00');
        $this->row($trip, 1, 2, 'travel', 'Travel to Adelina Hotel and Suites', '15:15:00', null, ['travel_max_minutes' => 360]);
        $this->row($trip, 1, 3, 'overnight', 'Late check-in and overnight stay', '22:15:00', null, ['accommodation_id' => $stay->id]);

        $notes = $this->notesFor($trip)[1];

        $this->assertSame('late_checkin', $notes[0]['type']);
        $this->assertSame('You reach Adelina Hotel and Suites at 10:15 PM, after the usual dinner hours; the last journey is up to 6 h.', $notes[0]['text']);
    }

    public function test_a_check_in_after_midnight_is_still_called_late_without_inventing_a_time(): void
    {
        $stay = $this->stay('Dahican Beach Resort and Spa');
        $trip = $this->trip(2, 'far');
        $this->row($trip, 1, 1, 'activity', 'Sightseeing', '14:00:00', '16:30:00');
        $this->row($trip, 1, 2, 'travel', 'Travel to Dahican Beach Resort and Spa', '19:30:00', null, ['travel_max_minutes' => 270]);
        $this->row($trip, 1, 3, 'overnight', 'Late check-in and overnight stay', null, null, ['accommodation_id' => $stay->id, 'note' => 'This falls after midnight, so no set time is shown.']);

        $this->assertSame('You reach Dahican Beach Resort and Spa after midnight, so no set time is shown; the last journey is up to 4 h 30 min.', $this->notesFor($trip)[1][0]['text']);

        $unknown = $this->trip(2, 'far');
        $this->row($unknown, 1, 1, 'overnight', 'Late check-in and overnight stay', null, null, ['accommodation_id' => $stay->id]);
        $this->assertSame([], $this->notesFor($unknown), 'with no time and no note saying why, nothing is claimed');

        $figures = app(ItineraryFollowability::class)->analyse($trip, 'far');
        $this->assertSame(1, $figures['late_checkins']['count']);
        $this->assertSame(1, $figures['late_checkins']['after_midnight']);
    }

    // ---- lunch not arranged ---------------------------------------------------------------------------------

    public function test_free_time_that_covers_the_lunch_hours_on_a_sightseeing_day_without_lunch_says_lunch_is_not_arranged(): void
    {
        $trip = $this->trip(4);
        $this->row($trip, 3, 1, 'travel', 'Travel to Tudaya Falls', '08:00:00', null, ['travel_max_minutes' => 30]);
        $this->row($trip, 3, 2, 'activity', 'Nature and adventure activities', '08:30:00', '11:00:00');
        $this->row($trip, 3, 3, 'travel', 'Travel to Red Barn', '11:15:00', null, ['travel_max_minutes' => 30]);
        $this->row($trip, 3, 4, 'free', 'Free time until dinner', '11:45:00', '18:30:00');
        $this->row($trip, 3, 5, 'meal', 'Dinner — Red Barn', '18:30:00', '19:30:00');

        $note = $this->notesFor($trip)[3][0];

        $this->assertSame('lunch_not_arranged', $note['type']);
        $this->assertSame('Lunch is not planned on this day. The free time from 11:45 AM covers the usual lunch hours (11:30 AM to 1:00 PM), so lunch is yours to arrange.', $note['text']);
    }

    public function test_no_lunch_note_when_lunch_is_planned_or_when_the_day_started_after_the_lunch_hours(): void
    {
        $withLunch = $this->trip(2);
        $this->row($withLunch, 1, 1, 'activity', 'Sightseeing', '09:00:00', '11:30:00');
        $this->row($withLunch, 1, 2, 'meal', 'Lunch — Somewhere', '12:00:00', '13:00:00');
        $this->row($withLunch, 1, 3, 'free', 'Free time until dinner', '13:30:00', '18:30:00');
        $this->assertSame([], $this->notesFor($withLunch));

        $arrivedLate = $this->trip(1);
        $this->row($arrivedLate, 1, 1, 'baseline', 'Planned arrival', '13:30:00');
        $this->row($arrivedLate, 1, 2, 'activity', 'Sightseeing', '14:15:00', '16:45:00');
        $this->assertSame([], $this->notesFor($arrivedLate), 'the lunch hours had already passed; nothing is claimed');
    }

    // ---- the long driving day -------------------------------------------------------------------------------

    private function pageWithDriving(int $minutes): string
    {
        $stay = $this->stay();
        $trip = $this->trip(2, 'far');
        $this->row($trip, 1, 1, 'travel', 'Travel', '08:00:00', null, ['travel_max_minutes' => $minutes]);
        $this->row($trip, 1, 2, 'activity', 'Sightseeing', '12:00:00', '14:30:00');
        $this->row($trip, 1, 3, 'overnight', 'Overnight stay', '19:00:00', null, ['accommodation_id' => $stay->id]);
        $this->row($trip, 2, 1, 'departure', 'Departure');

        return $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $trip->preference_id])
            ->get(route('plan.itinerary'))->assertOk()->getContent();
    }

    public function test_a_day_over_eight_hours_of_driving_is_called_a_long_driving_day_and_a_shorter_one_keeps_the_gentler_tip(): void
    {
        $long = $this->pageWithDriving(500);
        $this->assertStringContainsString('Long driving day.', $long);
        $this->assertStringContainsString('This day has up to 8 h 20 min of travel', $long);

        $medium = $this->pageWithDriving(300);
        $this->assertStringNotContainsString('Long driving day.', $medium);
        $this->assertStringContainsString('This day has up to 5 h of travel', $medium, 'the existing tip from 4 h of travel is still there');

        $this->assertSame(480, ItineraryDayNotes::longDrivingMinutes());
    }

    public function test_the_notes_never_reach_the_recommendation_or_scheduling_code(): void
    {
        foreach (glob(app_path('Services/Recommendation/*.php')) as $file) {
            $source = file_get_contents($file);
            foreach (['itinerary.notes', 'ItineraryDayNotes', 'ItineraryFollowability', 'OmissionReason', 'long_driving_day_minutes'] as $name) {
                $this->assertStringNotContainsString($name, $source, basename($file).' must not read '.$name.': it is message-only');
            }
        }
    }

    // ---- the followability report -------------------------------------------------------------------------

    public function test_the_report_counts_one_trip_per_range_without_enforcing_anything(): void
    {
        $stay = $this->stay();
        $trip = $this->trip(3, 'far');
        $trip->update(['stay_plan' => ['version' => 1, 'mode' => 'far', 'continuity' => ['nights' => 2, 'distinct_stays' => 1, 'transfers' => 0], 'guard' => null, 'notices' => [
            ['type' => 'unplaced_destination', 'destination_id' => 9, 'name' => 'Faraway Falls', 'reason' => 'would_end_after_cutoff', 'attempts' => [['day' => 2, 'cause' => 'would_end_after_cutoff', 'minutes' => null, 'limit' => null, 'fresh' => false]]]]]]);
        $this->row($trip, 1, 1, 'baseline', 'Planned arrival', '15:00:00');
        $this->row($trip, 1, 2, 'travel', 'Travel', '15:30:00', null, ['travel_max_minutes' => 165]);
        $this->row($trip, 1, 3, 'overnight', 'Overnight stay', '18:15:00', null, ['accommodation_id' => $stay->id]);
        $this->row($trip, 2, 1, 'travel', 'Travel', '08:00:00', null, ['travel_max_minutes' => 540]);
        $this->row($trip, 2, 2, 'activity', 'Sightseeing', '17:00:00', '19:30:00', ['destination_id' => null]);
        $this->row($trip, 2, 3, 'overnight', 'Late check-in and overnight stay', '21:30:00', null, ['accommodation_id' => $stay->id]);
        $this->row($trip, 3, 1, 'departure', 'Departure');

        $figures = app(ItineraryFollowability::class)->analyse($trip, 'far');

        $this->assertSame(1, $figures['coverage']['omitted']);
        $this->assertSame(['would_end_after_cutoff' => 1], $figures['coverage']['causes']);
        $this->assertSame(540, $figures['driving']['longest']);
        $this->assertSame(1, $figures['driving']['days_over_long'], 'counted, not enforced');
        $this->assertSame(1, $figures['late_checkins']['count']);
        $this->assertSame(120, $figures['late_checkins']['minutes_late'], '21:30 is 120 minutes after 19:30');
        $this->assertSame(1, $figures['arrival_transfer_days']);
        $this->assertSame(0, $figures['continuity']['changes']);

        $summary = app(ItineraryFollowability::class)->summarise([$figures, $figures]);
        $this->assertSame(2, $summary['late_checkins']['count']);
        $this->assertSame(540, $summary['driving']['longest_day']);
    }

    public function test_the_command_wants_one_set_and_has_no_way_to_enforce_a_band(): void
    {
        $this->assertSame(1, Artisan::call('itinerary:followability'));
        $this->assertSame(1, Artisan::call('itinerary:followability', ['--set' => 'all']));

        $options = array_keys(app(FollowabilityReport::class)->getDefinition()->getOptions());
        $this->assertEqualsCanonicalizing(['set', 'json', 'details'], array_diff($options, ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env']));
    }

    public function test_the_two_sets_are_fixed_trips_that_never_overlap(): void
    {
        $calibration = array_map(fn ($i) => FollowabilityReport::scenario($i), range(0, 47));
        $holdout = array_map(fn ($i) => FollowabilityReport::scenario($i), range(48, 95));

        $this->assertSame($calibration, array_map(fn ($i) => FollowabilityReport::scenario($i), range(0, 47)), 'the same trips every run');
        $this->assertCount(1, array_unique(array_map(fn ($s) => $s['origin'][0], $calibration)), 'calibration trips all start at Ma-a');
        $this->assertCount(3, array_unique(array_map(fn ($s) => $s['origin'][0], $holdout)), 'held-out trips start at three places');
        $this->assertSame(['near', 'moderate', 'far'], array_slice(array_column($calibration, 'distance_pref'), 0, 3));
    }
}
