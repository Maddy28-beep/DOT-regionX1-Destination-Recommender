<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Region;
use App\Models\TourOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Featured Tour Packages" on the landing page uses the same card as the packages page and every other grid.
 * It used to keep its own older card, which also ribboned one package "Most Popular" on a review-count proxy
 * even when no package had a single review.
 */
class LandingPackageCardsTest extends TestCase
{
    use RefreshDatabase;

    private function package(string $name, array $extra = []): Package
    {
        return Package::create($extra + [
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao del Sur',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_per_pax' => 1500, 'duration_label' => '1 Day',
            'price_tier' => 'Budget-Friendly', 'type' => 'Adventure',
        ]);
    }

    public function test_the_featured_packages_use_the_updated_listing_card(): void
    {
        $operator = TourOperator::create([
            'slug' => 'op', 'name' => 'Island Explorers', 'location' => 'Samal', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
        ]);
        $this->package('Samal Island Hopping', ['tour_operator_id' => $operator->id]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Featured Tour Packages', $html);
        $this->assertStringContainsString('class="dcard__name">Samal Island Hopping', $html);
        // No accreditation record is on file for this test place, so it is never read as official: 'Not Yet Verified'.
        $this->assertStringContainsString('Not Yet Verified', $html);
        $this->assertStringContainsString('by Island Explorers', $html);
        $this->assertStringContainsString('1,500', $html);
        $this->assertStringContainsString('1 Day', $html);
        $this->assertStringNotContainsString('pkg-card', $html, 'The older package card must not be used.');
    }

    public function test_no_package_is_called_the_most_popular_without_any_reviews_to_say_so(): void
    {
        $this->package('Samal Island Hopping');
        $this->package('Eden Nature Park Day');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('Most Popular', $html);
        $this->assertStringNotContainsString('dpost-ribbon--sm', $html);
    }

    public function test_the_landing_page_loads_the_packages_card_data_without_a_query_per_card(): void
    {
        foreach (['One', 'Two', 'Three'] as $name) {
            $this->package("Package $name");
        }

        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $this->get('/')->assertOk();
        $tourOperatorQueries = collect(\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "tour_operators"'))->count();

        $this->assertLessThanOrEqual(1, $tourOperatorQueries, 'Providers are loaded once for the strip, not once per card.');
    }
}
