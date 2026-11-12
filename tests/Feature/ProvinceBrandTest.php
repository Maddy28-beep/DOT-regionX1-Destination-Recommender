<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use App\Support\ProvinceBrand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The regional line and each province's DOT Region XI niche show on the listing pages and on a destination. */
class ProvinceBrandTest extends TestCase
{
    use RefreshDatabase;

    private function place(string $region): Destination
    {
        return Destination::create([
            'slug' => 'a-place', 'name' => 'A Place', 'location' => 'Somewhere',
            'region_id' => Region::firstOrCreate(['name' => $region])->id, 'type' => 'Nature & Leisure',
            'is_accredited' => true, 'rating' => 4.5, 'review_count' => 1, 'price_tier' => 'Mid-range',
        ]);
    }

    public function test_every_province_has_a_niche_and_an_unknown_name_has_none(): void
    {
        foreach (['Davao City', 'Davao Oriental', 'Davao de Oro', 'Davao del Norte', 'Davao del Sur', 'Davao Occidental'] as $name) {
            $this->assertNotEmpty(ProvinceBrand::for($name), $name);
        }
        $this->assertNull(ProvinceBrand::for('Island Garden City of Samal'));
        $this->assertNull(ProvinceBrand::for(null));
    }

    public function test_listing_pages_show_the_regional_line_and_the_niche_only_for_a_chosen_province(): void
    {
        $place = $this->place('Davao del Sur');

        foreach (['destinations.index', 'accommodations.index', 'restaurants.index', 'packages.index', 'souvenir-centers.index', 'tour-operators.index'] as $route) {
            $this->get(route($route))->assertOk()->assertSee(ProvinceBrand::REGION_LINE)->assertDontSee('province-niche', false);
        }

        $this->get(route('destinations.index', ['region_id' => $place->region_id]))
            ->assertOk()->assertSee('A food and coffee haven')->assertSee('Davao del Sur');
    }

    public function test_a_destination_shows_its_province_niche(): void
    {
        $this->get(route('destinations.show', $this->place('Davao Occidental')))->assertOk()->assertSee('Where nature meets culture');
    }
}
