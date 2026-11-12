<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The EN / FIL switch in the top bar: it remembers the choice, returns to the page, and changes the bar's own words. */
class LanguageSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bar_offers_both_languages_and_starts_in_english(): void
    {
        $this->get('/')->assertOk()
            ->assertSee(route('locale.switch', 'en'), false)
            ->assertSee(route('locale.switch', 'fil'), false)
            ->assertSee('Plan My Trip')
            ->assertDontSee('Planuhin ang Biyahe');
    }

    public function test_choosing_filipino_is_remembered_and_returns_to_the_page_the_visitor_was_on(): void
    {
        $this->from(route('destinations.index'))->get(route('locale.switch', 'fil'))->assertRedirect(route('destinations.index'));
        $this->assertSame('fil', session('locale'));

        $this->get(route('destinations.index'))->assertOk()
            ->assertSee('Mga Destinasyon')->assertSee('Matutuluyan')->assertSee('Kainan')->assertSee('Mga Paalala')
            ->assertSee('Planuhin ang Biyahe')->assertSee('Mag-log in');
    }

    public function test_switching_back_to_english_restores_the_words(): void
    {
        $this->get(route('locale.switch', 'fil'));
        $this->get(route('locale.switch', 'en'))->assertRedirect();

        $this->get('/')->assertSee('Plan My Trip')->assertSee('Log in')->assertDontSee('Mag-log in');
    }

    public function test_the_current_language_is_marked(): void
    {
        $this->get(route('locale.switch', 'fil'));

        $html = $this->get('/')->getContent();
        $this->assertMatchesRegularExpression('/lang="fil"\s+aria-current="true"/', $html);
        $this->assertDoesNotMatchRegularExpression('/lang="en"\s+aria-current="true"/', $html);
    }

    public function test_an_unknown_language_is_not_found_and_changes_nothing(): void
    {
        $this->get(route('locale.switch', 'xx'))->assertNotFound();
        $this->get(route('locale.switch', '<script>'))->assertNotFound();
        $this->assertNull(session('locale'));
    }

    public function test_a_visit_with_no_choice_made_is_english(): void
    {
        $this->get('/accommodations')->assertOk();
        $this->assertSame('en', app()->getLocale());
    }

    public function test_every_phrase_in_the_filipino_file_is_used_by_the_bar(): void
    {
        $phrases = array_keys(json_decode(file_get_contents(base_path('lang/fil.json')), true));
        $this->get(route('locale.switch', 'fil'));
        $page = $this->get('/')->getContent();
        preg_match('/<header class="site-header">.*?<\/header>/s', $page, $bar);
        $html = $bar[0] ?? '';
        $this->assertNotSame('', $html, 'the top bar is on the page');

        foreach (['Destinations', 'Stays', 'Dining', 'Packages', 'More', 'Advisories', 'Log in', 'Plan My Trip'] as $phrase) {
            $this->assertContains($phrase, $phrases, "{$phrase} has a Filipino line");
            $this->assertStringNotContainsString('>'.$phrase.'<', $html, "{$phrase} is not left in English");
        }
    }
}
