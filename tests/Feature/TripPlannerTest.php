<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Region;
use App\Models\TouristHealthProfile;
use App\Models\TouristPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The travel-preference survey and AI itinerary are the product's headline
 * feature, and there is no account to run them behind: traveler registration
 * was removed for Data Privacy Act compliance. These cover the anonymous path
 * end to end, including the health and accessibility questions that moved into
 * this form because the recommender is what uses them.
 */
class TripPlannerTest extends TestCase
{
    use RefreshDatabase;

    private function seedDestinations(): void
    {
        $region = Region::create(['name' => 'Davao City']);

        $places = [
            ['eden-nature-park', 'Eden Nature Park', 7.0206, 125.4103],
            ['philippine-eagle-center', 'Philippine Eagle Center', 7.1839, 125.4064],
            ['peoples-park', 'Peoples Park', 7.0731, 125.6128],
        ];

        foreach ($places as [$slug, $name, $lat, $lng]) {
            Destination::create([
                'slug' => $slug, 'name' => $name, 'location' => 'Davao City',
                'region_id' => $region->id, 'type' => 'Nature & Leisure',
                'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10,
                'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng,
                'distance_km' => 12,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function surveyPayload(array $overrides = []): array
    {
        return $overrides + [
            'travel_days' => 3,
            'travel_type' => 'Family',
            'budget' => 'Mid-range',
            'accommodation_pref' => 'Hotel',
            'distance_pref' => 'moderate',
            'activities' => ['Nature', 'Hiking'],
            'amenities' => ['Parking Area'],
        ];
    }

    public function test_the_planner_is_reachable_without_signing_in(): void
    {
        $this->get('/plan')->assertOk()->assertSee('travel_days', false);
    }

    /**
     * Every field the controller requires has to actually exist in the
     * rendered form, under the name the controller expects.
     *
     * The other tests in this file post a payload built by surveyPayload()
     * straight to the route, which proves the controller works but says
     * nothing about whether a human filling in the real page could ever
     * produce that payload. That gap let the entire middle of the survey --
     * travel type, budget, accommodation preference, distance, interests,
     * amenities -- disappear from the template while every test kept passing,
     * because nothing ever looked at the page itself. This does.
     */
    public function test_the_rendered_form_has_every_field_the_controller_requires(): void
    {
        $html = $this->get('/plan')->assertOk()->getContent();

        $required = ['travel_days', 'travel_type', 'budget', 'accommodation_pref', 'distance_pref'];
        $optional = [
            'start_date', 'arrival_time', 'travel_purpose', 'visitor_type',
            'origin_label', 'origin_lat', 'origin_lng', 'accessibility_notes',
            'health_other', 'health_consent',
        ];

        foreach (array_merge($required, $optional) as $field) {
            $this->assertMatchesRegularExpression(
                '/name="'.preg_quote($field, '/').'"/',
                $html,
                "The rendered form is missing a field named '{$field}'."
            );
        }

        // activities[] and amenities[] are repeated checkboxes, not one field.
        $this->assertStringContainsString('name="activities[]"', $html);
        $this->assertStringContainsString('name="amenities[]"', $html);

        // And the required ones must be fields a browser will actually submit
        // -- a required <select> needs at least one <option>, not just the tag.
        preg_match('/<select[^>]*name="travel_type"[^>]*>(.*?)<\/select>/s', $html, $m);
        $this->assertNotEmpty($m, 'travel_type must be a real select element.');
        $this->assertStringContainsString('<option', $m[1] ?? '', 'travel_type must offer at least one option.');
    }

    /**
     * Submits using the verb the rendered form actually declares, rather than
     * assuming POST. The page was adapted from the dashboard survey and kept
     * a spoofed PUT while the new route only accepted POST, so the form 405'd
     * for every visitor even though a direct POST in the tests passed. Reading
     * the form back is what catches that mismatch.
     */
    public function test_the_rendered_form_targets_a_route_that_accepts_it(): void
    {
        $this->seedDestinations();

        $html = $this->get('/plan')->assertOk()->getContent();

        preg_match('/<form[^>]*action="([^"]*\/plan)"[^>]*method="(\w+)"/i', $html, $form)
            ?: preg_match('/<form[^>]*method="(\w+)"[^>]*action="([^"]*\/plan)"/i', $html, $form);

        $this->assertNotEmpty($form, 'Could not find the survey form.');

        // A hidden _method overrides the declared verb.
        preg_match('/name="_method"\s+value="(\w+)"/i', $html, $spoof);
        $verb = strtoupper($spoof[1] ?? 'POST');

        $this->assertSame('POST', $verb,
            "The form submits {$verb} but the plan route only accepts POST.");

        $payload = $this->surveyPayload();
        if (isset($spoof[1])) {
            $payload['_method'] = $spoof[1];
        }

        $this->post('/plan', $payload)->assertRedirect(route('plan.itinerary'));
    }

    public function test_a_visitor_can_generate_an_itinerary(): void
    {
        $this->seedDestinations();

        $this->post('/plan', $this->surveyPayload())
            ->assertRedirect(route('plan.itinerary'));

        $preference = TouristPreference::sole();
        $itinerary = Itinerary::sole();

        // The session is the only thing pointing at them; nothing identifies
        // who filled the survey in.
        $this->assertSame($preference->id, session(TripPlannerController::PREFERENCE_KEY));
        $this->assertSame($itinerary->id, session(TripPlannerController::ITINERARY_KEY));

        $this->get('/plan/itinerary')->assertOk()->assertSee('Eden Nature Park');
    }

    public function test_no_column_on_a_plan_can_identify_the_visitor(): void
    {
        $this->seedDestinations();
        $this->post('/plan', $this->surveyPayload());

        foreach ([TouristPreference::sole(), Itinerary::sole()] as $row) {
            $this->assertArrayNotHasKey('tourist_id', $row->getAttributes(),
                'A plan must not carry an owner column after the DPA change.');
        }
    }

    public function test_itinerary_has_ranked_matches_and_day_items(): void
    {
        $this->seedDestinations();
        $this->post('/plan', $this->surveyPayload());

        $itinerary = Itinerary::with('matches', 'items')->sole();

        $this->assertSame(3, $itinerary->matches()->count(), 'Every candidate should be ranked.');
        $this->assertGreaterThan(0, $itinerary->items()->count(), 'The plan should have day-by-day stops.');
    }

    public function test_visiting_the_itinerary_with_no_plan_sends_you_to_the_survey(): void
    {
        $this->get('/plan/itinerary')->assertRedirect(route('plan.edit'));
    }

    public function test_a_visitor_can_regenerate(): void
    {
        $this->seedDestinations();
        $this->post('/plan', $this->surveyPayload());

        $first = session(TripPlannerController::ITINERARY_KEY);

        $this->post('/plan/itinerary/regenerate')->assertRedirect(route('plan.itinerary'));

        $this->assertNotSame($first, session(TripPlannerController::ITINERARY_KEY));
    }

    /** Health answers are stored against the plan, and only with consent. */
    public function test_health_answers_are_stored_against_the_plan_when_consented(): void
    {
        $this->seedDestinations();

        $this->post('/plan', $this->surveyPayload([
            'health_conditions' => ['mobility', 'senior'],
            'health_other' => 'Needs step-free access',
            'health_consent' => '1',
        ]));

        $profile = TouristHealthProfile::with('conditions')->sole();

        $this->assertSame(TouristPreference::sole()->id, $profile->preference_id);
        $this->assertTrue($profile->consent);
        $this->assertSame('Needs step-free access', $profile->other_text);
        $this->assertEqualsCanonicalizing(['mobility', 'senior'], $profile->conditions->pluck('condition')->all());
    }

    public function test_health_answers_are_discarded_without_consent(): void
    {
        $this->seedDestinations();

        $this->post('/plan', $this->surveyPayload([
            'health_conditions' => ['mobility'],
            // no health_consent
        ]));

        $this->assertSame(0, TouristHealthProfile::count(),
            'Nothing may be stored when the consent box is unticked.');
    }

    /** Unticking consent on a later pass must delete what was there. */
    public function test_reediting_without_consent_clears_stored_health_answers(): void
    {
        $this->seedDestinations();

        $this->post('/plan', $this->surveyPayload([
            'health_conditions' => ['mobility'],
            'health_consent' => '1',
        ]));
        $this->assertSame(1, TouristHealthProfile::count());

        $this->post('/plan', $this->surveyPayload());

        $this->assertSame(0, TouristHealthProfile::count(),
            'Clearing consent must delete the stored answers, not just stop using them.');
    }

    /**
     * A traveller landing in the afternoon must not be handed a morning stop.
     *
     * The generator used to give every trip a full day 1 regardless of when
     * they arrived, which is the whole reason arrival time was collected.
     */
    public function test_an_afternoon_arrival_drops_the_morning_stop_on_day_one(): void
    {
        $this->seedDestinations();

        $this->post('/plan', $this->surveyPayload(['travel_days' => 2, 'arrival_time' => '14:00']));

        $dayOne = Itinerary::with('items')->sole()
            ->items->where('day_number', 1)->where('kind', 'activity');

        $this->assertSame(1, $dayOne->count(), 'Only one slot survives a 2pm arrival.');
        $this->assertGreaterThanOrEqual(12, (int) explode(':', $dayOne->first()->starts_at)[0],
            'A 2pm arrival cannot produce a stop that starts in the morning.');
    }

    /** An evening arrival leaves day 1 with the accommodation and nothing else. */
    public function test_an_evening_arrival_leaves_day_one_with_no_sightseeing(): void
    {
        $this->seedDestinations();

        // Needed for the second assertion to mean anything: with no
        // accommodation in the catalogue there is nothing to place, and the
        // test would pass or fail for the wrong reason.
        Accommodation::create([
            'slug' => 'airport-hotel', 'name' => 'Airport Hotel', 'location' => 'Davao City',
            'region_id' => Region::sole()->id, 'type' => 'Hotel', 'is_accredited' => true,
            'rating' => 4.0, 'review_count' => 5, 'price_tier' => 'Mid-range',
        ]);

        $this->post('/plan', $this->surveyPayload(['travel_days' => 2, 'arrival_time' => '19:30']));

        $dayOne = Itinerary::with('items')->sole()->items->where('day_number', 1);

        $this->assertCount(0, $dayOne->where('kind', 'activity'),
            'Nobody landing at 7:30pm can make a sightseeing stop that day.');
        $this->assertCount(1, $dayOne->where('kind', 'overnight'),
            'They still need somewhere to sleep on the night they arrive.');
    }

    /** With no arrival time given, day 1 keeps the full allowance. */
    public function test_omitting_arrival_time_keeps_a_full_first_day(): void
    {
        $this->seedDestinations();

        $this->post('/plan', $this->surveyPayload(['travel_days' => 2]));

        $dayOne = Itinerary::with('items')->sole()
            ->items->where('day_number', 1)->where('kind', 'activity');

        $this->assertSame(2, $dayOne->count());
        $this->assertContains('Morning', $dayOne->pluck('slot')->all());
    }

    /** The shared position has to actually change the order of the stops. */
    public function test_the_starting_point_reorders_the_stops(): void
    {
        $region = Region::create(['name' => 'Davao Region']);

        // Two destinations far apart. Whichever is nearer the stated starting
        // point should be visited first.
        foreach ([['north', 'North Park', 7.90, 125.60], ['south', 'South Cove', 6.20, 125.60]] as [$slug, $name, $lat, $lng]) {
            Destination::create([
                'slug' => $slug, 'name' => $name, 'location' => 'Davao Region',
                'region_id' => $region->id, 'type' => 'Nature & Leisure',
                'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10,
                'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng,
                'distance_km' => 20,
            ]);
        }

        // Driven through the service rather than two HTTP posts: the session
        // carries one plan at a time, so posting twice would edit the same
        // preference instead of comparing two starting points.
        $service = app(\App\Services\Recommendation\ItineraryGenerationService::class);

        $firstStop = function (float $lat, float $lng) use ($service) {
            $preference = TouristPreference::create([
                'travel_days' => 2, 'travel_type' => 'Family', 'budget' => 'Mid-range',
                'accommodation_pref' => 'Hotel', 'distance_pref' => 'moderate',
                'origin_lat' => $lat, 'origin_lng' => $lng,
            ])->load('activities', 'amenities');

            return $service->generate($preference)
                ->items->where('kind', 'activity')->sortBy('sort_order')->first()->destination->name;
        };

        $this->assertSame('North Park', $firstStop(7.95, 125.60),
            'Starting in the north, the northern stop must come first.');
        $this->assertSame('South Cove', $firstStop(6.15, 125.60),
            'Starting in the south, the southern stop must come first.');
    }

    /** Coordinates are coarsened before storage; a precise position is not kept. */
    public function test_a_shared_position_is_rounded_before_it_is_stored(): void
    {
        $this->seedDestinations();

        $this->post('/plan', $this->surveyPayload([
            'origin_lat' => 7.0731234567,
            'origin_lng' => 125.6128987654,
        ]));

        $preference = TouristPreference::sole();

        $this->assertSame(7.073, (float) $preference->origin_lat);
        $this->assertSame(125.613, (float) $preference->origin_lng);
    }

    /** Declining to share a location is normal, not an error. */
    public function test_a_plan_still_generates_with_no_location(): void
    {
        $this->seedDestinations();

        $this->post('/plan', $this->surveyPayload())->assertRedirect(route('plan.itinerary'));

        $preference = TouristPreference::sole();
        $this->assertNull($preference->origin_lat);
        $this->assertNull($preference->origin_lng);

        $this->get('/plan/itinerary')->assertOk()->assertSee('Davao City centre');
    }

    /** A nonsense position is rejected rather than used to sequence a trip. */
    public function test_an_out_of_range_position_is_rejected(): void
    {
        $this->seedDestinations();

        $this->post('/plan', $this->surveyPayload([
            'origin_lat' => 999,
            'origin_lng' => 125.61,
        ]))->assertSessionHasErrors('origin_lat');
    }

    /**
     * The schedule must be a day a human could actually live.
     *
     * A distant second stop used to drag the clock straight through midnight:
     * souvenir shopping at 1:45 AM, departure at 3:30 AM. Nothing may start
     * after the evening.
     */
    public function test_no_schedule_row_runs_into_the_small_hours(): void
    {
        $region = Region::create(['name' => 'Davao Region']);

        // A nearby stop and one hours away, so the day cannot hold both.
        foreach ([['near', 'Near Park', 7.07, 125.61], ['far', 'Far Shore', 6.95, 126.55]] as [$slug, $name, $lat, $lng]) {
            Destination::create([
                'slug' => $slug, 'name' => $name, 'location' => 'Davao Region',
                'region_id' => $region->id, 'type' => 'Nature & Leisure',
                'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10,
                'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng,
                'distance_km' => 40,
            ]);
        }

        $this->post('/plan', $this->surveyPayload(['travel_days' => 2, 'arrival_time' => '08:00']));

        foreach (Itinerary::with('items')->sole()->items as $item) {
            $hour = (int) explode(':', (string) $item->starts_at)[0];

            $this->assertLessThanOrEqual(21, $hour,
                "'{$item->title}' is scheduled at {$item->starts_at}, which is not a time anyone travels.");
        }
    }

    /** A day that spans midday has to feed the traveller. */
    public function test_a_full_day_includes_lunch(): void
    {
        $this->seedDestinations();

        $this->post('/plan', $this->surveyPayload(['travel_days' => 2, 'arrival_time' => '08:00']));

        $dayOne = Itinerary::with('items')->sole()->items->where('day_number', 1);

        $lunch = $dayOne->first(fn ($i) => str_starts_with((string) $i->title, 'Lunch'));

        $this->assertNotNull($lunch, 'A full day from 8am must include lunch.');
        $this->assertGreaterThanOrEqual(11, (int) explode(':', (string) $lunch->starts_at)[0]);
        $this->assertLessThanOrEqual(15, (int) explode(':', (string) $lunch->starts_at)[0]);
    }

    /** Every column of the requested format has to be populated. */
    public function test_the_schedule_has_the_four_expected_columns(): void
    {
        $this->seedDestinations();

        $this->post('/plan', $this->surveyPayload(['travel_days' => 2, 'arrival_time' => '08:00']));
        $items = Itinerary::with('items')->sole()->items->sortBy(['day_number', 'sort_order']);

        $first = $items->first();
        $this->assertSame('baseline', $first->kind, 'A trip opens where the traveller arrives.');
        $this->assertSame('Baseline Location', $first->travelSummary());

        $travel = $items->firstWhere('kind', 'travel');
        $this->assertNotNull($travel, 'Journeys are rows in their own right.');
        $this->assertNotNull($travel->distance_km);
        $this->assertMatchesRegularExpression('/^Approx\. [\d.]+ km, \d+(–\d+)? mins$/u', $travel->travelSummary());

        $activity = $items->firstWhere('kind', 'activity');
        $this->assertSame('On-site Activity', $activity->travelSummary());
        $this->assertNotNull($activity->ends_at, 'An on-site visit is a time range, not a moment.');
        $this->assertStringContainsString('–', $activity->timeLabel());

        $this->assertSame('departure', $items->last()->kind, 'A trip ends by leaving.');
        $this->assertSame('End of Itinerary', $items->last()->travelSummary());
    }

    /**
     * Most of the catalogue has no coordinates, so an unmeasurable leg is the
     * common case, not an edge case.
     *
     * Treating a null coordinate as 0,0 put the traveller in the Atlantic and
     * produced an 18,000 km transfer whose travel time then swallowed the rest
     * of the trip. A leg we cannot measure must say so and cost a sane amount.
     */
    public function test_a_listing_without_coordinates_does_not_produce_an_absurd_distance(): void
    {
        $region = Region::create(['name' => 'Davao City']);

        Destination::create([
            'slug' => 'located', 'name' => 'Located Park', 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Mid-range',
            'latitude' => 7.07, 'longitude' => 125.61, 'distance_km' => 12,
        ]);

        // No latitude or longitude, exactly as the accreditation import leaves
        // them. The accommodation is the one that matters: the nightly
        // transfer to it is not subject to the day's fit check, so an
        // unlocatable hotel is what actually produced the 18,000 km leg.
        Destination::create([
            'slug' => 'unlocated', 'name' => 'Unlocated Falls', 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Nature & Adventure', 'is_accredited' => true,
            'rating' => 4.6, 'review_count' => 12, 'price_tier' => 'Mid-range',
            'distance_km' => 14,
        ]);

        Accommodation::create([
            'slug' => 'unlocated-inn', 'name' => 'Unlocated Inn', 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Hotel', 'is_accredited' => true,
            'rating' => 4.7, 'review_count' => 9, 'price_tier' => 'Mid-range',
        ]);

        $this->post('/plan', $this->surveyPayload(['travel_days' => 2, 'arrival_time' => '08:00']));

        $travelRows = Itinerary::with('items')->sole()->items->where('kind', 'travel');

        $this->assertNotEmpty($travelRows);

        foreach ($travelRows as $row) {
            if ($row->distance_km !== null) {
                $this->assertLessThan(500, (float) $row->distance_km,
                    "'{$row->title}' claims {$row->distance_km} km, which is not a journey inside Davao Region.");
            } else {
                $this->assertSame('Travel time varies', $row->travelSummary(),
                    'An unmeasurable leg has to say so rather than invent a figure.');
            }

            $this->assertLessThanOrEqual(300, $row->travel_max_minutes,
                "'{$row->title}' allows {$row->travel_max_minutes} minutes, which would eat the whole trip.");
        }
    }

    /**
     * The score has to come with its working.
     *
     * All five factors of Equation 3 were computed and then discarded, so the
     * page could state a ranking but never show how it was reached.
     */
    public function test_the_ranking_keeps_the_five_score_factors(): void
    {
        $this->seedDestinations();
        $this->post('/plan', $this->surveyPayload());

        $match = Itinerary::with('matches')->sole()->matches->sortBy('rank')->first();

        foreach (['pm', 'rs', 'ps', 'ds', 'as'] as $factor) {
            $this->assertNotNull($match->{$factor}, "Factor {$factor} was not kept.");
            $this->assertGreaterThanOrEqual(0, $match->{$factor});
            $this->assertLessThanOrEqual(5, $match->{$factor});
        }

        // The stored factors must actually reconstruct the stored score,
        // otherwise the breakdown on screen would be decorative.
        $recomputed = $match->pm * 0.30 + $match->rs * 0.20 + $match->ps * 0.20
            + $match->ds * 0.15 + $match->as * 0.15;

        $this->assertEqualsWithDelta((float) $match->match_score, $recomputed, 0.01,
            'The factors must add up to the score they are shown beneath.');
    }

    /**
     * The recommendation list shows the ranking and the score, and nothing
     * more.
     *
     * Rendering all five factors as bars made the panel unreadable -- four fit
     * on one row and the fifth was orphaned on the next. The factors stay in
     * the database as the record of how the ranking was reached; the traveller
     * sees the result.
     */
    public function test_the_recommendation_list_shows_rank_and_score_only(): void
    {
        $this->seedDestinations();
        $this->post('/plan', $this->surveyPayload());

        $html = $this->get('/plan/itinerary')->assertOk()->getContent();

        $this->assertStringContainsString('Match Score', $html);
        $this->assertStringContainsString('/ 5.00', $html);

        foreach (['drs-bar-fill', 'drs-factor', 'PM&times;0.30'] as $removed) {
            $this->assertStringNotContainsString($removed, $html,
                "The per-factor breakdown was removed from the UI; no trace should remain.");
        }
    }

    /**
     * Apriori has to be credited where it acted, and only where it acted.
     *
     * The "frequently visited together" note was lost when the schedule
     * replaced the old day builder, leaving the page claiming association rule
     * mining while showing no rule at all.
     */
    public function test_an_apriori_chosen_row_carries_its_rule(): void
    {
        $this->seedDestinations();

        // Two exit surveys that both pair Eden Nature Park with one restaurant
        // clear the minimum support of 2, so a rule exists to be credited.
        $region = Region::sole();
        $restaurant = \App\Models\Restaurant::create([
            'slug' => 'tuna-place', 'name' => 'Tuna Place', 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Seafood', 'is_accredited' => true,
            'rating' => 4.4, 'review_count' => 6, 'price_tier' => 'Mid-range',
            'latitude' => 7.05, 'longitude' => 125.60,
        ]);
        $eden = Destination::where('slug', 'eden-nature-park')->sole();

        foreach (range(1, 3) as $i) {
            $survey = \App\Models\ExitSurvey::create(['submitted_at' => now()]);
            \App\Models\ExitSurveyVisit::create([
                'exit_survey_id' => $survey->id, 'listing_kind' => 'destination', 'listing_id' => $eden->id,
            ]);
            \App\Models\ExitSurveyVisit::create([
                'exit_survey_id' => $survey->id, 'listing_kind' => 'restaurant', 'listing_id' => $restaurant->id,
            ]);
        }

        $this->post('/plan', $this->surveyPayload(['travel_days' => 2, 'arrival_time' => '08:00']));

        $items = Itinerary::with('items')->sole()->items;
        $credited = $items->whereNotNull('rule_basis');

        $this->assertNotEmpty($credited, 'A row chosen by an association rule must record it.');

        $row = $credited->first();
        $this->assertNotNull($row->rule_confidence);
        $this->assertNotNull($row->rule_support);
        $this->assertStringContainsString('confidence', (string) $row->ruleExplanation());

        // Rows nobody mined stay silent rather than borrowing the credit.
        $this->assertNull($items->firstWhere('kind', 'travel')->ruleExplanation());
    }

    /** The provenance panel reports the real size of each algorithm's input. */
    public function test_the_page_states_how_the_plan_was_built(): void
    {
        $this->seedDestinations();
        $this->post('/plan', $this->surveyPayload());

        $html = $this->get('/plan/itinerary')->assertOk()->getContent();

        $this->assertStringContainsString('How This Plan Was Built', $html);
        $this->assertStringContainsString('Content-Based Recommendation', $html);
        $this->assertStringContainsString('Nearest-neighbour sequencing', $html);
        $this->assertStringContainsString('Apriori association rule mining', $html);

        // With no exit surveys seeded, the panel must say so rather than imply
        // rules were used.
        $this->assertStringContainsString('no rule cleared the support threshold', $html);
    }

    /**
     * A listing with nothing recorded must not be scored as though it were bad.
     *
     * Every imported DOT-accredited destination arrived without ratings, tags
     * or coordinates, and each of those gaps scored 0. All 17 landed on exactly
     * 2.09, could never enter a six-stop plan, and the same eight seeded places
     * appeared in every itinerary anyone generated.
     */
    public function test_an_undocumented_destination_can_still_compete(): void
    {
        $region = Region::create(['name' => 'Davao City']);

        $documented = Destination::create([
            'slug' => 'documented', 'name' => 'Documented Park', 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Mid-range',
            'latitude' => 7.07, 'longitude' => 125.61, 'distance_km' => 20,
        ]);

        // Exactly what the accreditation import produces: a name and a type.
        $bare = Destination::create([
            'slug' => 'bare', 'name' => 'Bare Listing', 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
        ]);

        $preference = TouristPreference::create([
            'travel_days' => 2, 'travel_type' => 'Family', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Hotel', 'distance_pref' => 'moderate',
        ])->load('activities', 'amenities');

        $ranked = app(\App\Services\Recommendation\ContentBasedRecommendationService::class)->rank($preference);

        $bareScore = $ranked->firstWhere('destination.id', $bare->id)['drs'];
        $documentedScore = $ranked->firstWhere('destination.id', $documented->id)['drs'];

        $this->assertGreaterThan(2.5, $bareScore,
            'An undocumented listing must not be scored as if it were known to be poor.');
        $this->assertGreaterThan($bareScore * 0.6, $documentedScore,
            'A documented listing should still be able to lead on evidence.');
    }

    /** Distance is measured from the traveller, not from a fixed city centre. */
    public function test_the_shared_location_changes_which_destinations_rank_near(): void
    {
        $north = Region::create(['name' => 'North']);
        $south = Region::create(['name' => 'South']);

        foreach ([[$north, 'north-spot', 'North Spot', 7.90], [$south, 'south-spot', 'South Spot', 6.20]] as [$region, $slug, $name, $lat]) {
            Destination::create([
                'slug' => $slug, 'name' => $name, 'location' => $name,
                'region_id' => $region->id, 'type' => 'Nature & Leisure', 'is_accredited' => true,
                'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Mid-range',
                'latitude' => $lat, 'longitude' => 125.60,
            ]);
        }

        $service = app(\App\Services\Recommendation\ContentBasedRecommendationService::class);

        $nearestFrom = function (float $lat) use ($service) {
            $preference = TouristPreference::create([
                'travel_days' => 2, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
                'accommodation_pref' => 'Any', 'distance_pref' => 'near',
                'origin_lat' => $lat, 'origin_lng' => 125.60,
            ])->load('activities', 'amenities');

            return $service->rank($preference)->first()['destination']->name;
        };

        $this->assertSame('North Spot', $nearestFrom(7.92));
        $this->assertSame('South Spot', $nearestFrom(6.18));
    }

    /**
     * Regenerating has to offer something new among equally-scored places,
     * while leaving anything the score can distinguish exactly where it was.
     */
    public function test_regenerating_rotates_between_equally_scored_destinations(): void
    {
        $region = Region::create(['name' => 'Davao City']);

        // A clear leader, plus six that nothing distinguishes.
        Destination::create([
            'slug' => 'leader', 'name' => 'Clear Leader', 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => 5.0, 'review_count' => 40, 'price_tier' => 'Mid-range',
            'latitude' => 7.07, 'longitude' => 125.61, 'distance_km' => 20,
        ]);

        foreach (range(1, 6) as $i) {
            Destination::create([
                'slug' => "tied-{$i}", 'name' => "Tied {$i}", 'location' => 'Davao City',
                'region_id' => $region->id, 'type' => 'Nature & Leisure', 'is_accredited' => true,
                'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
            ]);
        }

        $service = app(\App\Services\Recommendation\ContentBasedRecommendationService::class);

        $topThree = function (int $variation) use ($service) {
            $preference = TouristPreference::create([
                'travel_days' => 2, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
                'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
                'variation' => $variation,
            ])->load('activities', 'amenities');

            return $service->rank($preference)->take(3)->pluck('destination.name')->all();
        };

        $first = $topThree(0);
        $second = $topThree(1);

        $this->assertSame('Clear Leader', $first[0], 'A distinguishable leader keeps its place.');
        $this->assertSame('Clear Leader', $second[0], 'Rotation must not disturb a real ranking.');
        $this->assertNotSame($first, $second, 'Tied destinations must rotate between regenerations.');

        // ...and the same seed always gives the same answer, so a plan stays
        // reproducible and testable.
        $this->assertSame($first, $topThree(0));
    }

    /**
     * A day's map must cover every place that day, not only the sightseeing
     * stops we happen to hold coordinates for.
     *
     * The restaurant lunch happens at and the hotel the traveller sleeps in
     * were dropped for having no coordinates, so a day with four places drew a
     * map of one -- and days with fewer than two mapped stops drew nothing.
     */
    public function test_a_days_route_includes_every_place_including_unmapped_ones(): void
    {
        $this->seedDestinations();
        $region = Region::sole();

        // No coordinates, exactly as the accreditation import leaves them.
        Accommodation::create([
            'slug' => 'unmapped-hotel', 'name' => 'Unmapped Hotel', 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Hotel', 'is_accredited' => true,
            'rating' => 4.6, 'review_count' => 8, 'price_tier' => 'Mid-range',
        ]);

        $this->post('/plan', $this->surveyPayload(['travel_days' => 2, 'arrival_time' => '08:00']));

        $stops = Itinerary::with(['items.destination', 'items.accommodation', 'items.restaurant', 'items.souvenirCenter'])
            ->sole()->routeStops();

        $this->assertNotEmpty($stops[1] ?? [], 'Day 1 must have a route.');

        $labels = collect($stops[1])->pluck('label');
        $this->assertContains('Unmapped Hotel', $labels->all(),
            'A place with no coordinates still has to appear in the route.');

        // Consecutive rows at the same place are one stop, not three.
        foreach ($stops as $day => $dayStops) {
            $names = array_column($dayStops, 'label');
            for ($i = 1; $i < count($names); $i++) {
                $this->assertNotSame($names[$i - 1], $names[$i],
                    "Day {$day} repeats {$names[$i]} back to back; that is one journey, not two.");
            }
        }
    }

    /** The Google Maps link has to carry the whole day, in order. */
    public function test_the_google_maps_link_carries_every_stop(): void
    {
        $url = Itinerary::googleMapsUrl([
            ['label' => 'Start Hotel', 'address' => 'Davao City', 'lat' => null, 'lng' => null],
            ['label' => 'Mapped Park', 'address' => 'Davao City', 'lat' => 7.07, 'lng' => 125.61],
            ['label' => 'Lunch Spot', 'address' => 'Toril', 'lat' => null, 'lng' => null],
            ['label' => 'Start Hotel', 'address' => 'Davao City', 'lat' => null, 'lng' => null],
        ]);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://www.google.com/maps/dir/', $url);
        $this->assertSame('Start Hotel, Davao City', $query['origin']);
        $this->assertSame('Start Hotel, Davao City', $query['destination'],
            'A day that returns to the hotel must end there.');

        // Coordinates are used where known, names where not.
        $this->assertStringContainsString('7.07,125.61', $query['waypoints']);
        $this->assertStringContainsString('Lunch Spot, Toril', $query['waypoints']);

        // No API key is involved: this project has never required a billing account.
        $this->assertStringNotContainsString('key=', $url);
    }

    /** A single place is not a route, but must still open. */
    public function test_a_one_stop_day_links_to_a_maps_search(): void
    {
        $url = Itinerary::googleMapsUrl([
            ['label' => 'Only Stop', 'address' => 'Davao City', 'lat' => null, 'lng' => null],
        ]);

        $this->assertStringContainsString('/maps/search/', $url);
        $this->assertStringContainsString('Only+Stop', str_replace('%20', '+', $url));
    }

    /** Rows have to run forwards within a day. */
    public function test_each_day_runs_in_chronological_order(): void
    {
        $this->seedDestinations();

        $this->post('/plan', $this->surveyPayload(['travel_days' => 3, 'arrival_time' => '08:00']));

        foreach (Itinerary::with('items')->sole()->items->groupBy('day_number') as $day => $rows) {
            $times = $rows->sortBy('sort_order')->pluck('starts_at')->all();
            $sorted = $times;
            sort($sorted);

            $this->assertSame($sorted, $times, "Day {$day} is out of chronological order.");
        }
    }

    /**
     * "Within the city" must be a hard gate, not just a soft nudge.
     *
     * A distant destination scored far higher on rating and popularity than a
     * nearby one used to still win, because distance was only ever one of
     * five blended factors. It must now not even be a candidate: the closer,
     * more modest destination is the only one on offer.
     */
    public function test_within_the_city_excludes_a_far_destination_even_if_it_is_more_popular(): void
    {
        $region = Region::create(['name' => 'Davao City']);

        // ~7.8 km from the baseline -- comfortably inside the 25 km "near" tier.
        $near = Destination::create([
            'slug' => 'near-park', 'name' => 'Near Park', 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => 3.5, 'review_count' => 2, 'price_tier' => 'Mid-range',
            'latitude' => 7.1231, 'longitude' => 125.6628,
        ]);

        // ~120.6 km away -- outside even the 75 km "moderate" tier -- but the
        // most rated, most reviewed place in the whole set.
        Destination::create([
            'slug' => 'far-popular', 'name' => 'Far Popular Resort', 'location' => 'Mati City',
            'region_id' => $region->id, 'type' => 'Beach & Leisure', 'is_accredited' => true,
            'rating' => 5.0, 'review_count' => 200, 'price_tier' => 'Mid-range',
            'latitude' => 7.8431, 'longitude' => 126.3828,
        ]);

        // A second near candidate, so the "within the city" tier already has
        // enough destinations and never needs to widen.
        Destination::create([
            'slug' => 'near-park-2', 'name' => 'Near Park Two', 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Wildlife', 'is_accredited' => true,
            'rating' => 3.5, 'review_count' => 2, 'price_tier' => 'Mid-range',
            'latitude' => 7.09, 'longitude' => 125.63,
        ]);

        $preference = TouristPreference::create([
            'travel_days' => 1, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'near',
            'origin_lat' => 7.0731, 'origin_lng' => 125.6128,
        ])->load('activities', 'amenities');

        $service = app(\App\Services\Recommendation\ContentBasedRecommendationService::class);
        $ranked = $service->rank($preference);

        $names = $ranked->pluck('destination.name')->all();

        $this->assertContains('Near Park', $names);
        $this->assertNotContains('Far Popular Resort', $names,
            'A destination outside the chosen range must not appear at all, however well it scores otherwise.');
        $this->assertFalse($service->lastRangeWidened, 'The near tier already had enough destinations; it must not have widened.');
        $this->assertSame('near', $service->lastRangeTierUsed);
    }

    /** The moderate tier reaches further than "within the city" but is still bounded. */
    public function test_moderate_range_includes_a_nearby_city_but_not_a_distant_one(): void
    {
        $region = Region::create(['name' => 'Davao City']);

        Destination::create([
            'slug' => 'near-spot', 'name' => 'Near Spot', 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => 4.0, 'review_count' => 3, 'price_tier' => 'Mid-range',
            'latitude' => 7.1231, 'longitude' => 125.6628,
        ]);

        // ~50 km away -- outside "near" (25 km) but inside "moderate" (75 km).
        Destination::create([
            'slug' => 'moderate-town-spot', 'name' => 'Moderate Town Spot', 'location' => 'Tagum City',
            'region_id' => $region->id, 'type' => 'Cultural Heritage', 'is_accredited' => true,
            'rating' => 4.0, 'review_count' => 3, 'price_tier' => 'Mid-range',
            'latitude' => 7.3931, 'longitude' => 125.9328,
        ]);

        // ~120 km away -- outside "moderate" too.
        Destination::create([
            'slug' => 'far-spot', 'name' => 'Far Spot', 'location' => 'Mati City',
            'region_id' => $region->id, 'type' => 'Beach & Leisure', 'is_accredited' => true,
            'rating' => 4.0, 'review_count' => 3, 'price_tier' => 'Mid-range',
            'latitude' => 7.8431, 'longitude' => 126.3828,
        ]);

        $preference = TouristPreference::create([
            'travel_days' => 1, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
            'origin_lat' => 7.0731, 'origin_lng' => 125.6128,
        ])->load('activities', 'amenities');

        $names = app(\App\Services\Recommendation\ContentBasedRecommendationService::class)
            ->rank($preference)->pluck('destination.name')->all();

        $this->assertContains('Near Spot', $names, 'The moderate range must still include what is even closer.');
        $this->assertContains('Moderate Town Spot', $names);
        $this->assertNotContains('Far Spot', $names, 'A destination beyond the moderate range must not be included.');
    }

    /** "Willing to travel" removes the distance ceiling entirely. */
    public function test_willing_to_travel_has_no_distance_ceiling(): void
    {
        $region = Region::create(['name' => 'Davao City']);

        Destination::create([
            'slug' => 'near-here', 'name' => 'Near Here', 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => 4.0, 'review_count' => 3, 'price_tier' => 'Mid-range',
            'latitude' => 7.1231, 'longitude' => 125.6628,
        ]);

        Destination::create([
            'slug' => 'far-there', 'name' => 'Far There', 'location' => 'Mati City',
            'region_id' => $region->id, 'type' => 'Beach & Leisure', 'is_accredited' => true,
            'rating' => 4.0, 'review_count' => 3, 'price_tier' => 'Mid-range',
            'latitude' => 7.8431, 'longitude' => 126.3828,
        ]);

        $preference = TouristPreference::create([
            'travel_days' => 1, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'far',
            'origin_lat' => 7.0731, 'origin_lng' => 125.6128,
        ])->load('activities', 'amenities');

        $names = app(\App\Services\Recommendation\ContentBasedRecommendationService::class)
            ->rank($preference)->pluck('destination.name')->all();

        $this->assertContains('Near Here', $names);
        $this->assertContains('Far There', $names, 'Willing to travel must not exclude anything on distance alone.');
    }

    /**
     * The real scenario this was built for: a province with almost nothing in
     * it. Davao Oriental had exactly one seeded destination at the time of
     * writing, so a strict "within the city" filter from a Mati City baseline
     * must widen rather than come back empty.
     */
    public function test_a_sparse_region_widens_instead_of_returning_nothing(): void
    {
        $region = Region::create(['name' => 'Davao Oriental']);

        // Only one destination anywhere near Mati City itself.
        Destination::create([
            'slug' => 'dahican-like', 'name' => 'Dahican-like Beach', 'location' => 'Mati City',
            'region_id' => $region->id, 'type' => 'Beach & Leisure', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 5, 'price_tier' => 'Mid-range',
            'latitude' => 6.96, 'longitude' => 126.22,
        ]);

        // Everything else the catalogue actually has is far away, in Davao City.
        foreach (range(1, 3) as $i) {
            Destination::create([
                'slug' => "davao-city-{$i}", 'name' => "Davao City Spot {$i}", 'location' => 'Davao City',
                'region_id' => Region::create(['name' => "Davao City {$i}"])->id,
                'type' => 'Nature & Leisure', 'is_accredited' => true,
                'rating' => 4.0, 'review_count' => 4, 'price_tier' => 'Mid-range',
                'latitude' => 7.07 + $i * 0.01, 'longitude' => 125.61 + $i * 0.01,
            ]);
        }

        // Mati City, Davao Oriental -- the traveller's actual baseline.
        $preference = TouristPreference::create([
            'travel_days' => 2, 'travel_type' => 'Family', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'near',
            'origin_lat' => 6.9521657, 'origin_lng' => 126.2166758,
            'origin_label' => 'Mati City, Davao Oriental, Philippines',
        ])->load('activities', 'amenities');

        $service = app(\App\Services\Recommendation\ContentBasedRecommendationService::class);
        $ranked = $service->rank($preference);

        $this->assertNotEmpty($ranked, 'A sparse region must still produce an itinerary, not an empty one.');
        $this->assertTrue($service->lastRangeWidened,
            'With only one destination anywhere near Mati City, the range had to widen.');
        $this->assertContains('Dahican-like Beach', $ranked->pluck('destination.name')->all(),
            'The one genuinely local destination must still be included once widened.');
    }

    /** Baseline is dynamic: the SAME rules apply from any Davao Region starting point, not just Davao City. */
    public function test_the_range_gate_works_from_a_non_davao_city_baseline(): void
    {
        $region = Region::create(['name' => 'Davao Oriental']);

        // ~8 km from a Mati City baseline.
        Destination::create([
            'slug' => 'near-mati', 'name' => 'Near Mati Spot', 'location' => 'Mati City',
            'region_id' => $region->id, 'type' => 'Beach & Leisure', 'is_accredited' => true,
            'rating' => 4.0, 'review_count' => 3, 'price_tier' => 'Mid-range',
            'latitude' => 7.0021657, 'longitude' => 126.2666758,
        ]);

        // A second, so the near tier has enough to avoid widening.
        Destination::create([
            'slug' => 'near-mati-2', 'name' => 'Near Mati Spot Two', 'location' => 'Mati City',
            'region_id' => $region->id, 'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => 4.0, 'review_count' => 3, 'price_tier' => 'Mid-range',
            'latitude' => 6.98, 'longitude' => 126.21,
        ]);

        // Davao City itself, ~120 km from Mati -- far from THIS baseline, even
        // though it would be "home" for a Davao-City-baseline trip.
        Destination::create([
            'slug' => 'davao-city-far-from-mati', 'name' => 'Davao City Spot', 'location' => 'Davao City',
            'region_id' => Region::create(['name' => 'Davao City'])->id,
            'type' => 'Cultural Heritage', 'is_accredited' => true,
            'rating' => 4.0, 'review_count' => 3, 'price_tier' => 'Mid-range',
            'latitude' => 7.0731, 'longitude' => 125.6128,
        ]);

        $preference = TouristPreference::create([
            'travel_days' => 1, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'near',
            'origin_lat' => 6.9521657, 'origin_lng' => 126.2166758,
            'origin_label' => 'Mati City, Davao Oriental, Philippines',
        ])->load('activities', 'amenities');

        $names = app(\App\Services\Recommendation\ContentBasedRecommendationService::class)
            ->rank($preference)->pluck('destination.name')->all();

        $this->assertContains('Near Mati Spot', $names);
        $this->assertNotContains('Davao City Spot', $names,
            'The gate must be relative to the traveller\'s OWN baseline, not hardcoded to Davao City.');
    }

    /**
     * A destination in a region with NO mapped destinations of its own must
     * not be treated as "distance unknown, therefore always in range."
     *
     * Davao del Norte has zero mapped destinations in the real catalogue, so
     * before this fix, any Davao del Norte listing passed "within the city"
     * from ANY baseline, however far away it actually was -- while a mapped,
     * genuinely closer Davao City destination could be correctly excluded.
     * The real province centre (Tagum City) is used as a last-resort
     * reference point precisely so this can no longer happen.
     */
    public function test_a_region_with_no_mapped_destinations_still_gets_a_real_distance(): void
    {
        $davaoCity = Region::create(['name' => 'Davao City']);
        $davaoDelNorte = Region::create(['name' => 'Davao del Norte']);

        // Two real, mapped, genuinely near-baseline destinations -- enough
        // that the near tier already has plenty and never needs to widen.
        foreach (['near-a', 'near-b'] as $i => $slug) {
            Destination::create([
                'slug' => $slug, 'name' => 'Near '.strtoupper($slug), 'location' => 'Davao City',
                'region_id' => $davaoCity->id, 'type' => 'Nature & Leisure', 'is_accredited' => true,
                'rating' => 4.0, 'review_count' => 3, 'price_tier' => 'Mid-range',
                'latitude' => 7.09 + $i * 0.01, 'longitude' => 125.63 + $i * 0.01,
            ]);
        }

        // A Davao del Norte destination with NO coordinates -- exactly the
        // shape of the real imported catalogue. Its region has nothing else
        // to average, so before the fix its distance was simply unknown.
        Destination::create([
            'slug' => 'del-norte-farm', 'name' => 'Del Norte Farm', 'location' => 'Tagum City',
            'region_id' => $davaoDelNorte->id, 'type' => 'Farm Tourism', 'is_accredited' => true,
            'rating' => 4.0, 'review_count' => 3, 'price_tier' => 'Mid-range',
        ]);

        $preference = TouristPreference::create([
            'travel_days' => 1, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'near',
            // A baseline where Davao City is close (~1-2 km) and the real
            // Davao del Norte reference point (Tagum, ~7.45,125.81) is
            // genuinely far -- ~40+ km, outside the 25 km "near" tier.
            'origin_lat' => 7.0731, 'origin_lng' => 125.6128,
        ])->load('activities', 'amenities');

        $names = app(\App\Services\Recommendation\ContentBasedRecommendationService::class)
            ->rank($preference)->pluck('destination.name')->all();

        $this->assertNotContains('Del Norte Farm', $names,
            'An unmapped destination in an entirely-unmapped region must not bypass the range filter.');
    }

    /** The stated need has to actually reach the ranking. */


    public function test_health_answers_change_the_ranking(): void
    {
        $region = Region::create(['name' => 'Davao City']);

        foreach ([['ramped', 'Ramped Park', true], ['stairs', 'Stairs Only Park', false]] as [$slug, $name, $accessible]) {
            $destination = Destination::create([
                'slug' => $slug, 'name' => $name, 'location' => 'Davao City',
                'region_id' => $region->id, 'type' => 'Nature & Leisure',
                'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10,
                'price_tier' => 'Mid-range', 'latitude' => 7.07, 'longitude' => 125.61,
                'distance_km' => 20,
            ]);

            if ($accessible) {
                $destination->tags()->create(['kind' => 'amenity', 'value' => 'Accessibility Ramp']);
            }
        }

        $this->post('/plan', $this->surveyPayload([
            'health_conditions' => ['mobility'],
            'health_consent' => '1',
        ]));

        $top = Itinerary::with('matches.destination')->sole()
            ->matches->sortBy('rank')->first();

        $this->assertSame('Ramped Park', $top->destination->name,
            'A stated mobility need must push the accessible destination to the top.');
    }
}
