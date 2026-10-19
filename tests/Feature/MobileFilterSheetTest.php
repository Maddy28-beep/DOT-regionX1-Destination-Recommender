<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * On a phone the listing filters open as a bottom sheet (public/js/filter-sheet.js) instead of expanding in the page
 * and pushing the listing down. These pin the markup that makes that possible on every listing page.
 */
class MobileFilterSheetTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, array{0: string}> */
    public static function listingPages(): array
    {
        return [
            ['/destinations'], ['/accommodations'], ['/packages'],
            ['/restaurants'], ['/souvenir-centers'], ['/tour-operators'],
        ];
    }

    #[DataProvider('listingPages')]
    public function test_the_filters_button_sits_in_the_toolbar_and_opens_the_sheet(string $url): void
    {
        $html = $this->get($url)->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-filter-sheet-open'), 'One Filters button.');
        $this->assertStringNotContainsString("getElementById('filterPanel').classList.toggle", $html, 'No more inline expand-in-page toggle.');
        $this->assertMatchesRegularExpression('/results-toolbar__controls">\s*(?:\{\{--.*?--\}\}\s*)?<button type="button" class="filter-toggle" data-filter-sheet-open aria-controls="filterPanel" aria-expanded="false"/s', $html);
    }

    #[DataProvider('listingPages')]
    public function test_the_panel_has_what_a_sheet_needs(string $url): void
    {
        $html = $this->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('data-filter-sheet-overlay', $html, 'The dim layer behind the sheet.');
        $this->assertStringContainsString('data-filter-sheet-close', $html, 'A close button.');
        $this->assertStringContainsString('id="filterPanelTitle"', $html, 'A title to name the dialog.');
        $this->assertMatchesRegularExpression('#js/filter-sheet\.js\?v=\d+#', $html);
        $this->assertStringNotContainsString('role="dialog"', $this->panelMarkup($html), 'Only a dialog while it is open; the script sets that.');
    }

    public function test_the_button_counts_the_filters_that_are_on(): void
    {
        $none = $this->get('/destinations')->assertOk()->getContent();
        $this->assertStringNotContainsString('filter-toggle__count', $none);

        $one = $this->get('/destinations?price_tier=Free')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/filter-toggle__count" aria-label="1 on">1</', $one);
    }

    public function test_the_script_ships_and_guards_against_wide_screens(): void
    {
        $js = file_get_contents(public_path('js/filter-sheet.js'));

        $this->assertStringContainsString('(max-width: 900px)', $js, 'Same breakpoint as the stylesheet.');
        $this->assertStringContainsString("'Escape'", $js);
        $this->assertStringContainsString('aria-modal', $js);
    }

    private function panelMarkup(string $html): string
    {
        $start = strpos($html, '<aside class="filter-panel');
        $this->assertNotFalse($start, 'The filter panel is on the page.');

        return substr($html, $start, strpos($html, '</aside>', $start) - $start);
    }
}
