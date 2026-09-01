<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The chat panel must start closed on every fresh page load.
 *
 * It carries the `hidden` HTML attribute, but `.chatbot-panel { display:
 * flex }` in the widget's own stylesheet has the same specificity as the
 * browser's built-in `[hidden] { display: none }` rule -- and an author rule
 * wins that tie regardless of source order. Without an explicit
 * `.chatbot-panel[hidden] { display: none }` override, the attribute did
 * nothing and the panel rendered open on every navigation until a visitor
 * clicked to close it once.
 */
class ChatbotWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_panel_has_a_hidden_attribute_and_a_css_rule_that_actually_hides_it(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('id="chatbot-panel" class="chatbot-panel" hidden', $html);

        // Guards the CSS specificity fix itself, not just the HTML attribute:
        // a `.chatbot-panel { display: flex }` rule alone is not enough.
        $this->assertMatchesRegularExpression(
            '/\.chatbot-panel\[hidden\]\s*\{\s*display:\s*none;?\s*\}/',
            $html,
            'The stylesheet must explicitly override [hidden] for the chat panel, '.
            'or a same-specificity display:flex rule silently wins and it shows on every page load.'
        );
    }
}
