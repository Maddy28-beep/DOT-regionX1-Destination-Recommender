<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Most destinations have no distance typed in, so their card and detail page showed nothing where the places with one
 * show "32 km from city". The figure now falls back to the straight line from the place's coordinates (as dining cards
 * already do), and a recorded distance still wins.
 */
class DestinationCardDistanceTest extends TestCase
{
    use RefreshDatabase;

    private function destination(array $extra = []): Destination
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return Destination::create($extra + [
            'slug' => 'monfort-bat-cave', 'name' => 'Monfort Bat Cave', 'location' => 'Samal', 'region_id' => $region->id,
            'type' => 'Wildlife', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
        ]);
    }

    public function test_a_card_without_a_recorded_distance_shows_the_straight_line_from_its_coordinates(): void
    {
        $this->destination(['latitude' => 7.1031, 'longitude' => 125.6128]);   // about 3.3 km north of the centre

        $html = $this->get('/destinations')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/dcard__dist">.*?3\.3 km<span class="dcard__dist-more"> from city/s', $html);
    }

    public function test_a_recorded_distance_wins_over_the_measured_one(): void
    {
        $d = $this->destination(['latitude' => 7.1031, 'longitude' => 125.6128, 'distance_km' => 32]);

        $this->assertSame(32.0, $d->distanceFromCityCentreKm());
        $this->assertMatchesRegularExpression('/dcard__dist">.*?32 km<span class="dcard__dist-more"> from city/s', $this->get('/destinations')->getContent());
    }

    public function test_a_place_with_neither_shows_no_distance(): void
    {
        $d = $this->destination();

        $this->assertNull($d->distanceFromCityCentreKm());
        $this->assertStringNotContainsString('dcard__dist', $this->get('/destinations')->getContent());
    }
}
