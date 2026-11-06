<?php

namespace Tests\Feature;

use Tests\TestCase;

/** The swap panel opens under the card with a header of its own, and the swap button points at it. */
class SwapPanelScriptTest extends TestCase
{
    public function test_the_panel_has_its_own_header_and_a_close_button(): void
    {
        $js = file_get_contents(public_path('js/itinerary-swap.js'));

        $this->assertStringContainsString('Similar places', $js);
        $this->assertStringContainsString('Ranked by how closely they match this stop', $js);
        $this->assertStringContainsString("'Close similar places'", $js);
        $this->assertStringContainsString("'Use this instead'", $js);
    }

    public function test_an_alternative_shows_its_match_chip_and_an_amber_sample_pill_and_neutral_reason(): void
    {
        $js = file_get_contents(public_path('js/itinerary-swap.js'));

        $this->assertStringContainsString('swap-chip', $js);
        $this->assertStringContainsString("'Sample data'", $js);
        $this->assertStringContainsString('swap-option__reason', $js);

        $css = file_get_contents(public_path('css/itinerary-redesign.css'));
        $this->assertMatchesRegularExpression('/\.swap-panel \.swap-option__reason \{[^}]*color: #5f6b66/', $css, 'The interest line is neutral gray, never green.');
    }

    public function test_the_swap_button_is_linked_to_its_panel(): void
    {
        $blade = file_get_contents(resource_path('views/partials/itinerary-timeline-row.blade.php'));

        $this->assertStringContainsString('aria-controls="swap-panel-{{ $item->id }}"', $blade);
        $this->assertStringContainsString('id="swap-panel-{{ $item->id }}"', $blade);
        $this->assertStringContainsString('aria-expanded="false"', $blade);
    }
}
