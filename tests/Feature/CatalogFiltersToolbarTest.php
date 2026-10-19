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
        foreach (['Search: tuna', 'Davao City', 'Premium', 'Seafood'] as $label) {
            $this->assertStringContainsString('Remove filter: '.$label, $html);
        }
        $this->assertStringContainsString('Clear all', $html);
    }

    public function test_the_dining_filter_names_its_budget_in_words_with_no_peso_sign(): void
    {
        $html = $this->get('/restaurants?price_tier=Mid-range')->assertOk()->getContent();

        $panel = substr($html, (int) strpos($html, 'id="filterPanel"'), 6000);
        foreach (['Any budget', 'Budget-friendly', 'Mid-range', 'Premium'] as $name) {
            $this->assertStringContainsString('budget-tile__name">'.$name.'<', $panel, $name);
        }
        $this->assertStringNotContainsString('budget-tile__sub', $panel, 'No peso line under the tile names.');
        $this->assertStringNotContainsString('&#8369;', $panel);
        $this->assertStringNotContainsString('₱', $panel);
        $this->assertStringContainsString('Remove filter: Mid-range"', $html, 'The chip for the chosen band has no peso signs either.');
    }

    public function test_no_catalogue_shows_peso_signs_in_its_budget_filter(): void
    {
        foreach (['/destinations', '/accommodations', '/packages', '/tour-operators', '/restaurants'] as $url) {
            $html = $this->get($url.'?price_tier=Premium')->assertOk()->getContent();

            $panel = substr($html, (int) strpos($html, 'id="filterPanel"'), 6000);
            $this->assertStringContainsString('budget-tile__name', $panel, $url);
            $this->assertStringNotContainsString('₱', $panel, $url);
            $this->assertStringNotContainsString('budget-tile__sub', $panel, $url);
            $this->assertStringContainsString('Remove filter: Premium"', $html, $url.': the chip names the band in words.');
        }
    }

    public function test_the_active_filters_helper_names_the_budget_in_words(): void
    {
        foreach (['Free' => 'Free', 'Budget-Friendly' => 'Budget-friendly', 'Mid-range' => 'Mid-range', 'Premium' => 'Premium'] as $tier => $label) {
            $filters = ActiveFilters::from(Request::create('/x', 'GET', ['price_tier' => $tier]), collect());

            $this->assertSame($label, $filters->get('price_tier'));
            $this->assertStringNotContainsString('₱', $filters->get('price_tier'));
        }
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
        $this->assertSame('Budget-friendly', $some['price_tier']);
        $this->assertSame('Hotel', $some['type']);
    }
}
