<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The plan page keeps an unfinished draft on the visitor's own device and offers to pick it up again. The card is
 * rendered hidden and only shown by the script when a draft exists; the draft is limited to trip basics.
 */
class PlanResumeDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_resume_card_is_on_the_page_but_hidden_until_a_draft_exists(): void
    {
        $this->get(route('plan.edit'))
            ->assertOk()
            ->assertSee('id="planResume"', false)
            ->assertSee('data-draft-owner="guest"', false)
            ->assertSee('Dates, location and health details aren', false)
            ->assertSeeInOrder(['id="planResume"', 'hidden>'], false);
    }

    public function test_the_card_uses_the_compressed_sprite(): void
    {
        $this->get(route('plan.edit'))->assertSee('images/davo-resume-frames.webp', false)->assertDontSee('davo-resume-frames.png', false);
        $this->assertFileExists(public_path('images/davo-resume-frames.webp'));
    }

    public function test_the_draft_only_ever_stores_low_sensitivity_trip_choices(): void
    {
        $script = file_get_contents(public_path('js/plan-wizard.js'));
        preg_match('/const draftNames = \[(.*?)\];/s', $script, $match);

        $this->assertNotEmpty($match, 'The draft field list is missing from plan-wizard.js.');

        foreach (['start_date', 'arrival_time', 'place_of_origin', 'origin_lat', 'origin_lng', 'origin_label', 'health', 'accessibility', 'travel_purpose'] as $private) {
            $this->assertStringNotContainsString($private, $match[1], "The draft must not store $private.");
        }

        foreach (['travel_days', 'budget', 'activities[]'] as $basic) {
            $this->assertStringContainsString($basic, $match[1]);
        }
    }
}
