<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The long "About Davao Region" copy folds behind a button on a phone only; the text is always in the page, and the
 * button stays hidden until the script that folds it has run.
 */
class LandingReadMoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_second_paragraph_is_in_the_page_with_a_hidden_button_until_the_script_runs(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('id="aboutMoreText"', $html);
        $this->assertStringContainsString('What makes the region unusual', $html);
        $this->assertMatchesRegularExpression('/<button type="button" class="read-more" data-read-more="aboutMoreText" aria-expanded="false" aria-controls="aboutMoreText" hidden>/', $html);
        $this->assertMatchesRegularExpression('#js/read-more\.js\?v=\d+#', $html);
    }

    public function test_the_script_only_folds_after_it_has_marked_the_block(): void
    {
        $css = file_get_contents(public_path('css/landing-sections.css'));

        $this->assertStringContainsString('.about-copy.has-fold .about-copy__more:not(.is-open)', $css, 'Folding depends on the script adding has-fold.');
        $this->assertStringContainsString('has-fold', file_get_contents(public_path('js/read-more.js')));
    }
}
