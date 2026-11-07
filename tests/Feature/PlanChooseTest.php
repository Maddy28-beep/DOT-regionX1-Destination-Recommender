<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "How would you like to plan your trip?" page.
 */
class PlanChooseTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_offers_both_paths_with_what_each_gives(): void
    {
        $this->get(route('plan.choose'))
            ->assertOk()
            ->assertSee('Personalized Itinerary')
            ->assertSee('Tour Packages')
            ->assertSee('About 2 minutes')
            ->assertSee('3 short steps')
            ->assertSee('Best if')
            ->assertSee(route('plan.edit'), false)
            ->assertSee(route('packages.index'), false);
    }

    public function test_it_marks_one_path_as_recommended_and_helps_the_undecided(): void
    {
        $this->get(route('plan.choose'))
            ->assertOk()
            ->assertSee('Recommended')
            ->assertSee('Not sure which to pick?')
            ->assertSee('I\'m just exploring', false);
    }

    public function test_it_works_without_an_account(): void
    {
        $this->get(route('plan.choose'))->assertOk()->assertSee('No account needed');
    }

    public function test_the_steps_are_a_real_stepper_with_the_first_step_current(): void
    {
        $html = $this->get(route('plan.choose'))->assertOk()->getContent();

        $this->assertStringContainsString('<ol class="pc-stepper"', $html);
        $this->assertSame(3, substr_count($html, 'class="pc-stepper__dot"'));
        $this->assertSame(1, substr_count($html, 'aria-current="step"'), 'only step 1 is current');
        $this->assertMatchesRegularExpression('/<li aria-current="step">.*?Choose a path/s', $html);

        foreach (['Choose a path', 'Tell us about your trip', 'Get your plan'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    public function test_each_path_has_its_own_icon_and_the_quick_picks_are_buttons(): void
    {
        $html = $this->get(route('plan.choose'))->assertOk()->getContent();

        preg_match_all('/<div class="feature-stamp[^"]*">\s*<svg[^>]*>\s*<path[^>]*d="([^"]+)"/', $html, $icons);

        $this->assertCount(2, $icons[1]);
        $this->assertNotSame($icons[1][0], $icons[1][1], 'the two cards do not share an icon');

        foreach (['I want it built around me', "I&#039;d rather have a guide", "I&#039;m just exploring"] as $pick) {
            $this->assertStringContainsString($pick, str_replace("'", '&#039;', $html));
        }

        $this->assertSame(3, preg_match_all('/<button type="button" aria-pressed="false" data-answer=/', $html));
    }
}
