<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Services\Recommendation\ItineraryScheduleBuilder;
use App\Services\Recommendation\ThemeDayGrouper;
use App\Support\OmissionReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Why a place was left out of a trip: the reason is the one the schedule builder RECORDED when it turned the place away,
 * the page says only that, and a place with no recorded reason gets the neutral "It was not added to your plan."
 * Remote trips list their omissions too. A one-day trip is told as well.
 */
class ClarityOmissionReasonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Notice characterization uses deliberately incomplete plans, not the default rejection flow.
        config(['itinerary.planner.reject_incomplete_trips' => false]);

        Carbon::setTestNow('2026-10-20 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---- the sentence ---------------------------------------------------------------------------------------

    public function test_each_recorded_cause_is_put_in_plain_words(): void
    {
        $cutoff = OmissionReason::sentence(['attempts' => [['day' => 2, 'cause' => 'would_end_after_cutoff', 'minutes' => null, 'limit' => null, 'fresh' => false]]]);
        $this->assertSame('On day 2 the visit would have ended after the 18:00 sightseeing cutoff.', $cutoff);

        $budget = OmissionReason::sentence(['attempts' => [['day' => 3, 'cause' => 'drive_budget_reached', 'minutes' => 345, 'limit' => 300, 'fresh' => false]]]);
        $this->assertSame('On day 3 it would have taken the day to about 5 h 45 min of driving, over the 5 h limit for your range.', $budget);

        $this->assertSame('Days 1 and 2 already held 2 places.', OmissionReason::sentence(['attempts' => [
            ['day' => 1, 'cause' => 'day_full', 'minutes' => null, 'limit' => 2, 'fresh' => false], ['day' => 2, 'cause' => 'day_full', 'minutes' => null, 'limit' => 2, 'fresh' => false]]]));
        $this->assertSame('Day 1 already held 1 place; days 2 and 3 already held 2 places.', OmissionReason::sentence(['attempts' => [
            ['day' => 1, 'cause' => 'day_full', 'minutes' => null, 'limit' => 1, 'fresh' => false], ['day' => 2, 'cause' => 'day_full', 'minutes' => null, 'limit' => 2, 'fresh' => false],
            ['day' => 3, 'cause' => 'day_full', 'minutes' => null, 'limit' => 2, 'fresh' => false]]]), 'days are joined only when the figure is the same');
        $this->assertSame('It is closed on day 4, even starting the day fresh.', OmissionReason::sentence(['attempts' => [['day' => 4, 'cause' => 'closed_that_day', 'minutes' => null, 'limit' => null, 'fresh' => true]]]));
        $this->assertSame('Day 1 had no sightseeing time after your arrival.', OmissionReason::sentence(['attempts' => [['day' => 1, 'cause' => 'no_sightseeing_time_that_day', 'minutes' => null, 'limit' => 0, 'fresh' => false]]]));
    }

    public function test_without_a_recorded_reason_the_sentence_claims_nothing(): void
    {
        $this->assertSame('It was not added to your plan.', OmissionReason::sentence(['reason' => 'not_recorded', 'attempts' => []]));
        $this->assertSame('It was not added to your plan.', OmissionReason::sentence(['reason' => 'no_time_before_cutoff']), 'an old notice with no attempts');
        $this->assertSame('It was not added to your plan.', OmissionReason::sentence(['attempts' => [['day' => 1, 'cause' => 'something_new']]]), 'a cause it does not know');
        $this->assertStringNotContainsString('cutoff', OmissionReason::sentence(['attempts' => [['day' => 2, 'cause' => 'drive_budget_reached', 'minutes' => 400, 'limit' => 300]]]));
    }

    // ---- recorded by the real builder -----------------------------------------------------------------------

    /** The four far-flung stops of ArrivalRuleTest: in two days three fit and one is turned away. */
    private function farFlungTrip(int $days = 2, string $arrival = '06:00'): Itinerary
    {
        foreach ([['Samal Island', 'Beach & Leisure', 7.1553, 125.708, 'Island Garden City of Samal', 4.4], ['Mount Apo Natural Park', 'Adventure & Hiking', 6.9847, 125.2725, 'Davao del Sur', 4.3],
            ['Bemwa Farm Fresh Inc.', 'Farm Tourism', 7.481914, 125.259499, 'Davao City', 4.2], ['Dahican Beach', 'Beach & Surfing', 6.9553, 126.2758, 'Davao Oriental', 4.1]] as [$name, $type, $lat, $lng, $region, $rating]) {
            Destination::create(['slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => $region, 'region_id' => Region::firstOrCreate(['name' => $region])->id, 'type' => $type,
                'is_accredited' => true, 'rating' => $rating, 'review_count' => 10, 'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng]);
        }
        Accommodation::create(['slug' => 'sonreir', 'name' => 'Sonreir Apartelle and Inn', 'location' => 'Davao', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range', 'latitude' => 7.176, 'longitude' => 125.4693]);

        $this->app->bind(ThemeDayGrouper::class, fn () => new class extends ThemeDayGrouper
        {
            public function __construct() {}

            public function group(array $sequence, array $dayCapacities, array $origin): ?array
            {
                $by = collect($sequence)->keyBy(fn ($e) => $e['row']['destination']->name);

                return ['sequence' => array_values(array_filter(array_map(fn ($n) => $by[$n] ?? null, ['Mount Apo Natural Park', 'Bemwa Farm Fresh Inc.', 'Samal Island', 'Dahican Beach']))), 'days' => [],
                    'summary' => ['regrouped' => true, 'mean_similarity_before' => 0.0, 'mean_similarity_after' => 0.0, 'distance_before_km' => 0.0, 'distance_after_km' => 0.0]];
            }
        });

        $preference = TouristPreference::create(['travel_days' => $days, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'far',
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Cebu City', 'start_date' => '2026-10-28', 'arrival_time' => $arrival]);
        PreferenceActivity::create(['preference_id' => $preference->id, 'activity' => 'Beach & Island']);

        return app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'))->fresh();
    }

    public function test_every_place_left_out_has_a_notice_with_a_cause_the_builder_recorded(): void
    {
        $trip = $this->farFlungTrip();

        $placed = $trip->items()->where('kind', 'activity')->whereNotNull('destination_id')->pluck('destination_id')->all();
        $notices = collect($trip->stay_plan['notices'])->where('type', 'unplaced_destination')->values();

        $this->assertCount(3, $placed);
        $this->assertCount(1, $notices, 'the other one is named, none is dropped silently');
        $this->assertEqualsCanonicalizing(Destination::pluck('id')->all(), array_merge($placed, $notices->pluck('destination_id')->all()));

        foreach ($notices as $notice) {
            $this->assertNotSame('not_recorded', $notice['reason'], $notice['name']);
            $this->assertNotEmpty($notice['attempts'], $notice['name'].' carries the attempts the builder recorded');
            foreach ($notice['attempts'] as $attempt) {
                if ($attempt['cause'] === 'day_full') {
                    $this->assertGreaterThan(0, $attempt['limit'], 'the places a full day holds is recorded as its limit');
                    $this->assertNull($attempt['minutes']);
                }
                if ($attempt['cause'] === 'drive_budget_reached') {
                    $this->assertGreaterThan($attempt['limit'], $attempt['minutes']);
                }
            }
            $this->assertContains($notice['reason'], ['would_end_after_cutoff', 'drive_budget_reached', 'day_full', 'closed_that_day', 'outside_opening_hours', 'no_sightseeing_time_that_day']);
            // The old default invented a cutoff for everything; the sentence now follows the recorded cause.
            $this->assertSame(in_array('would_end_after_cutoff', array_column($notice['attempts'], 'cause'), true), str_contains(OmissionReason::sentence($notice), '18:00'));
        }
    }

    public function test_the_builder_keeps_attempts_per_build_and_a_new_trip_starts_with_none(): void
    {
        $this->farFlungTrip();
        $builder = app(ItineraryScheduleBuilder::class);
        $stops = Destination::orderBy('id')->get()->map(fn ($d) => ['row' => ['destination' => $d->load('region')], 'distance_km' => null])->all();

        $this->assertNull($builder->recordedAttempts($stops, null, [1 => ['Morning'], 2 => ['Morning']]), 'a build nobody made is not invented');

        $builder->forgetRecordedAttempts();
        $this->assertNull($builder->recordedAttempts([], null, [1 => ['Morning']]));
    }

    public function test_a_one_day_trip_is_told_what_was_left_out_too(): void
    {
        $trip = $this->farFlungTrip(1, '15:00');

        $placed = $trip->items()->where('kind', 'activity')->whereNotNull('destination_id')->pluck('destination_id')->all();
        $notices = collect(data_get($trip->stay_plan, 'notices', []))->where('type', 'unplaced_destination');

        $this->assertNotEmpty($notices, 'a 15:00 arrival cannot reach a stop three hours away before the cutoff');
        // An evening landing has room for one stop; that stop is in the notices because it was not placed.
        $this->assertSame([], $placed);
        $this->assertCount(1, $notices);
        $this->assertSame(0, $trip->stay_plan['continuity']['nights']);
    }

    // ---- the page ---------------------------------------------------------------------------------------------

    private function pageFor(string $range, array $notices, int $days = 3): string
    {
        $pref = TouristPreference::create(['travel_days' => $days, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => $range,
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Cebu City', 'start_date' => '2026-10-28']);
        $trip = Itinerary::create(['preference_id' => $pref->id, 'total_days' => $days, 'generated_at' => now(), 'stay_plan' => [
            'version' => 1, 'mode' => $range, 'continuity' => ['nights' => $days - 1, 'distinct_stays' => 1, 'transfers' => 0], 'notices' => $notices, 'guard' => null]]);
        ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => 1, 'sort_order' => 1, 'slot' => 'Evening', 'kind' => 'overnight', 'title' => 'Overnight stay', 'starts_at' => '20:00:00']);
        ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => $days, 'sort_order' => 1, 'slot' => 'Morning', 'kind' => 'departure', 'title' => 'Departure']);

        return $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $pref->id])->get(route('plan.itinerary'))->assertOk()->getContent();
    }

    private function notice(int $id, string $name, array $attempts): array
    {
        return ['type' => 'unplaced_destination', 'destination_id' => $id, 'name' => $name, 'reason' => $attempts ? $attempts[0]['cause'] : 'not_recorded', 'attempts' => $attempts];
    }

    public function test_a_remote_trip_lists_what_it_left_out(): void
    {
        $html = $this->pageFor('far', [
            $this->notice(1, 'Faraway Falls', [['day' => 2, 'cause' => 'drive_budget_reached', 'minutes' => 480, 'limit' => 420, 'fresh' => false]]),
            $this->notice(2, 'Unknown Lagoon', []),
        ]);

        $this->assertStringContainsString('2 places we set out to include could not be fitted into your plan.', $html);
        $this->assertStringContainsString('Faraway Falls', $html);
        $this->assertStringContainsString('On day 2 it would have taken the day to about 8 h of driving, over the 7 h limit for your range.', $html);
        $this->assertStringContainsString('It was not added to your plan.', $html);
        $this->assertStringNotContainsString('keeps the same place to stay', $html, 'that sentence belongs to Close Proximity');
        $this->assertStringNotContainsString('did not fit into your days before', $html, 'the old default wording is gone');
    }

    public function test_close_proximity_keeps_its_own_sentence_and_a_long_list_folds_away(): void
    {
        $html = $this->pageFor('near', array_map(fn ($i) => $this->notice($i, "Place $i", [['day' => 1, 'cause' => 'day_full', 'minutes' => null, 'limit' => 2, 'fresh' => false]]), range(1, 6)));

        $this->assertStringContainsString('6 places we set out to include could not be fitted into your plan.', $html);
        $this->assertStringContainsString('keeps the same place to stay for the whole trip', $html);
        $this->assertStringContainsString('<summary style="cursor:pointer;">Show the 6 places</summary>', $html);
        $this->assertStringContainsString('Place 6', $html, 'all of them are still there');
    }

    public function test_a_short_list_is_not_folded(): void
    {
        $html = $this->pageFor('moderate', [$this->notice(1, 'Only One', [['day' => 2, 'cause' => 'would_end_after_cutoff', 'minutes' => null, 'limit' => null, 'fresh' => false]])]);

        $this->assertStringContainsString('1 place we set out to include could not be fitted into your plan.', $html);
        $this->assertStringNotContainsString('Show the 1 places', $html);
    }
}
