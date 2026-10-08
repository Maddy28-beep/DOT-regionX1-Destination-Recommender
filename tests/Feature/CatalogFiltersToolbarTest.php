<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Support\ActiveFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The shared filter card and results toolbar on every listing page (Destinations has its own test for the
 * same pieces): which fields each page offers, the "Showing" chips, the count on the button, and that the
 * sort select belongs to the filter form.
 */
class CatalogFiltersToolbarTest extends TestCase
{
    use RefreshDatabase;

    /** page => [path, has budget tiles, category parameter, has the grid/map switch] */
    private function pages(): array
    {
        return [
            'accommodations' => ['/accommodations', true, 'type', true],
            'restaurants' => ['/restaurants', true, 'cuisine_type', true],
            'souvenir centers' => ['/souvenir-centers', false, null, true],
            'tour operators' => ['/tour-operators', true, 'specialization', true],
            'packages' => ['/packages', true, 'type', false],
        ];
    }

    public function test_every_page_has_the_new_filter_card_and_toolbar(): void
    {
        foreach ($this->pages() as $name => [$path, $tiers, $category, $toggle]) {
            $html = $this->get($path)->assertOk()->getContent();

            $this->assertStringContainsString('filter-panel--v2', $html, $name);
            $this->assertStringContainsString('Province or city', $html, $name);
            $this->assertStringContainsString('All of Davao Region', $html, $name);
            $this->assertStringContainsString('results-toolbar', $html, $name);
            $this->assertStringContainsString('form="catalogFilters"', $html, $name);
            $this->assertMatchesRegularExpression('/data-filter-submit>\s*Show results\s*<\/button>/', $html, $name);
            $this->assertStringNotContainsString('Apply Filters', $html, $name);
            $this->assertStringNotContainsString('Filter results', $html, $name);

            $this->assertSame($tiers ? 4 : 0, substr_count($html, 'name="price_tier"'), "$name budget tiles: Any budget + three tiers");
            if ($tiers) {
                foreach (['Any budget', 'Budget-Friendly', 'Mid-range', 'Premium'] as $label) {
                    $this->assertStringContainsString($label, $html, "$name tile $label");
                }
                $this->assertMatchesRegularExpression('/value="" checked/', $html, "$name starts on Any budget");
            }
            $this->assertStringNotContainsString('value="Free"', $html, "$name has no Free tile (that is a destinations thing)");
            if ($category) {
                $this->assertStringContainsString('name="'.$category.'"', $html, $name);
            }
        }
    }

    public function test_the_grid_and_map_switch_appears_only_where_there_is_a_map_and_something_to_show(): void
    {
        // Nothing listed yet, so there is nothing to switch between.
        $this->assertStringNotContainsString('view-toggle__btn', $this->get('/restaurants')->getContent());
    }

    public function test_active_filters_become_chips_and_a_count_on_the_button(): void
    {
        $region = Region::create(['name' => 'Davao City']);

        $html = $this->get('/restaurants?cuisine_type=Seafood&price_tier=Premium&region_id='.$region->id.'&q=tuna')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/Show results \(4 filters\)/', $html);
        foreach (['Search: tuna', 'Davao City', 'Premium ₱₱₱', 'Seafood'] as $label) {
            $this->assertStringContainsString('Remove filter: '.$label, $html);
        }
        $this->assertStringContainsString('Clear all', $html);
    }

    public function test_a_page_with_no_budget_filter_does_not_offer_or_count_one(): void
    {
        $html = $this->get('/souvenir-centers?q=shop')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/Show results \(1 filter\)/', $html);
        $this->assertStringNotContainsString('Budget', $html);
    }

    public function test_the_active_filters_helper_only_describes_what_is_in_the_url(): void
    {
        $regions = collect([Region::create(['name' => 'Davao City'])]);

        $none = ActiveFilters::from(Request::create('/x'), $regions, 'type');
        $this->assertTrue($none->isEmpty());

        $some = ActiveFilters::from(
            Request::create('/x', 'GET', ['q' => 'eden', 'price_tier' => 'Budget-Friendly', 'type' => 'Hotel', 'interest' => 'Beach', 'region_id' => 999]),
            $regions,
            'type'
        );

        $this->assertSame(['q', 'price_tier', 'type', 'interest'], $some->keys()->all(), 'An unknown region is dropped rather than shown blank.');
        $this->assertSame('Budget ₱', $some['price_tier']);
        $this->assertSame('Hotel', $some['type']);
    }
}
