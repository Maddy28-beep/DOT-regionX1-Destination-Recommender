<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Destinations page's filter panel (search, province or city, budget tiles, a button that counts the
 * filters that are on) and its results toolbar (count, sort, grid/map, and a "Showing" chip per filter).
 */
class DestinationFiltersToolbarTest extends TestCase
{
    use RefreshDatabase;

    private function place(string $name, string $tier, string $regionName = 'Davao City'): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Somewhere',
            'region_id' => Region::firstOrCreate(['name' => $regionName])->id, 'type' => 'Nature & Adventure',
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => $tier,
        ]);
    }

    public function test_the_panel_omits_budget_price_and_sort_controls_and_keeps_show_results(): void
    {
        $this->place('Eden Park', 'Mid-range');

        $html = $this->get('/destinations')->assertOk()->getContent();

        foreach (['name="price_tier"', 'name="price_min"', 'name="price_max"', 'name="sort"'] as $control) {
            $this->assertStringNotContainsString($control, $html);
        }
        $panel = substr($html, (int) strpos($html, 'id="filterPanel"'), 6000);
        $this->assertStringNotContainsString('₱', $panel, 'The budget tiles are words, with no peso signs.');
        $this->assertStringContainsString('All of Davao Region', $html);
        $this->assertStringContainsString('Province or city', $html);
        $this->assertMatchesRegularExpression('/data-filter-submit>\s*Show results\s*<\/button>/', $html);
        $this->assertStringContainsString('Clear all', $html);
        $this->assertStringNotContainsString('Showing:', $html);
    }

    public function test_a_filter_shows_in_the_chips_the_button_count_and_a_clear_all_link(): void
    {
        $this->place('Cheap Beach', 'Budget-Friendly');
        $this->place('Fancy Resort', 'Premium');

        $html = $this->get('/destinations?price_tier=Budget-Friendly')->assertOk()->getContent();

        $this->assertStringContainsString('1 destination found', $html);
        $this->assertStringContainsString('Showing:', $html);
        $this->assertStringContainsString('Budget-friendly', $html);
        $this->assertMatchesRegularExpression('/Show results \(1 filter\)/', $html);
        $this->assertStringContainsString('Clear all', $html);
        $this->assertStringContainsString('Cheap Beach', $html);
        $this->assertStringNotContainsString('Fancy Resort', $html);
        $this->assertStringContainsString('type="hidden" name="price_tier" value="Budget-Friendly"', $html);
    }

    public function test_several_filters_are_counted_and_each_chip_removes_only_its_own_filter(): void
    {
        $this->place('Eden Park', 'Mid-range');

        $html = $this->get('/destinations?q=eden&price_tier=Mid-range')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/Show results \(2 filters\)/', $html);
        $this->assertStringContainsString('Search: eden', $html);
        $this->assertStringContainsString('Remove filter: Mid-range', $html);

        // Removing the budget chip keeps the search and drops the budget.
        preg_match_all('/<a href="([^"]+)" class="filter-chip" aria-label="Remove filter: ([^"]+)"/', $html, $m, PREG_SET_ORDER);
        $this->assertCount(2, $m);
        $budget = collect($m)->first(fn ($c) => str_contains($c[2], 'Mid-range'));
        $this->assertStringContainsString('q=eden', html_entity_decode($budget[1]));
        $this->assertStringNotContainsString('price_tier', html_entity_decode($budget[1]));
    }

    public function test_sorting_is_removed_and_the_existing_map_controls_remain(): void
    {
        $this->place('Eden Park', 'Mid-range');

        $html = $this->get('/destinations')->assertOk()->getContent();

        $this->assertStringContainsString('id="destinationFilters"', $html);
        $this->assertStringNotContainsString('Sort by', $html);
        $this->assertStringNotContainsString('name="sort"', $html);
        $this->assertStringContainsString('data-destination-view="map"', $html);
    }

    public function test_the_filtering_itself_is_unchanged(): void
    {
        $this->place('Free Park', 'Free');
        $this->place('Mid Park', 'Mid-range');

        $this->get('/destinations?price_tier=Free')->assertOk()->assertSee('Free Park')->assertDontSee('Mid Park');
        $this->get('/destinations?q=mid')->assertOk()->assertSee('Mid Park')->assertDontSee('Free Park');
    }
}
