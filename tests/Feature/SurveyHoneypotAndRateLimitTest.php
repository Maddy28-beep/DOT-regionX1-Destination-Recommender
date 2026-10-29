<?php

namespace Tests\Feature;

use App\Http\Controllers\ExitSurveyController;
use App\Models\ExitSurvey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The public exit survey needs no login, so a script can post to it (a sales pitch once arrived as a "real" survey).
 * A hidden honeypot field and a per-address limit keep scripts out without getting in a visitor's way.
 */
class SurveyHoneypotAndRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $extra = []): array
    {
        return array_merge(['overall_rating' => 5, 'would_recommend' => 'Yes', 'travel_purpose' => 'Leisure'], $extra);
    }

    public function test_the_form_carries_a_hidden_honeypot_field_that_a_visitor_never_fills(): void
    {
        $html = $this->get(route('exit-survey.create'))->assertOk()->getContent();

        $this->assertStringContainsString('name="'.ExitSurveyController::HONEYPOT_FIELD.'"', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
        $this->assertStringContainsString('class="hp-field"', $html);
    }

    public function test_a_filled_honeypot_is_thanked_and_nothing_is_saved(): void
    {
        $this->withCookie('visitor_token', (string) Str::uuid())
            ->post(route('exit-survey.store'), $this->payload([ExitSurveyController::HONEYPOT_FIELD => 'http://example.com', 'comments' => 'Buy our SEO services']))
            ->assertRedirect(route('exit-survey.create'))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, ExitSurvey::withoutGlobalScopes()->count());
    }

    public function test_an_empty_honeypot_does_not_get_in_the_way(): void
    {
        $this->withCookie('visitor_token', (string) Str::uuid())
            ->post(route('exit-survey.store'), $this->payload([ExitSurveyController::HONEYPOT_FIELD => '']))
            ->assertRedirect(route('exit-survey.recap'));

        $this->assertSame(1, ExitSurvey::count());
    }

    public function test_one_address_can_send_ten_surveys_an_hour_and_no_more(): void
    {
        // Trial-mode surveys skip the one-per-browser lock, so only the per-address limit is being tested.
        foreach (range(1, 10) as $i) {
            $this->withCookie('visitor_token', (string) Str::uuid())
                ->post(route('exit-survey.store'), $this->payload(['test' => 1]))
                ->assertRedirect(route('exit-survey.recap'));
        }

        $this->withCookie('visitor_token', (string) Str::uuid())
            ->postJson(route('exit-survey.store'), $this->payload(['test' => 1]))
            ->assertStatus(429);

        $this->assertSame(10, ExitSurvey::withoutGlobalScopes()->count());
    }
}
