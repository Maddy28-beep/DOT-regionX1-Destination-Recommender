<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\ExitSurveyVisit;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The DOT Admin "Association Rules & Co-Visitation Patterns" page: its new
 * KPI row (Rules Found / Transactions Analyzed / Minimum Support / Minimum
 * Confidence) must reflect real AprioriService output, never a fabricated or
 * stale number, and the page must not show misleading figures when there
 * isn't enough data to mine rules from.
 */
class AssociationRulesPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'email' => 'assoc-admin@x.test', 'password_hash' => Hash::make('x'),
            'full_name' => 'Admin', 'role' => 'super_admin',
        ]);
    }

    private function destination(string $name): Destination
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return Destination::create([
            'slug' => \Illuminate\Support\Str::slug($name), 'name' => $name, 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Nature', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 3, 'price_tier' => 'Mid-range',
        ]);
    }

    public function test_no_transactions_shows_the_empty_state_without_any_kpi_numbers(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.association-rules'))->getContent();

        $this->assertStringContainsString('Not enough visitation data yet', $html);
        $this->assertStringNotContainsString('Rules Found', $html);
        $this->assertStringNotContainsString('Transactions Analyzed', $html);
    }

    public function test_kpi_values_match_the_actual_apriori_output(): void
    {
        $a = $this->destination('Kiosk A');
        $b = $this->destination('Kiosk B');

        // 3 transactions visit both A and B; a 4th visits only A.
        for ($i = 0; $i < 3; $i++) {
            $survey = ExitSurvey::create(['overall_rating' => 5, 'would_recommend' => 'Yes']);
            ExitSurveyVisit::create(['exit_survey_id' => $survey->id, 'listing_kind' => 'destination', 'listing_id' => $a->id]);
            ExitSurveyVisit::create(['exit_survey_id' => $survey->id, 'listing_kind' => 'destination', 'listing_id' => $b->id]);
        }
        $lone = ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes']);
        ExitSurveyVisit::create(['exit_survey_id' => $lone->id, 'listing_kind' => 'destination', 'listing_id' => $a->id]);

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.association-rules'))->getContent();

        // 4 transactions total; confidence(A->B) = 3/4 = 75%, confidence(B->A) = 3/3 = 100%.
        $this->assertStringContainsString('Transactions Analyzed', $html);
        $this->assertMatchesRegularExpression('/\bstat-card-val">4<\/div>\s*<div class="stat-card-label">Transactions Analyzed/', $html);
        $this->assertStringContainsString('75.0%', $html);
        $this->assertStringContainsString('100.0%', $html);
        // Minimum support threshold (2 co-visits) as a % of these 4 transactions = 50%.
        $this->assertStringContainsString('50%', $html);
        $this->assertStringContainsString('Minimum Support', $html);
        // Default minimum confidence threshold is 15%.
        $this->assertStringContainsString('15%', $html);
        $this->assertStringContainsString('Minimum Confidence', $html);
        $this->assertStringContainsString('Based on 4 completed exit-survey transactions.', $html);
    }

    public function test_the_capped_note_appears_only_when_more_rules_exist_than_are_shown(): void
    {
        $a = $this->destination('Kiosk A');
        $b = $this->destination('Kiosk B');

        for ($i = 0; $i < 3; $i++) {
            $survey = ExitSurvey::create(['overall_rating' => 5, 'would_recommend' => 'Yes']);
            ExitSurveyVisit::create(['exit_survey_id' => $survey->id, 'listing_kind' => 'destination', 'listing_id' => $a->id]);
            ExitSurveyVisit::create(['exit_survey_id' => $survey->id, 'listing_kind' => 'destination', 'listing_id' => $b->id]);
        }

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.association-rules'))->getContent();

        // Only 2 rules exist (A->B, B->A) -- well under the 15-row cap, so no "Showing the top N of M" note.
        $this->assertStringNotContainsString('Showing the top', $html);
    }

    /** Confidence values must never be rewritten -- 100% is a real, unaltered result, not something to cap or fake. */
    public function test_a_genuine_100_percent_confidence_rule_is_shown_uncapped_and_unaltered(): void
    {
        $a = $this->destination('Kiosk A');
        $b = $this->destination('Kiosk B');

        for ($i = 0; $i < 2; $i++) {
            $survey = ExitSurvey::create(['overall_rating' => 5, 'would_recommend' => 'Yes']);
            ExitSurveyVisit::create(['exit_survey_id' => $survey->id, 'listing_kind' => 'destination', 'listing_id' => $a->id]);
            ExitSurveyVisit::create(['exit_survey_id' => $survey->id, 'listing_kind' => 'destination', 'listing_id' => $b->id]);
        }

        $apriori = app(\App\Services\Recommendation\AprioriService::class);
        $rule = $apriori->topRules()->firstWhere('a_id', $a->id);
        $this->assertEquals(1.0, $rule['confidence']);

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.association-rules'))->getContent();
        $this->assertStringContainsString('100.0%', $html);
        $this->assertStringContainsString('Confidence should be interpreted alongside support and co-visit counts', $html);
    }

    /** @param  array<string, int>  $mix  data_source => number of surveys that visit both A and B */
    private function pageWith(array $mix): string
    {
        $a = $this->destination('Kiosk A');
        $b = $this->destination('Kiosk B');
        foreach ($mix as $source => $n) {
            for ($i = 0; $i < $n; $i++) {
                $survey = ExitSurvey::create(['overall_rating' => 5, 'would_recommend' => 'Yes', 'data_source' => $source]);
                ExitSurveyVisit::create(['exit_survey_id' => $survey->id, 'listing_kind' => 'destination', 'listing_id' => $a->id]);
                ExitSurveyVisit::create(['exit_survey_id' => $survey->id, 'listing_kind' => 'destination', 'listing_id' => $b->id]);
            }
        }

        return $this->actingAs($this->admin(), 'admin')->get(route('admin.association-rules'))->getContent();
    }

    public function test_rule_rows_are_labelled_simulated_when_at_least_90_percent_of_the_data_is_simulated(): void
    {
        $this->assertSame(0.90, \App\Http\Controllers\Admin\AdminDashboardController::SIMULATED_LABEL_SHARE);

        $this->assertStringContainsString('class="sample-tag" title="At least 90%', $this->pageWith(['demo' => 10]), '100% simulated');
    }

    public function test_exactly_90_percent_simulated_still_gets_the_label(): void
    {
        $this->assertStringContainsString('>Simulated</span>', $this->pageWith(['demo' => 9, 'real' => 1]));
    }

    public function test_89_percent_simulated_does_not_get_the_label(): void
    {
        $this->assertStringNotContainsString('>Simulated</span>', $this->pageWith(['demo' => 8, 'real' => 1]));
    }

    public function test_real_only_data_is_never_labelled_simulated(): void
    {
        $this->assertStringNotContainsString('>Simulated</span>', $this->pageWith(['real' => 4]));
    }
}
