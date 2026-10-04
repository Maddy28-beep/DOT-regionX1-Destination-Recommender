<?php

namespace Tests\Feature;

use App\Models\Advisory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The advisory strip lets a traveller step through several active notices by hand
 * ("1 of 3"), and the Advisories nav link carries how many are live.
 */
class AdvisoryRibbonTest extends TestCase
{
    use RefreshDatabase;

    private function advisory(string $title, string $severity): Advisory
    {
        return Advisory::create(['title' => $title, 'message' => 'Details here.', 'severity' => $severity]);
    }

    public function test_one_advisory_shows_no_counter_or_arrows(): void
    {
        $this->advisory('Road closed', 'danger');

        $this->get('/destinations')->assertOk()
            ->assertSee('Road closed')
            ->assertDontSee('data-advisory-pager', false);
    }

    public function test_several_advisories_show_a_counter_and_arrows_most_urgent_first(): void
    {
        $this->advisory('General notice', 'info');
        $this->advisory('Trail closure', 'danger');
        $this->advisory('Heavy rain', 'warning');

        $html = $this->get('/destinations')->assertOk()->getContent();

        $this->assertStringContainsString('data-advisory-pager', $html);
        $this->assertStringContainsString('of 3', $html);
        $this->assertStringContainsString('aria-label="Next advisory"', $html);
        $this->assertStringContainsString('advisory-ribbon--danger', $html);
        $this->assertLessThan(strpos($html, 'Heavy rain'), strpos($html, 'Trail closure'));
        $this->assertLessThan(strpos($html, 'General notice'), strpos($html, 'Heavy rain'));
    }

    public function test_nothing_rotates_by_itself(): void
    {
        $this->advisory('One', 'info');
        $this->advisory('Two', 'info');

        $html = $this->get('/destinations')->assertOk()->getContent();

        $this->assertStringNotContainsString('setInterval', $html);
    }

    public function test_the_nav_link_shows_how_many_advisories_are_active(): void
    {
        $this->advisory('One', 'info');
        $this->advisory('Two', 'warning');

        $html = $this->get('/destinations')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/main-nav__dot--count"[^>]*>\s*2\s*</', $html);
        $this->assertStringContainsString('(2 active advisories)', $html);
    }

    public function test_there_is_no_count_when_nothing_is_active(): void
    {
        $this->get('/destinations')->assertOk()
            ->assertDontSee('main-nav__dot--count', false)
            ->assertDontSee('advisory-ribbon__items', false);
    }
}
