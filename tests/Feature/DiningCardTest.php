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
        // No accreditation record is on file for this test place, so it is never read as official: 'Not Yet Verified'.
        $this->assertStringContainsString('Not Yet Verified', $html);
        $this->assertStringContainsString('Filipino Seafood', $html);
        $this->assertStringContainsString('dcard__new', $html, 'A listing with no reviews yet carries the New tag.');
        $this->assertStringNotContainsString('dpost-card__name', $html, 'No more name printed over the photo.');
    }

    public function test_the_price_band_is_said_in_words_with_no_peso_sign(): void
    {
        $this->restaurant();

        $html = $this->get('/restaurants')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="dcard__tier">Mid-range</', $html);
        $this->assertStringNotContainsString('dcard__band', $html, 'No row of peso symbols on a dining card.');
        $this->assertStringNotContainsString('&#8369;', $this->card($html), 'No peso sign on the card.');
    }

    public function test_the_distance_from_the_city_centre_shows_when_there_is_a_map_location(): void
    {
        $restaurant = $this->restaurant();
        $restaurant->update(['latitude' => 7.1031, 'longitude' => 125.6128]);   // about 3.3 km north of the centre

        $html = $this->get('/restaurants')->getContent();

        $this->assertMatchesRegularExpression('/dcard__dist">.*?3\.3 km<span class="dcard__dist-more"> from city/s', $html);
        $this->assertEqualsWithDelta(3.3, $restaurant->fresh()->distanceFromCityCentreKm(), 0.1);
    }

    public function test_a_place_with_no_band_and_no_location_has_no_footer(): void
    {
        $restaurant = $this->restaurant();
        $restaurant->update(['price_tier' => null]);

        $html = $this->get('/restaurants')->getContent();

        $this->assertStringNotContainsString('dcard__foot', $html);
        $this->assertNull($restaurant->fresh()->distanceFromCityCentreKm());
    }

    /** The markup of the first listing card on the page. */
    private function card(string $html): string
    {
        $start = strpos($html, 'class="dpost-card-wrap"');
        $this->assertNotFalse($start);

        return substr($html, $start, (int) strpos($html, 'dcard__body', $start) + 4000 - $start);
    }

    public function test_a_dining_card_still_has_its_heart_and_links_to_the_place(): void
    {
        $this->restaurant();

        $html = $this->get('/restaurants')->getContent();

        $this->assertStringContainsString('saved/restaurants/', $html);
        $this->assertStringContainsString(route('restaurants.show', 'marina-tuna'), $html);
    }
}
