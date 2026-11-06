<?php

namespace Tests\Feature;

use App\Models\Advisory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The phone drawer: search up top, the same links in the same order, Log in / Saved as pill buttons, the account links,
 * and Plan My Trip pinned at the bottom. "N active" appears on Advisories only when something is active.
 */
class MobileDrawerRedesignTest extends TestCase
{
    use RefreshDatabase;

    private function drawer(): string
    {
        $html = $this->get('/destinations')->assertOk()->getContent();

        return substr($html, strpos($html, 'id="mobileMenuOverlay"'));
    }

    public function test_the_drawer_keeps_every_link_in_its_order(): void
    {
        $drawer = $this->drawer();
        $last = -1;

        foreach (['Destinations', 'Stays', 'Dining', 'Packages', 'More', 'Souvenir Centers', 'Tour Operators', 'Events', 'Advisories', 'Log in', 'Saved', 'Create a free account', 'List your establishment', 'Plan My Trip'] as $label) {
            $at = strpos($drawer, $label, $last + 1);
            $this->assertNotFalse($at, "{$label} is in the drawer, after the one before it.");
            $last = $at;
        }
    }

    public function test_it_has_a_search_that_goes_to_the_site_search(): void
    {
        $drawer = $this->drawer();

        $this->assertStringContainsString('role="search"', $drawer);
        $this->assertStringContainsString('action="'.route('search').'"', $drawer);
        $this->assertStringContainsString('placeholder="Search Davao Region"', $drawer);
        $this->assertStringContainsString('name="q"', $drawer);
    }

    public function test_more_is_a_disclosure_with_its_links_hidden_until_opened(): void
    {
        $drawer = $this->drawer();

        $this->assertMatchesRegularExpression('/<button type="button" class="nav-more__toggle[^"]*" aria-expanded="false" aria-controls="mobile-more-links"/', $drawer);
        $this->assertStringContainsString('id="mobile-more-links" hidden', $drawer);
    }

    public function test_log_in_and_saved_are_pills_and_the_account_links_are_grouped(): void
    {
        $drawer = $this->drawer();

        $this->assertSame(2, substr_count($drawer, 'class="mobile-menu__pill"'), 'Log in and Saved.');
        $this->assertStringContainsString('id="mobileMenuAccount"', $drawer);
        $this->assertStringContainsString('aria-labelledby="mobileMenuAccount"', $drawer);
    }

    public function test_no_active_advisories_means_no_urgent_label(): void
    {
        $this->assertStringNotContainsString('mobile-menu__urgent', $this->drawer());
    }

    public function test_an_active_advisory_shows_the_dot_and_the_active_count(): void
    {
        Advisory::create(['title' => 'Road closure', 'message' => 'The road is closed.', 'severity' => 'info']);

        $drawer = $this->drawer();

        $this->assertStringContainsString('class="mobile-menu__pip"', $drawer);
        $this->assertMatchesRegularExpression('/class="mobile-menu__urgent"[^>]*>1 active</', $drawer);
    }
}
