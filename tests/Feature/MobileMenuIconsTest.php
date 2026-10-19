<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The phone drawer puts an icon in front of every link; the top bar, which shares the same link list, does not.
 */
class MobileMenuIconsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: string} the page's top bar and its drawer */
    private function parts(): array
    {
        $html = $this->get('/destinations')->assertOk()->getContent();
        $split = strpos($html, 'id="mobileMenuOverlay"');

        $this->assertNotFalse($split, 'The drawer is on the page.');

        return [substr($html, 0, $split), substr($html, $split)];
    }

    public function test_every_primary_link_in_the_drawer_has_an_icon(): void
    {
        [, $drawer] = $this->parts();

        // Destinations, Stays, Dining, Packages, More, Souvenir Centers, Tour Operators, Events, Advisories
        $this->assertGreaterThanOrEqual(9, substr_count($drawer, 'class="mobile-menu__icon"'));

        foreach (['Destinations', 'Stays', 'Dining', 'Packages', 'More', 'Souvenir Centers', 'Tour Operators', 'Events', 'Advisories'] as $label) {
            $this->assertMatchesRegularExpression('/mobile-menu__icon[^>]*>.*?'.preg_quote($label, '/').'/s', $drawer, "{$label} should carry an icon.");
        }
    }

    public function test_the_account_and_plan_links_have_icons_too(): void
    {
        [, $drawer] = $this->parts();

        foreach (['Log in', 'Saved', 'Create a free account', 'List your establishment', 'Plan My Trip'] as $label) {
            $this->assertMatchesRegularExpression('/<svg[^>]*>.*?<\/svg>\s*'.preg_quote($label, '/').'/s', $drawer, "{$label} should carry an icon.");
        }
    }

    public function test_the_top_bar_does_not_get_the_icons(): void
    {
        [$topBar] = $this->parts();

        $this->assertStringNotContainsString('mobile-menu__icon', $topBar);
        $this->assertMatchesRegularExpression('/aria-current="page"\s*>Destinations<\/a>/', $topBar, 'The top bar link is plain text, as before.');
    }

    public function test_the_current_page_is_still_marked_for_screen_readers(): void
    {
        [, $drawer] = $this->parts();

        $this->assertMatchesRegularExpression('/aria-current="page"\s*>\s*<span class="mobile-menu__icon"/', $drawer);
        $this->assertStringContainsString('aria-hidden="true"><svg', $drawer, 'The icons themselves are hidden from screen readers.');
    }
}
