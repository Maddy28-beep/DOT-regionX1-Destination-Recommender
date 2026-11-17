<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\ExitSurvey;
use App\Models\Region;
use App\Models\TouristPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExitSurveyStaysTest extends TestCase
{
    use RefreshDatabase;

    private function stay(array $attributes = []): Accommodation
    {
        return Accommodation::create($attributes + [
            'name' => 'Test City Hotel', 'slug' => 'test-city-hotel', 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
            'price_tier' => 'Mid-range',
        ]);
    }

    private function payload(array $answers = []): array
    {
        return $answers + ['overall_rating' => 5, 'would_recommend' => 'Yes', 'test' => 1];
    }

    public function test_stays_and_visits_remain_separate_in_one_submission(): void
    {
        $stay = $this->stay();
        $this->post(route('exit-survey.store'), $this->payload([
            'places_visited' => ['accommodation:'.$stay->id],
            'activities' => ['Wildlife'],
            'stays' => [
                ['place' => 'accommodation:'.$stay->id],
                ['place' => 'other', 'name' => '  Another   Inn ', 'locality' => ' Samal '],
                ['place' => 'other', 'name' => 'another inn', 'locality' => 'samal'],
                ['place' => 'friends_family'],
            ],
        ]))->assertRedirect(route('exit-survey.recap'));
        $survey = ExitSurvey::withoutGlobalScopes()->sole();
        $this->assertDatabaseCount('exit_survey_stays', 3);
        $this->assertDatabaseHas('exit_survey_stays', [
            'exit_survey_id' => $survey->id, 'accommodation_id' => $stay->id,
            'place_name' => 'Test City Hotel', 'directory_status' => 'directory_match',
        ]);
        $this->assertDatabaseHas('exit_survey_stays', ['reported_kind' => 'other', 'directory_status' => 'unverified']);
        $this->assertDatabaseCount('exit_survey_visits', 1);
        $this->assertDatabaseCount('exit_survey_activities', 1);
        $this->assertDatabaseCount('accommodations', 1);
    }

    public function test_missing_manual_fields_return_to_visit_step_and_preserve_answers(): void
    {
        $this->from(route('exit-survey.create'))->post(route('exit-survey.store'), $this->payload([
            'origin' => 'Cebu', 'stays' => [['place' => 'other', 'name' => 'My Inn']],
        ]))->assertSessionHasErrors('stays.0.locality');
        $this->get(route('exit-survey.create'))->assertOk()
            ->assertSee('data-initial-step="1"', false)->assertSee('value="My Inn"', false);
        $this->assertDatabaseCount('exit_surveys', 0);
        $this->assertDatabaseCount('exit_survey_stays', 0);
    }

    public function test_conflicting_or_excess_stays_are_rejected_atomically(): void
    {
        foreach ([
            [['place' => 'day_trip'], ['place' => 'friends_family']],
            array_fill(0, 6, ['place' => 'friends_family']),
        ] as $answers) {
            $this->post(route('exit-survey.store'), $this->payload(['stays' => $answers]))
                ->assertSessionHasErrors('stays');
        }
        $this->assertDatabaseCount('exit_surveys', 0);
    }

    public function test_archived_sample_and_missing_directory_entries_are_rejected(): void
    {
        $stay = $this->stay(['archived_at' => now()]);
        $sample = $this->stay(['name' => 'Sample Hotel', 'slug' => 'sample-hotel']);
        AccreditationRecord::create([
            'listing_kind' => 'accommodation', 'listing_id' => $sample->id,
            'accreditation_number' => 'SAMPLE', 'status' => 'active', 'is_placeholder' => true,
        ]);
        foreach ([$stay->id, $sample->id, 999999] as $id) {
            $this->post(route('exit-survey.store'), $this->payload(['stays' => [['place' => 'accommodation:'.$id]]]))
                ->assertSessionHasErrors('stays.0.place');
        }
        $this->assertDatabaseCount('exit_surveys', 0);
    }

    public function test_empty_stays_and_no_overnight_stay_are_valid(): void
    {
        $this->post(route('exit-survey.store'), $this->payload(['stays' => [['place' => '']]]))
            ->assertRedirect(route('exit-survey.recap'));
        $this->assertDatabaseCount('exit_survey_stays', 0);
        $this->post(route('exit-survey.store'), $this->payload(['stays' => [['place' => 'day_trip']]]))
            ->assertRedirect(route('exit-survey.recap'));
        $this->assertDatabaseHas('exit_survey_stays', ['reported_kind' => 'day_trip', 'place_name' => null]);
    }

    public function test_plan_context_only_fills_missing_answers_and_never_invents_actual_days(): void
    {
        $plan = TouristPreference::create([
            'travel_days' => 7, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'near',
            'travel_purpose' => 'Leisure', 'visitor_type' => 'Returning Visitor',
            'visitor_origin_source' => 'chosen', 'visitor_origin_country_code' => 'PH',
            'visitor_origin_province' => 'Cebu', 'visitor_origin_city' => 'Cebu City',
        ]);
        $this->withSession([TripPlannerController::PREFERENCE_KEY => $plan->id])
            ->post(route('exit-survey.store'), $this->payload(['travel_purpose' => 'Business']))
            ->assertRedirect(route('exit-survey.recap'));
        $survey = ExitSurvey::withoutGlobalScopes()->sole();
        $this->assertSame('Business', $survey->travel_purpose);
        $this->assertSame('Domestic Tourist', $survey->residency_type);
        $this->assertSame('Cebu City, Cebu, Philippines', $survey->origin);
        $this->assertSame('Returning Visitor', $survey->visitor_type);
        $this->assertNull($survey->actual_days_stayed);
    }
}
