<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Restaurant;
use App\Models\Region;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use App\Support\MapLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Objective 1.3.2.3: navigate from the user's location to a destination. The "Directions" buttons now open Google Maps
 * directions TO the place with no starting point given, so Maps starts from where the visitor is. They used to open a
 * place search, which showed a pin and left the visitor to find the Directions button.
 */
class DirectionsLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_link_is_directions_to_the_place_with_no_origin(): void
    {
        $url = MapLinks::directionsTo('7.0710000', '125.6110000', 'Eden Nature Park', 'Davao City');

        $this->assertStringStartsWith('https://www.google.com/maps/dir/?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(['api' => '1', 'destination' => '7.0710000,125.6110000', 'travelmode' => 'driving'], $query);
        $this->assertArrayNotHasKey('origin', $query, 'no origin: Google Maps starts from the visitor\'s own location');
    }

    public function test_a_place_without_a_position_falls_back_to_its_name_and_address(): void
    {
        $url = MapLinks::directionsTo(null, null, 'Unlocated Tours', 'Davao City');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('Unlocated Tours Davao City', $query['destination']);
    }

    private function region(): int
    {
        return Region::firstOrCreate(['name' => 'Davao City'])->id;
    }

    /** @return array<string, array{0: string, 1: callable}> */
    public static function pages(): array
    {
        return [
            'destination' => ['destinations.show', fn (array $b) => Destination::create($b + ['type' => 'Nature & Leisure'])],
            'accommodation' => ['accommodations.show', fn (array $b) => Accommodation::create($b + ['type' => 'Hotel'])],
            'restaurant' => ['restaurants.show', fn (array $b) => Restaurant::create($b + ['cuisine_type' => 'Cafe'])],
            'souvenir center' => ['souvenir-centers.show', fn (array $b) => SouvenirCenter::create($b)],
            'tour operator' => ['tour-operators.show', fn (array $b) => TourOperator::create($b)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_every_directions_button_opens_directions_from_the_visitors_location(string $route, callable $make): void
    {
        $place = $make([
            'slug' => 'the-place', 'name' => 'The Place', 'location' => 'Davao City', 'region_id' => $this->region(),
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'latitude' => 7.071, 'longitude' => 125.611,
        ]);

        $html = $this->get(route($route, $place))->assertOk()->getContent();

        $this->assertStringContainsString('https://www.google.com/maps/dir/?api=1&amp;destination=', $html);
        $this->assertStringContainsString('travelmode=driving', $html);
        $this->assertStringNotContainsString('google.com/maps/search', $html, 'no place-search link where directions are meant');
        $this->assertStringNotContainsString('origin=', $html);
    }
}
