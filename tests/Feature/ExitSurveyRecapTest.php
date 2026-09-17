<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Http\Middleware\EnsureVisitorToken;
use App\Models\Destination;
use App\Models\Region;
use App\Models\TouristAccount;
use App\Models\TouristPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The immediate payoff for finishing the anonymous exit survey: a recap of
 * the trip itself, built entirely from what was just submitted (no invented
 * numbers), plus a short list of places not yet visited. These tests guard
 * that the recap is accurate, that it degrades gracefully with no data, and
 * that it never leaks anything the survey is supposed to keep private.
 */
class ExitSurveyRecapTest extends TestCase
{
    use RefreshDatabase;

    private function seedDestinations(int $count = 3): array
    {
        $region = Region::create(['name' => 'Davao City']);

        return collect(range(1, $count))->map(fn ($i) => Destination::create([
            'slug' => "destination-{$i}", 'name' => "Destination {$i}", 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Mid-range',
        ]))->all();
    }

    private function validPayload(array $overrides = []): array
    {
        return $overrides + [
            'overall_rating' => 5,
            'would_recommend' => 'Yes',
        ];
    }

    public function test_the_recap_shows_the_exact_places_reported_visited(): void
    {
        [$a, $b, $c] = $this->seedDestinations();

        $this->post(route('exit-survey.store'), $this->validPayload([
            'places_visited' => ["destination:{$a->id}", "destination:{$b->id}"],
        ]));

        $html = $this->get(route('exit-survey.recap'))->assertOk()->getContent();
        // Scoped to the "Places You Visited" section -- Destination 3 (the
        // one NOT reported visited) legitimately appears later, in "You
        // Might Have Missed", so a whole-page substring check would be
        // testing the wrong thing.
        $visitedSection = $this->sectionBetween($html, 'Places You Visited', 'You Might Have Missed');

        $this->assertStringContainsString('Destination 1', $visitedSection);
        $this->assertStringContainsString('Destination 2', $visitedSection);
        $this->assertStringNotContainsString('Destination 3', $visitedSection);
    }

    private function sectionBetween(string $html, string $start, string $end): string
    {
        $startPos = strpos($html, $start);
        $endPos = strpos($html, $end, $startPos ?: 0);

        return substr($html, $startPos ?: 0, $endPos !== false ? $endPos - $startPos : null);
    }

    public function test_the_day_count_is_shown_only_when_provided(): void
    {
        $this->post(route('exit-survey.store'), $this->validPayload(['actual_days_stayed' => 4]));

        $this->get(route('exit-survey.recap'))->assertOk()->assertSee('4');
    }

    public function test_no_day_count_is_shown_when_not_provided(): void
    {
        $this->post(route('exit-survey.store'), $this->validPayload());

        $html = $this->get(route('exit-survey.recap'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Day', $this->extractDaysBlock($html));
    }

    private function extractDaysBlock(string $html): string
    {
        // Only assert against the stat block itself -- "Day" legitimately
        // appears elsewhere on the page (nav, footer copy) regardless of
        // whether a day count was reported.
        preg_match('/id="tripCard".*?<\/div>\s*<\/div>/s', $html, $m);

        return $m[0] ?? '';
    }

    public function test_a_survey_with_no_places_reported_shows_a_graceful_generic_recap(): void
    {
        $this->post(route('exit-survey.store'), $this->validPayload());

        $this->get(route('exit-survey.recap'))
            ->assertOk()
            ->assertSee('Thanks for your feedback');
    }

    public function test_missed_destinations_exclude_what_was_already_visited(): void
    {
        [$a, $b, $c] = $this->seedDestinations();

        $this->post(route('exit-survey.store'), $this->validPayload([
            'places_visited' => ["destination:{$a->id}"],
        ]));

        $html = $this->get(route('exit-survey.recap'))->assertOk()->getContent();

        $this->assertStringContainsString('You Might Have Missed', $html);
        $this->assertStringContainsString('Destination 2', $html);
        $this->assertStringContainsString('Destination 3', $html);
    }

    public function test_the_missed_section_is_hidden_when_nothing_is_left_to_suggest(): void
    {
        $only = $this->seedDestinations(1)[0];

        $this->post(route('exit-survey.store'), $this->validPayload([
            'places_visited' => ["destination:{$only->id}"],
        ]));

        $this->get(route('exit-survey.recap'))
            ->assertOk()
            ->assertDontSee('You Might Have Missed');
    }

    public function test_the_missed_list_is_personalized_when_a_preference_is_linked(): void
    {
        $this->seedDestinations();
        $preference = TouristPreference::create([
            'travel_days' => 2, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
        ]);
        $this->withSession([TripPlannerController::PREFERENCE_KEY => $preference->id]);

        $this->post(route('exit-survey.store'), $this->validPayload());

        $this->get(route('exit-survey.recap'))
            ->assertOk()
            ->assertSee('Based on your trip');
    }

    public function test_the_missed_list_is_framed_as_popular_with_no_linked_preference(): void
    {
        $this->seedDestinations();

        $this->post(route('exit-survey.store'), $this->validPayload());

        $this->get(route('exit-survey.recap'))
            ->assertOk()
            ->assertSee('popular places')
            ->assertDontSee('Based on your trip');
    }

    public function test_direct_navigation_with_no_prior_submission_redirects_home(): void
    {
        $this->get(route('exit-survey.recap'))->assertRedirect(route('home'));
    }

    public function test_the_recap_never_exposes_the_visitor_token(): void
    {
        [$a] = $this->seedDestinations(1);
        $token = 'super-secret-visitor-token-value';

        $this->withCookie(EnsureVisitorToken::COOKIE, $token)
            ->post(route('exit-survey.store'), $this->validPayload(['places_visited' => ["destination:{$a->id}"]]));

        $html = $this->get(route('exit-survey.recap'))->assertOk()->getContent();

        $this->assertStringNotContainsString($token, $html);
    }

    public function test_the_recap_never_exposes_the_surveys_own_answers(): void
    {
        [$a] = $this->seedDestinations(1);

        $this->post(route('exit-survey.store'), $this->validPayload([
            'places_visited' => ["destination:{$a->id}"],
            'comments' => 'A very distinctive private comment nobody else should see.',
        ]));

        $html = $this->get(route('exit-survey.recap'))->assertOk()->getContent();

        $this->assertStringNotContainsString('A very distinctive private comment', $html);
    }

    /** A logged-in tourist gets the same recap, and the survey stays unlinked from their account. */
    public function test_a_logged_in_tourist_gets_the_same_recap_without_the_survey_being_linked(): void
    {
        [$a] = $this->seedDestinations(1);
        $tourist = TouristAccount::create(['alias' => 'explorer1', 'password_hash' => Hash::make('password123')]);
        $this->actingAs($tourist, 'tourist');

        $this->post(route('exit-survey.store'), $this->validPayload(['places_visited' => ["destination:{$a->id}"]]));

        $this->get(route('exit-survey.recap'))->assertOk()->assertSee('Destination 1');

        $survey = \App\Models\ExitSurvey::sole();
        $this->assertArrayNotHasKey('tourist_account_id', $survey->getAttributes());
    }
}
