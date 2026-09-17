<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\ExitSurveyActivity;
use App\Models\ExitSurveyVisit;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The DOT Admin "Exit Survey Insights" page (tourism analytics/statistics),
 * covering the specific accuracy fixes requested: no fabricated response
 * rate, visitor origin and spending treated as first-class stats with real
 * missing-data states, mathematically correct averages, and working filters.
 */
class ExitSurveyInsightsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'email' => 'dot-admin@x.test', 'password_hash' => Hash::make('x'),
            'full_name' => 'DOT Admin', 'role' => 'super_admin',
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

    public function test_visitor_origin_groups_by_actual_reported_data_case_and_whitespace_insensitively(): void
    {
        ExitSurvey::create(['overall_rating' => 5, 'would_recommend' => 'Yes', 'origin' => 'Cebu City']);
        ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes', 'origin' => ' cebu city ']);
        ExitSurvey::create(['overall_rating' => 5, 'would_recommend' => 'Yes', 'origin' => 'Manila']);
        ExitSurvey::create(['overall_rating' => 5, 'would_recommend' => 'Yes']); // no origin reported

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.exit-surveys'))->getContent();

        $this->assertStringContainsString('Visitor Origin', $html);
        $this->assertStringContainsString('Cebu City', $html);
        // The two Cebu City variants collapse into one bar, counted twice --
        // 2 of the 3 responses that reported ANY origin (the percentage base
        // excludes the one survey with no origin at all), so 67%, not 50%.
        $this->assertStringContainsString('2 (67%)', $html);
        $this->assertStringContainsString('Manila', $html);
        $this->assertStringContainsString('3 of 4 response', $html);
    }

    public function test_no_spending_data_shows_an_explicit_message_not_a_zero_value(): void
    {
        ExitSurvey::create(['overall_rating' => 5, 'would_recommend' => 'Yes']);
        ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'No']);

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.exit-surveys'))->getContent();

        $this->assertStringContainsString('No spending data available yet.', $html);
        $this->assertStringNotContainsString('₱0.00', $html);
    }

    public function test_satisfaction_averages_are_correct_and_highest_lowest_are_flagged(): void
    {
        ExitSurvey::create(['overall_rating' => 5, 'itinerary_useful' => 5, 'destination_relevant' => 2, 'would_recommend' => 'Yes']);
        ExitSurvey::create(['overall_rating' => 3, 'itinerary_useful' => 5, 'destination_relevant' => 2, 'would_recommend' => 'Yes']);

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.exit-surveys'))->getContent();

        // Overall Satisfaction avg = (5+3)/2 = 4.00, Itinerary Usefulness = 5.00, Destination Relevance = 2.00.
        $this->assertStringContainsString('4.00', $html);
        $this->assertStringContainsString('5.00', $html);
        $this->assertStringContainsString('2.00', $html);
        $this->assertStringContainsString('Itinerary Usefulness', $html);
        $this->assertStringContainsString('Destination Relevance', $html);
        $this->assertStringContainsString('Highest', $html);
        $this->assertStringContainsString('Lowest', $html);
        $this->assertStringContainsString('Highest-rated category: <strong>Itinerary Usefulness</strong>', $html);
        $this->assertStringContainsString('Lowest-rated category: <strong>Destination Relevance</strong>', $html);
    }

    public function test_most_visited_places_and_popular_activities_reflect_actual_survey_data(): void
    {
        $edenPark = $this->destination('Eden Nature Park');
        $mountApo = $this->destination('Mount Apo Natural Park');

        $s1 = ExitSurvey::create(['overall_rating' => 5, 'would_recommend' => 'Yes']);
        $s2 = ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes']);

        ExitSurveyVisit::create(['exit_survey_id' => $s1->id, 'listing_kind' => 'destination', 'listing_id' => $edenPark->id]);
        ExitSurveyVisit::create(['exit_survey_id' => $s2->id, 'listing_kind' => 'destination', 'listing_id' => $edenPark->id]);
        ExitSurveyVisit::create(['exit_survey_id' => $s2->id, 'listing_kind' => 'destination', 'listing_id' => $mountApo->id]);

        ExitSurveyActivity::create(['exit_survey_id' => $s1->id, 'activity' => 'Hiking & Trekking']);
        ExitSurveyActivity::create(['exit_survey_id' => $s2->id, 'activity' => 'Hiking & Trekking']);

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.exit-surveys'))->getContent();

        $this->assertStringContainsString('Eden Nature Park', $html);
        $this->assertStringContainsString('2 visits', $html);
        $this->assertStringContainsString('Mount Apo Natural Park', $html);
        $this->assertStringContainsString('1 visit<', $html);
        $this->assertStringContainsString('Hiking &amp; Trekking', $html);
        // 2 mentions out of 2 total exit surveys = 100%.
        $this->assertStringContainsString('2 mentions (100%)', $html);
    }

    public function test_filters_narrow_every_analytic_on_the_page(): void
    {
        ExitSurvey::create([
            'overall_rating' => 5, 'would_recommend' => 'Yes',
            'residency_type' => 'Domestic Tourist', 'origin' => 'Cebu City',
        ]);
        ExitSurvey::create([
            'overall_rating' => 2, 'would_recommend' => 'No',
            'residency_type' => 'Foreign Tourist', 'origin' => 'Seoul, South Korea',
        ]);

        $admin = $this->admin();

        $unfiltered = $this->actingAs($admin, 'admin')->get(route('admin.exit-surveys'))->getContent();
        $this->assertStringContainsString('Cebu City', $unfiltered);
        $this->assertStringContainsString('Seoul, South Korea', $unfiltered);

        $filtered = $this->actingAs($admin, 'admin')
            ->get(route('admin.exit-surveys', ['residency' => 'Domestic Tourist']))
            ->getContent();

        $this->assertStringContainsString('Cebu City', $filtered);
        $this->assertStringNotContainsString('Seoul, South Korea', $filtered);
        // Only 1 of the 2 surveys matches the filter.
        $this->assertMatchesRegularExpression('/\bstat-card-val">1<\/div>\s*<div class="stat-card-label">Exit Survey Responses/', $filtered);
    }

    public function test_a_filter_value_outside_the_known_options_is_ignored_rather_than_erroring(): void
    {
        ExitSurvey::create(['overall_rating' => 5, 'would_recommend' => 'Yes']);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.exit-surveys', ['residency' => "'; DROP TABLE exit_surveys; --"]))
            ->assertOk();

        $this->assertSame(1, ExitSurvey::count());
    }
}
