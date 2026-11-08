<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mobile speed (PageSpeed Insights scored the live landing page 67 on mobile). What was slow, and what now keeps it from
 * coming back:
 *   - the hero clip (0.6 MB) started downloading beside the stylesheets and the poster, so on a phone connection it delayed
 *     the first paint; on a phone it now starts after the page has loaded (a computer still starts it straight away);
 *   - the hero's poster image was preloaded without a priority, so it queued behind other downloads;
 *   - Leaflet (144 KB of script plus a stylesheet) loaded with the page for one small map near the foot of it; it now loads
 *     when the map is about to scroll into view;
 *   - the slideshow dots were 9 px buttons (too small to tap); they are 24 px;
 *   - the map caption was too faint to read;
 *   - the server sent stylesheets and scripts uncompressed and with no cache lifetime (deploy/speed-up-static-files.sh).
 */
class MobilePerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function home(): string
    {
        // The map section is only drawn when there is a province with something accredited in it.
        $region = Region::create(['name' => 'Davao City']);
        Destination::create([
            'slug' => 'eden-nature-park', 'name' => 'Eden Nature Park', 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Mid-range',
            'latitude' => 7.0206, 'longitude' => 125.4103, 'distance_km' => 12,
        ]);

        return $this->get('/')->assertOk()->getContent();
    }

    public function test_the_landing_page_no_longer_loads_leaflet_up_front(): void
    {
        $html = $this->home();

        $this->assertDoesNotMatchRegularExpression('/<(?:script|link)\b[^>]*leaflet[^>]*>/i', $html);
        // It is fetched by the map's own script when the map is about to be seen.
        $this->assertStringContainsString('function loadLeaflet', $html);
        $this->assertStringContainsString('IntersectionObserver', $html);
    }

    public function test_the_map_still_has_its_container_and_a_no_script_fallback(): void
    {
        $html = $this->home();

        $this->assertStringContainsString('id="region-map"', $html);
        $this->assertStringContainsString('The interactive map needs JavaScript', $html);
    }

    public function test_the_hero_poster_is_preloaded_at_high_priority(): void
    {
        $html = $this->home();

        if (! file_exists(public_path('video/hero.mp4'))) {
            $this->markTestSkipped('No hero clip in this checkout.');
        }

        $this->assertMatchesRegularExpression('/<link rel="preload" as="image" href="[^"]*hero-poster\.jpg" fetchpriority="high">/', $html);
    }

    public function test_the_hero_clip_waits_for_the_load_event_on_a_phone_only(): void
    {
        if (! file_exists(public_path('video/hero.mp4'))) {
            $this->markTestSkipped('No hero clip in this checkout.');
        }
        $html = $this->home();

        // Everything that touches the network is inside begin(); a phone waits for 'load', a computer runs it at once.
        $this->assertStringContainsString('var begin = function', $html);
        $this->assertStringContainsString("window.matchMedia('(max-width: 767px)').matches && document.readyState !== 'complete'", $html);
        $this->assertStringContainsString("window.addEventListener('load', begin, { once: true });", $html);
        // The clip's network calls exist once, inside begin(), not a second time outside it.
        $this->assertSame(1, substr_count($html, 'video.load();'));
        $this->assertSame(1, substr_count($html, 'var giveUp'));
    }

    public function test_the_slideshow_dots_are_real_touch_targets(): void
    {
        $css = file_get_contents(public_path('css/mood-cards.css'));

        $this->assertMatchesRegularExpression('/\.moods__dot\s*\{[^}]*width:\s*24px;\s*height:\s*24px/s', $css);
        $this->assertStringContainsString('.moods__dot::before', $css);
    }

    public function test_the_map_caption_is_dark_enough_to_read(): void
    {
        $css = file_get_contents(public_path('css/app.css'));

        $this->assertMatchesRegularExpression('/\.about-map:has\(\.region-map\) \.about-map__caption \{ font-size: \.85rem; color: #8a2f0f; \}/', $css);
        $this->assertStringNotContainsString('.about-map__caption { font-size: .85rem; opacity: .85; }', $css);
    }

    public function test_the_server_script_compresses_and_caches_and_is_safe_to_run(): void
    {
        $path = base_path('deploy/speed-up-static-files.sh');
        $script = file_get_contents($path);

        // It only looks unless told to apply, tests nginx before reloading, and puts the original back on failure.
        $this->assertStringContainsString('--apply', $script);
        $this->assertStringContainsString('nginx -t', $script);
        $this->assertStringContainsString('cp -a "/root/nginx-site-before-speed-$STAMP" "$SITE"', $script);
        $this->assertStringContainsString('Look only: nothing was changed', $script);

        // What it turns on.
        $this->assertStringContainsString('gzip_types text/plain text/css text/javascript application/javascript', $script);
        $this->assertStringContainsString('expires 1y;', $script);
        $this->assertStringContainsString('try_files $uri /index.php?$query_string;', $script);

        // Unix line endings, or it fails on the server.
        $this->assertStringNotContainsString("\r", $script);
        $this->assertStringStartsWith('#!/usr/bin/env bash', $script);
    }
}
