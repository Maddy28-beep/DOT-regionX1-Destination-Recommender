<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The home page is a set of screen-sized slides on a desktop window, and plain scrolling everywhere else. */
class HomeSlidesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_is_marked_up_as_slides_and_loads_the_slide_stylesheet(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="en" class="home-slides">', $html);
        $this->assertStringContainsString('css/home-slides.css', $html);
        // The two sections longer than a screen are split into two slide parts each.
        $this->assertMatchesRegularExpression('/id="destinations"[^>]*>|class="[^"]*home-split[^"]*" id="destinations"/', $html);
        $this->assertSame(4, substr_count($html, 'class="slide-part"'));
        $this->assertSame(2, substr_count($html, 'home-split'));
    }

    public function test_snapping_is_limited_to_desktop_windows_and_respects_reduced_motion(): void
    {
        $css = file_get_contents(public_path('css/home-slides.css'));

        // Snapping only inside the desktop media query (wide, not short, no reduced-motion preference).
        $this->assertStringContainsString('@media (min-width: 1024px) and (min-height: 560px) and (prefers-reduced-motion: no-preference)', $css);
        $outside = preg_replace('/@media[^{]*\{(?:[^{}]|\{[^{}]*\})*\}/', '', $css);
        $this->assertStringNotContainsString('scroll-snap-type', $outside);
        // No forced 100vh sections.
        $this->assertStringNotContainsString('100vh', $css);
    }

    public function test_other_pages_do_not_load_the_slide_stylesheet(): void
    {
        $this->assertStringNotContainsString('home-slides', $this->get(route('destinations.index'))->assertOk()->getContent());
    }
}
