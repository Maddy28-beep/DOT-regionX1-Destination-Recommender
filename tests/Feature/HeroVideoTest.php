<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesHeroFixtures;
use Tests\TestCase;

/**
 * Hero footage is optional and additive: drop a file at public/video/hero.mp4
 * (and no Mount Apo photograph is installed) and the landing page uses it, remove it and the painted hero is exactly as
 * it was.
 *
 * The painted hero is withheld server-side via .has-footage whenever a clip
 * exists, and restored by the inline script in welcome.blade.php when it
 * decides against the video -- reduced motion, a metered connection,
 * or a clip that will not decode. That inversion matters: a browser holds even
 * an inline script until pending stylesheets apply, so anything hidden by
 * script alone is painted first, and the illustration flashed on every load.
 */
class HeroVideoTest extends TestCase
{
    use RefreshDatabase;
    use UsesHeroFixtures;

    /**
     * The footage and the photograph are looked up in a temporary folder (config/hero.php, UsesHeroFixtures), never in
     * public/. Real footage and the real photograph may be installed on the machine running these tests, and an earlier
     * version of this file moved and deleted those paths itself: a failed rename on Windows once left the real hero video
     * deleted and stopped the database rollback of every test after it.
     */
    private function putVideo(string $file = 'hero.mp4'): void
    {
        $this->putHeroFile($file);
    }

    public function test_the_photograph_takes_precedence_over_footage(): void
    {
        $this->putHeroPhoto();
        $this->putVideo();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('has-photo', $html);
        $this->assertStringNotContainsString('<video class="poster-hero__video"', $html);
        $this->assertStringNotContainsString('has-footage', preg_match('/<section class="poster-hero[^"]*"/', $html, $m) ? $m[0] : '');
    }

    public function test_the_illustrated_hero_stands_alone_when_no_footage_is_supplied(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('poster-hero__video', $html);
        $this->assertStringNotContainsString('poster-hero__horizon', $html, 'No painted mountain band over the hero.');
        $this->assertStringNotContainsString('poster-hero__banca', $html, 'No painted boat either.');
    }

    public function test_footage_is_offered_once_a_file_exists(): void
    {
        $this->putVideo();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('poster-hero__video', $html);
        $this->assertStringContainsString('data-mp4', $html);
    }

    /**
     * The markup must never carry a src: a browser starts downloading one
     * before any script can decide whether this visit should pay for it,
     * which would defeat the reduced-motion and metered-connection checks entirely.
     */
    public function test_the_markup_carries_no_source_for_the_browser_to_preload(): void
    {
        $this->putVideo();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<video[^>]*class="poster-hero__video"[^>]*>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<video[^>]*class="poster-hero__video"[^>]*\ssrc=/', $html);
        $this->assertStringContainsString('preload="none"', $html);
    }

    /** Autoplay is blocked everywhere without these, and noise is not wanted anyway. */
    public function test_the_footage_is_muted_looping_and_inline(): void
    {
        $this->putVideo();

        $html = $this->get('/')->assertOk()->getContent();

        foreach (['muted', 'loop', 'playsinline'] as $attribute) {
            $this->assertStringContainsString($attribute, $html);
        }

        $this->assertStringContainsString('aria-hidden="true"', $html,
            'Decorative footage should not be announced to a screen reader.');
    }

    public function test_a_webm_is_offered_only_when_one_exists(): void
    {
        $this->putVideo();

        $this->assertStringNotContainsString('data-webm', $this->get('/')->assertOk()->getContent());

        $this->putVideo('hero.webm');

        $this->assertStringContainsString('data-webm', $this->get('/')->assertOk()->getContent());
    }

    /**
     * The still is the section's background, never a <video poster>.
     *
     * A poster paints over the video element itself, which is one layer above
     * the hero's ground -- so it arrives and departs as its own visible step.
     * As the background it is simply what the video paints onto, and since the
     * still IS the clip's first frame the two are identical: there is no
     * moment of change to see.
     */
    public function test_the_still_is_the_heros_ground_not_a_video_poster(): void
    {
        $this->putVideo();
        $this->putVideo('hero-poster.jpg');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/<video[^>]*\sposter=/', $html,
            'A <video poster> would be a separate visible step over the ground.');
        $this->assertMatchesRegularExpression('/<section[^>]*poster-hero[^>]*background-image:\s*url/', $html);
    }

    /** Fetched alongside the stylesheet, so the ground is ready at first paint. */
    public function test_the_still_is_preloaded(): void
    {
        $this->putVideo();
        $this->putVideo('hero-poster.jpg');

        $this->assertMatchesRegularExpression(
            '/<link[^>]*rel="preload"[^>]*as="image"[^>]*hero-poster/',
            $this->get('/')->assertOk()->getContent()
        );
    }

    /**
     * Phones get the footage too: the clip is well under a megabyte, and most visitors are on a phone. What still
     * opts a visit out is reduced motion, Data Saver and a slow connection -- never the width of the screen.
     */
    public function test_a_narrow_screen_is_not_a_reason_to_skip_the_footage(): void
    {
        $this->putVideo();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('min-width: 900px', $html);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $html);
        $this->assertStringContainsString('saveData', $html);
        $this->assertStringContainsString('(2g|3g)', $html);
    }

    /** Without a still there is nothing to paint onto, so no background is set. */
    public function test_no_background_is_set_when_the_still_is_missing(): void
    {
        $this->putVideo();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('poster-hero__video', $html);
        $this->assertStringNotContainsString('background-image', $html);
        $this->assertStringNotContainsString('hero-poster', $html);
    }
}
