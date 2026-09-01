<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The footer used to claim "No traveler accounts, no personal data
 * collected" right next to a citation of RA 10173 -- inaccurate, since the
 * trip planner can collect optional health/accessibility data with consent,
 * and establishment partners do have real accounts. The blanket denial is
 * replaced with a neutral copyright line plus links to real legal pages that
 * describe what is actually collected.
 */
class FooterTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_footer_no_longer_makes_a_blanket_no_data_claim(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringNotContainsString('No traveler accounts, no personal data collected', $html);
    }

    public function test_the_footer_links_to_all_four_legal_pages(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString(route('legal.privacy'), $html);
        $this->assertStringContainsString(route('legal.terms'), $html);
        $this->assertStringContainsString(route('legal.accessibility'), $html);
        $this->assertStringContainsString('#ra-10173', $html);
    }

    public function test_the_footer_brand_mark_matches_the_nav_icon(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('footer-brand-icon', $html);
    }

    public function test_the_privacy_policy_page_renders(): void
    {
        $this->get(route('legal.privacy'))->assertOk();
    }

    public function test_the_terms_of_service_page_renders(): void
    {
        $this->get(route('legal.terms'))->assertOk();
    }

    public function test_the_accessibility_statement_page_renders(): void
    {
        $this->get(route('legal.accessibility'))->assertOk();
    }

    /** The privacy policy is the one page making factual claims about data
     *  practices -- lock in the specific, verified facts so a future edit
     *  can't quietly reintroduce an inaccurate blanket claim. */
    public function test_the_privacy_policy_describes_the_real_data_practices(): void
    {
        $html = $this->get(route('legal.privacy'))->getContent();

        $this->assertStringContainsString('There is no traveler account', $html);
        $this->assertStringContainsString('Health or accessibility information', $html);
        $this->assertStringContainsString('establishment', $html);
        $this->assertStringContainsString('id="ra-10173"', $html);
    }
}
