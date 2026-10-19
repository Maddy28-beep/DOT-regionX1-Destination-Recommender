<?php

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * The listing pages' page links show a short window of numbers, not one box per page.
 */
class PaginationPartialTest extends TestCase
{
    private function render(int $page, int $pages, ?string $panel = null): string
    {
        $paginator = new LengthAwarePaginator(array_fill(0, 12, 1), $pages * 12, 12, $page, ['path' => '/destinations']);

        return view('partials.pagination', ['paginator' => $paginator] + ($panel ? ['panel' => $panel] : []))->render();
    }

    /** @return list<string> the page numbers shown as links or as the current page */
    private function numbers(string $html): array
    {
        preg_match_all('/class="pagination__page(?: is-current)?"[^>]*>(?:<span class="sr-only">Page <\/span>)?(\d+)</', $html, $m);

        return $m[1];
    }

    public function test_a_long_catalogue_collapses_to_a_window(): void
    {
        $html = $this->render(13, 26);

        $this->assertSame(['1', '12', '13', '14', '26'], $this->numbers($html));
        $this->assertSame(2, substr_count($html, 'pagination__gap'), 'An ellipsis on each side of the window.');
    }

    public function test_a_gap_of_one_page_is_filled_not_hidden(): void
    {
        $this->assertSame(['1', '2', '3', '4', '5', '26'], $this->numbers($this->render(4, 26)));
    }

    public function test_a_short_catalogue_shows_every_page(): void
    {
        $html = $this->render(2, 3);

        $this->assertSame(['1', '2', '3'], $this->numbers($html));
        $this->assertStringNotContainsString('pagination__gap', $html);
    }

    public function test_the_current_page_and_the_steps_are_marked(): void
    {
        $html = $this->render(1, 5);

        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertMatchesRegularExpression('/pagination__step is-disabled/', $html, 'No previous page from page 1.');
        $this->assertStringContainsString('rel="next"', $html);
        $this->assertStringContainsString('Page <strong>1</strong> of 5', $html, 'The phone shows "Page 1 of 5".');
    }

    public function test_one_page_renders_nothing_and_a_panel_is_passed_through(): void
    {
        $this->assertSame('', trim($this->render(1, 1)));
        $this->assertStringContainsString('data-view-panel="grid"', $this->render(1, 3, 'grid'));
    }
}
