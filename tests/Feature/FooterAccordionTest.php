<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The footer's three link groups are accordions on a phone, with every link and label kept. */
class FooterAccordionTest extends TestCase
{
    use RefreshDatabase;

    private function footer(): string
    {
        $html = $this->get('/')->assertOk()->getContent();

        return substr($html, strpos($html, '<footer class="site-footer"'));
    }

    public function test_each_group_has_a_button_that_controls_its_links(): void
    {
        $footer = $this->footer();

        foreach (['footer-travelers' => 'For Travelers', 'footer-partners' => 'For Partners', 'footer-dot' => 'Department of Tourism'] as $id => $label) {
            $this->assertMatchesRegularExpression('/<button type="button" class="footer-acc__btn" aria-expanded="true" aria-controls="'.$id.'">'.preg_quote($label, '/').'/', $footer);
            $this->assertStringContainsString('id="'.$id.'"', $footer);
        }
    }

    public function test_the_travelers_group_opens_first_and_the_others_start_closed(): void
    {
        $footer = $this->footer();

        $this->assertStringContainsString('data-footer-acc="open"', $footer);
        $this->assertSame(2, substr_count($footer, 'data-footer-acc="closed"'));
    }

    public function test_every_link_label_is_still_there(): void
    {
        $footer = $this->footer();

        foreach ([
            'Plan a trip', 'Browse destinations', 'Browse accommodations', 'Browse restaurants', 'Browse tour packages', 'Browse souvenir centers',
            'Browse tour operators', 'Browse events', 'Saved places', 'Share your feedback', 'List your establishment', 'Partner / DOT login',
            'Tourism analytics &amp; accreditation monitoring', 'Privacy Policy', 'Terms of Service', 'Data Privacy Act (RA 10173) Notice', 'Accessibility Statement',
            'Follow DOT Davao',
        ] as $label) {
            $this->assertStringContainsString($label, $footer, $label);
        }
    }

    public function test_the_four_social_links_are_icon_only_with_labels(): void
    {
        $footer = $this->footer();

        foreach (['Facebook', 'Instagram', 'YouTube', 'TikTok'] as $name) {
            $this->assertStringContainsString('aria-label="DOT Davao on '.$name.' (opens in a new tab)"', $footer);
        }
    }
}
