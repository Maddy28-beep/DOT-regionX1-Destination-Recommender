<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\ExitSurvey;
use App\Models\ExitSurveyActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The redesigned Exit Survey Insights page: ratings high to low (unrated last), a donut for activities, profile rows. */
class ExitSurveyRedesignTest extends TestCase
{
    use RefreshDatabase;

    private function page(): string
    {
        $admin = AdminUser::create(['email' => 'xs@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Admin', 'role' => 'super_admin']);

        return $this->actingAs($admin, 'admin')->get(route('admin.exit-surveys'))->assertOk()->getContent();
    }

    public function test_ratings_are_sorted_high_to_low_and_unrated_categories_come_last(): void
    {
        ExitSurvey::create(['overall_rating' => 3, 'itinerary_useful' => 5, 'destination_relevant' => 4, 'would_recommend' => 'Yes']);

        $html = $this->page();
        $positions = [
            strpos($html, 'Itinerary Usefulness'),
            strpos($html, 'Destination Relevance'),
            strpos($html, 'Overall Satisfaction', strpos($html, 'Average Ratings by Category')),
            strpos($html, 'Attraction Quality'),
        ];

        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'highest first, categories nobody rated last');
        $this->assertStringContainsString('No data yet', $html);
    }

    public function test_activities_are_drawn_as_a_donut_with_a_legend_and_percentages(): void
    {
        $a = ExitSurvey::create(['overall_rating' => 5, 'would_recommend' => 'Yes']);
        $b = ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes']);
        ExitSurveyActivity::create(['exit_survey_id' => $a->id, 'activity' => 'Hiking']);
        ExitSurveyActivity::create(['exit_survey_id' => $b->id, 'activity' => 'Hiking']);
        ExitSurveyActivity::create(['exit_survey_id' => $b->id, 'activity' => 'Beach']);

        $html = $this->page();

        $this->assertStringContainsString('class="xs-donut"', $html);
        $this->assertStringContainsString('conic-gradient(', $html);
        $this->assertStringContainsString('xs-legend', $html);
        $this->assertStringContainsString('Hiking', $html);
        $this->assertStringContainsString('Beach', $html);
    }

    public function test_every_original_section_is_still_on_the_page(): void
    {
        ExitSurvey::create(['overall_rating' => 5, 'would_recommend' => 'Yes', 'origin' => 'Cebu City', 'residency_type' => 'Local Resident', 'travel_purpose' => 'Business', 'total_spend' => 15000]);

        $html = $this->page();

        foreach (['Printable survey QR code', 'Apply Filter', 'Include simulated survey rows', 'Key Insights', 'Average Ratings by Category', 'Popular Activities',
            'Visitor Profile', 'Visitor Origin', 'Visitor Residency', 'Visit Type', 'Purpose of Travel', 'Trip Economics', 'Average Spending', 'Most Visited Places'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
    }
}
