<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dining listings use the same card as every other catalogue (destinations, stays, packages...), not the older
 * photo-with-the-name-on-it card they used to fall back to.
 */
class DiningCardTest extends TestCase
{
    use RefreshDatabase;

    private function restaurant(): Restaurant
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return Restaurant::create([
            'slug' => 'marina-tuna', 'name' => 'Marina Tuna Restaurant', 'location' => 'Davao City',
            'region_id' => $region->id, 'cuisine_type' => 'Filipino Seafood', 'is_accredited' => true,
            'price_tier' => 'Mid-range', 'rating' => 0, 'review_count' => 0,
        ]);
    }

    public function test_the_dining_grid_uses_the_shared_listing_card(): void
    {
        $this->restaurant();

        $html = $this->get('/restaurants')->assertOk()->getContent();

        $this->assertStringContainsString('class="dcard__name">Marina Tuna Restaurant<', $html, 'The name sits below the photo, as on the other cards.');
        $this->assertStringContainsString('DOT accredited', $html);
        $this->assertStringContainsString('Filipino Seafood', $html);
        $this->assertStringContainsString('dcard__new', $html, 'A listing with no reviews yet carries the New tag.');
        $this->assertStringNotContainsString('dpost-card__name', $html, 'No more name printed over the photo.');
    }

    public function test_a_dining_card_still_has_its_heart_and_links_to_the_place(): void
    {
        $this->restaurant();

        $html = $this->get('/restaurants')->getContent();

        $this->assertStringContainsString('saved/restaurants/', $html);
        $this->assertStringContainsString(route('restaurants.show', 'marina-tuna'), $html);
    }
}
