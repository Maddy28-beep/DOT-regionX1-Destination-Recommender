<?php

namespace Tests\Feature;

use App\Http\Controllers\ExitSurveyController;
use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\ExitSurveyActivity;
use App\Models\ExitSurveyVisit;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The exit survey is anonymous by design (no tourist_id, no session link) --
 * these tests cover that a real submission is recorded correctly, and that
 * the endpoint rejects the forged/out-of-range input a plain unauthenticated
 * POST is exposed to.
 */
class ExitSurveyTest extends TestCase
{
    use RefreshDatabase;

    private function destination(): Destination
    {
        $region = Region::create(['name' => 'Davao City']);

        return Destination::create([
            'slug' => 'eden-nature-park', 'name' => 'Eden Nature Park',
            'location' => 'Toril, Davao City', 'region_id' => $region->id,
            'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'overall_rating' => 5,
            'would_recommend' => 'Yes',
            'travel_purpose' => 'Leisure',
            'activities' => ['Beach & Island'],
        ], $overrides);
    }

    public function test_a_submission_needs_no_account_or_session_link(): void
    {
        $destination = $this->destination();

        $response = $this->post(route('exit-survey.store'), $this->validPayload([
            'places_visited' => ["destination:{$destination->id}"],
        ]));

        $response->assertRedirect(route('exit-survey.create'));
        $this->assertSame(1, ExitSurvey::count());
        $this->assertSame(1, ExitSurveyVisit::count());
        $this->assertSame(1, ExitSurveyActivity::count());

        $survey = ExitSurvey::first();
        $this->assertArrayNotHasKey('tourist_id', $survey->getAttributes());
        $this->assertArrayNotHasKey('itinerary_id', $survey->getAttributes());
    }

    public function test_travel_purpose_is_rejected_outside_the_fixed_list(): void
    {
        $response = $this->post(route('exit-survey.store'), $this->validPayload([
            'travel_purpose' => 'Something I made up',
        ]));

        $response->assertSessionHasErrors('travel_purpose');
        $this->assertSame(0, ExitSurvey::count());
    }

    public function test_an_activity_outside_the_fixed_list_is_rejected(): void
    {
        $response = $this->post(route('exit-survey.store'), $this->validPayload([
            'activities' => ['Something I made up'],
        ]));

        $response->assertSessionHasErrors('activities.0');
        $this->assertSame(0, ExitSurvey::count());
    }

    public function test_a_place_kind_outside_the_real_morph_map_is_rejected(): void
    {
        $response = $this->post(route('exit-survey.store'), $this->validPayload([
            'places_visited' => ['not_a_real_kind:1'],
        ]));

        $response->assertSessionHasErrors('places_visited.0');
        $this->assertSame(0, ExitSurvey::count());
    }

    /** admin/establishment are real morph-map kinds (used by audit logs and accreditation
     *  records) but a tourist never "visits" one -- the survey must reject them too, not
     *  just kinds that are invalid everywhere in the app. */
    public function test_an_account_kind_is_rejected_even_though_it_is_a_real_morph_kind(): void
    {
        $response = $this->post(route('exit-survey.store'), $this->validPayload([
            'places_visited' => ['admin:1'],
        ]));

        $response->assertSessionHasErrors('places_visited.0');
        $this->assertSame(0, ExitSurvey::count());
    }

    public function test_more_than_the_allowed_number_of_activities_is_rejected(): void
    {
        $tooMany = array_fill(0, 51, 'Beach & Island');

        $response = $this->post(route('exit-survey.store'), $this->validPayload([
            'activities' => $tooMany,
        ]));

        $response->assertSessionHasErrors('activities');
        $this->assertSame(0, ExitSurvey::count());
    }

    public function test_one_invalid_field_blocks_the_whole_submission(): void
    {
        // A valid place alongside one invalid activity: nothing should be
        // written for either -- validation runs before the DB::transaction
        // in store(), so a rejected field can't leave a partial survey with
        // no matching visit/activity rows.
        $response = $this->post(route('exit-survey.store'), $this->validPayload([
            'places_visited' => ['destination:1'],
            'activities' => ['Not A Real Activity'],
        ]));

        $response->assertSessionHasErrors();
        $this->assertSame(0, ExitSurvey::count());
        $this->assertSame(0, ExitSurveyVisit::count());
    }

    public function test_the_options_shown_on_the_form_match_what_validation_accepts(): void
    {
        $response = $this->get(route('exit-survey.create'));

        $response->assertOk();
        foreach (ExitSurveyController::TRAVEL_PURPOSES as $purpose) {
            $response->assertSee($purpose);
        }
        foreach (ExitSurveyController::ACTIVITIES as $activity) {
            $response->assertSee($activity);
        }
    }
}
