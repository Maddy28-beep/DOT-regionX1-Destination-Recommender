<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\ExitSurvey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Apriori needs two places in one survey to form a rule, so the insights page
 * reports how many REAL surveys carry two or more places, separately from the
 * (much larger) simulated demo set.
 */
class ExitSurveyUsableForRulesTest extends TestCase
{
    use RefreshDatabase;

    private function survey(string $source, int $places): void
    {
        $survey = ExitSurvey::create([
            'submitted_at' => now(), 'overall_rating' => 4, 'would_recommend' => 'Yes', 'data_source' => $source,
        ]);

        for ($i = 1; $i <= $places; $i++) {
            $survey->visits()->create(['listing_kind' => 'destination', 'listing_id' => $i]);
        }
    }

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'email' => 'usable-admin@x.test', 'password_hash' => Hash::make('x'),
            'full_name' => 'Admin', 'role' => 'super_admin',
        ]);
    }

    public function test_only_real_surveys_with_two_or_more_places_are_counted(): void
    {
        $this->survey('real', 0);
        $this->survey('real', 1);
        $this->survey('real', 2);
        $this->survey('real', 3);
        $this->survey('demo', 4);
        $this->survey('demo', 5);

        $response = $this->actingAs($this->admin(), 'admin')->get(route('admin.exit-surveys'))->assertOk();

        $this->assertSame(2, $response->viewData('realUsableForRules'));
        $this->assertSame(4, $response->viewData('realCount'));
        $response->assertSee('Real Surveys Usable for Rules');
    }

    public function test_the_survey_form_encourages_adding_two_or_more_places(): void
    {
        $this->get(route('exit-survey.create'))
            ->assertOk()
            ->assertSee('adding two or more places');
    }
}
