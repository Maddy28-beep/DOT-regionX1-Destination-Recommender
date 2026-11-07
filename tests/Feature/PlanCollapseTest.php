<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trip form, step 1, on a phone: the optional parts (arrival time, departure details, where you start) fold behind a one-line
 * row. Nothing is removed from the form; the folding is script and CSS only, and never hides a required field.
 */
class PlanCollapseTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_form_still_carries_every_field_the_folded_parts_hold(): void
    {
        $this->get(route('plan.edit'))
            ->assertOk()
            ->assertSee('name="arrival_time"', false)
            ->assertSee('name="departure_time"', false)
            ->assertSee('name="departure_label"', false)
            ->assertSee('name="origin_label"', false)
            ->assertSee('js/plan-collapse.js', false);
    }

    public function test_the_fold_script_loads_after_the_wizard_script_that_builds_the_step(): void
    {
        $html = $this->get(route('plan.edit'))->assertOk()->getContent();

        $this->assertGreaterThan(strpos($html, 'js/plan-wizard.js'), strpos($html, 'js/plan-collapse.js'));
    }

    public function test_the_script_folds_only_the_three_optional_parts_and_opens_for_an_invalid_field(): void
    {
        $js = file_get_contents(public_path('js/plan-collapse.js'));

        foreach (['[data-arrival]', '.pc-trip-departure', '.pc-trip-start'] as $selector) {
            $this->assertStringContainsString($selector, $js);
        }
        // The required "where you're from" block is not one of them.
        $this->assertStringNotContainsString('pc-trip-visitor', $js);
        $this->assertStringContainsString("addEventListener('invalid'", $js);
    }

    public function test_the_fold_only_applies_on_a_phone(): void
    {
        $css = file_get_contents(public_path('css/plan-trip-layout.css'));

        $this->assertStringContainsString('.pc-collapse { display: none; }', $css);
        $this->assertMatchesRegularExpression('/@media \(max-width: 640px\) \{\s*\.plan-wizard #plan-step-0 \.pc-collapsible\.is-collapsed \{ display: none; \}/', $css);
    }
}
