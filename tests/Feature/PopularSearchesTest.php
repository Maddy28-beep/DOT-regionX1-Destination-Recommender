<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use App\Models\SearchedTerm;
use App\Support\SearchLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Popular searches" under the landing page's search card: what visitors really search for, counted anonymously, and
 * never more than four chips (one row). Until enough searches exist the row is topped up with the best-ranked places
 * and the label says "Popular:" rather than claiming they were searched.
 */
class PopularSearchesTest extends TestCase
{
    use RefreshDatabase;

    private function destination(string $slug, string $priceTier = 'Mid-range', float $rating = 4.5): Destination
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return Destination::create([
            'slug' => $slug, 'name' => ucwords(str_replace('-', ' ', $slug)), 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Nature & Adventure', 'is_accredited' => true,
            'rating' => $rating, 'review_count' => 10, 'price_tier' => $priceTier,
        ]);
    }

    private function searched(string $term, int $times, string $purpose = 'destinations', ?\DateTimeInterface $when = null): SearchedTerm
    {
        return SearchedTerm::create([
            'term' => mb_strtolower($term), 'label' => $term, 'purpose' => $purpose,
            'searches' => $times, 'last_searched_at' => $when ?? now(),
        ]);
    }

    /** @return list<string> the chip labels in order */
    private function chips(): array
    {
        $html = $this->get('/')->assertOk()->getContent();
        preg_match_all('/class="hero-popular-searches__chip">([^<]+)</', $html, $m);

        return array_map('trim', $m[1]);
    }

    private function label(): string
    {
        preg_match('/class="hero-popular-searches__label">([^<]+)</', $this->get('/')->getContent(), $m);

        return trim($m[1] ?? '');
    }

    // ---- counting ----

    public function test_a_search_that_finds_something_is_counted(): void
    {
        $this->destination('samal-island');

        $this->get('/destinations?q=samal')->assertOk();

        $row = SearchedTerm::firstOrFail();
        $this->assertSame('samal', $row->term);
        $this->assertSame('destinations', $row->purpose);
        $this->assertSame(1, $row->searches);
    }

    public function test_a_search_that_finds_nothing_is_not_counted(): void
    {
        $this->destination('samal-island');

        $this->get('/destinations?q=zzzzzz')->assertOk();

        $this->assertSame(0, SearchedTerm::count());
    }

    public function test_the_same_search_in_the_same_visit_counts_once(): void
    {
        $this->destination('samal-island');

        $this->withSession(['last_logged_search' => 'destinations|samal'])->get('/destinations?q=samal&sort=rating')->assertOk();

        $this->assertSame(0, SearchedTerm::count(), 'Re-sorting the same search is not another search.');
    }

    public function test_spelling_and_spacing_are_counted_together(): void
    {
        $this->destination('samal-island');

        $this->get('/destinations?q=Samal')->assertOk();
        // A different visit (its own session): same words, other capitals and padding.
        $this->withSession(['last_logged_search' => 'elsewhere'])->get('/destinations?q=%20SAMAL%20')->assertOk();

        $this->assertSame(1, SearchedTerm::count());
        $this->assertSame(2, SearchedTerm::first()->searches);
        $this->assertSame('Samal', SearchedTerm::first()->label, 'The first spelling is the one shown.');
    }

    public function test_personal_looking_and_tiny_terms_are_never_stored(): void
    {
        foreach (['a@b.com', 'ab', 'call 0917 123 4567', 'https://x.test'] as $term) {
            $this->assertNull(SearchLog::normalise($term), $term);
        }
        $this->assertSame('eden nature park', SearchLog::normalise("  Eden   Nature\tPark "));
    }

    public function test_later_pages_and_crawlers_are_not_counted(): void
    {
        $this->destination('samal-island');

        $this->get('/destinations?q=samal&page=2');
        $this->withHeader('User-Agent', 'Googlebot/2.1')->get('/destinations?q=samal');

        $this->assertSame(0, SearchedTerm::count());
    }

    public function test_every_catalogue_counts_its_own_searches(): void
    {
        $this->destination('samal-island');
        $this->get('/restaurants?q=nothing')->assertOk();

        $this->assertSame(0, SearchedTerm::count(), 'No restaurant matched, so nothing is counted.');
        $this->assertArrayHasKey('restaurants', SearchLog::PURPOSES);
    }

    // ---- showing ----

    public function test_a_term_needs_enough_recent_searches_to_be_popular(): void
    {
        $this->searched('rarely', SearchLog::MIN_SEARCHES - 1);
        $this->searched('stale', 50, 'destinations', now()->subDays(SearchLog::WINDOW_DAYS + 1));
        $this->searched('busy', SearchLog::MIN_SEARCHES);

        $labels = SearchLog::popular()->pluck('label')->all();

        $this->assertSame(['busy'], $labels);
    }

    public function test_the_same_words_in_two_catalogues_show_once_with_the_busier_one(): void
    {
        $this->searched('beach', 9, 'destinations');
        $this->searched('beach', 4, 'accommodations');

        $popular = SearchLog::popular();

        $this->assertCount(1, $popular);
        $this->assertStringContainsString('/destinations', $popular->first()['url']);
        $this->assertStringContainsString('q=beach', $popular->first()['url']);
    }

    public function test_four_real_searches_make_the_row_and_the_label_says_so(): void
    {
        foreach (['samal', 'eden', 'eagle', 'apo'] as $i => $term) {
            $this->searched($term, 10 - $i);
        }
        $this->destination('alpha-falls');

        $chips = $this->chips();

        $this->assertSame(['samal', 'eden', 'eagle', 'apo'], $chips);
        $this->assertSame('Popular searches:', $this->label());
    }

    public function test_with_too_few_searches_the_row_is_topped_up_and_labelled_popular(): void
    {
        foreach (['alpha-falls', 'bravo-beach', 'charlie-peak', 'delta-park'] as $i => $slug) {
            $this->destination($slug, $slug === 'delta-park' ? 'Free' : 'Mid-range', 5 - $i * 0.1);
        }
        $this->searched('bravo beach', 5);

        $chips = $this->chips();

        $this->assertCount(4, $chips);
        $this->assertSame('bravo beach', $chips[0], 'Real searches come first.');
        $this->assertNotContains('Bravo Beach', $chips, 'A place already shown as a search is not repeated.');
        $this->assertSame('Popular:', $this->label(), 'Not all four are searches, so the label does not claim they are.');
    }

    public function test_never_more_than_four_chips(): void
    {
        foreach (['alpha-falls', 'bravo-beach', 'charlie-peak', 'delta-park', 'echo-island'] as $i => $slug) {
            $this->destination($slug, 'Mid-range', 5 - $i * 0.1);
        }
        foreach (['one', 'two', 'three', 'four', 'five', 'six'] as $term) {
            $this->searched($term, 5);
        }

        $this->assertCount(4, $this->chips());
    }

    public function test_the_landing_page_still_works_when_nothing_has_been_searched(): void
    {
        $this->destination('alpha-falls');

        $this->assertSame(['Alpha Falls'], $this->chips());
        $this->assertSame('Popular:', $this->label());
    }
}
