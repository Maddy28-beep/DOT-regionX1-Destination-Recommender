<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ContentBasedRecommendationService as Recommender;
use App\Services\Recommendation\TransportMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Close proximity is 5 km. Commutable is 15 to 50 km, depending on how the traveller gets about. Remote has no limit.
 * The form, the filter and the distance score all read the same numbers, so each is checked here.
 */
class CommutableRadiusTest extends TestCase
{
    use RefreshDatabase;

    private const CENTRE = [7.0731, 125.6128];

    public function test_the_radius_of_each_choice(): void
    {
        $this->assertSame(5.0, Recommender::radiusKm('near'));
        $this->assertSame(5.0, Recommender::radiusKm('near', TransportMode::JEEP), 'Close proximity does not depend on how you travel.');
        $this->assertNull(Recommender::radiusKm('far'));
        $this->assertNull(Recommender::radiusKm('far', TransportMode::JEEP));
    }

    public function test_the_commutable_radius_depends_on_the_way_of_travelling(): void
    {
        $this->assertSame(15.0, Recommender::radiusKm('moderate', TransportMode::JEEP));
        $this->assertSame(30.0, Recommender::radiusKm('moderate', TransportMode::TAXI));
        $this->assertSame(50.0, Recommender::radiusKm('moderate', TransportMode::TOUR_OPERATOR));
        $this->assertSame(50.0, Recommender::radiusKm('moderate', TransportMode::PERSONAL_CAR));
        $this->assertSame(50.0, Recommender::radiusKm('moderate', TransportMode::RENTAL_CAR));
        $this->assertSame(50.0, Recommender::radiusKm('moderate', null), 'No answer is the personal car, as everywhere else.');
        $this->assertSame(50.0, Recommender::radiusKm('moderate', 'hovercraft'));
    }

    public function test_the_wording_shown_on_the_form(): void
    {
        $this->assertSame('about 5 km', Recommender::rangeLabel('near'));
        $this->assertSame('15–50 km, by how you travel', Recommender::rangeLabel('moderate'));
        $this->assertSame('no limit', Recommender::rangeLabel('far'));
    }

    public function test_the_form_states_the_new_distances_and_not_the_old_ones(): void
    {
        $html = $this->get('/plan')->assertOk()->getContent();

        $this->assertStringContainsString('about 5 km', $html);
        $this->assertStringContainsString('15–50 km, by how you travel', $html);
        $this->assertStringNotContainsString('about 25 km', $html);
        $this->assertStringNotContainsString('about 75 km', $html);
    }

    /** @return array<string, array{string, list<string>}> */
    public static function modes(): array
    {
        return [
            'jeep reaches 15 km' => [TransportMode::JEEP, ['Place 3 km', 'Place 10 km']],
            'taxi reaches 30 km' => [TransportMode::TAXI, ['Place 3 km', 'Place 10 km', 'Place 20 km']],
            'a rental car reaches 50 km' => [TransportMode::RENTAL_CAR, ['Place 3 km', 'Place 10 km', 'Place 20 km', 'Place 40 km']],
            'a tour operator reaches 50 km' => [TransportMode::TOUR_OPERATOR, ['Place 3 km', 'Place 10 km', 'Place 20 km', 'Place 40 km']],
        ];
    }

    /** @param list<string> $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_commutable_trip_only_offers_places_the_way_of_travelling_reaches(string $mode, array $expected): void
    {
        $region = Region::create(['name' => 'Davao City']);
        foreach ([3, 10, 20, 40] as $km) {
            Destination::create([
                'slug' => "place-{$km}", 'name' => "Place {$km} km", 'location' => 'Davao City', 'region_id' => $region->id,
                'type' => 'Nature & Adventure', 'is_accredited' => true, 'rating' => 4, 'review_count' => 5, 'price_tier' => 'Mid-range',
                'latitude' => self::CENTRE[0] + $km / 111.2, 'longitude' => self::CENTRE[1],
            ]);
        }

        $preference = TouristPreference::create([
            'travel_days' => 1, 'travel_type' => 'Solo', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any',
            'distance_pref' => 'moderate', 'transport_pref' => $mode, 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'origin_lat' => self::CENTRE[0], 'origin_lng' => self::CENTRE[1],
        ])->load('activities', 'amenities');

        // One day needs two places, so the trip does not widen as long as two are in range.
        $service = app(Recommender::class);
        $names = $service->rank($preference)->pluck('destination.name')->sort()->values()->all();

        sort($expected);   // compared as sorted text on both sides
        $this->assertSame($expected, $names);
        $this->assertFalse($service->lastRangeWidened);
    }

    public function test_a_close_proximity_trip_keeps_to_five_kilometres_when_there_is_enough_there(): void
    {
        $region = Region::create(['name' => 'Davao City']);
        foreach ([1, 3, 4, 12, 30] as $km) {
            Destination::create([
                'slug' => "spot-{$km}", 'name' => "Spot {$km} km", 'location' => 'Davao City', 'region_id' => $region->id,
                'type' => 'Wildlife', 'is_accredited' => true, 'rating' => 4, 'review_count' => 5, 'price_tier' => 'Mid-range',
                'latitude' => self::CENTRE[0] + $km / 111.2, 'longitude' => self::CENTRE[1],
            ]);
        }

        $preference = TouristPreference::create([
            'travel_days' => 1, 'travel_type' => 'Solo', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any',
            'distance_pref' => 'near', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'origin_lat' => self::CENTRE[0], 'origin_lng' => self::CENTRE[1],
        ])->load('activities', 'amenities');

        $names = app(Recommender::class)->rank($preference)->pluck('destination.name')->sort()->values()->all();

        $this->assertSame(['Spot 1 km', 'Spot 3 km', 'Spot 4 km'], $names);
    }

    public function test_the_itinerary_names_the_distance_that_was_applied(): void
    {
        $region = Region::create(['name' => 'Davao City']);
        foreach ([2, 6, 9] as $km) {
            Destination::create([
                'slug' => "stop-{$km}", 'name' => "Stop {$km} km", 'location' => 'Davao City', 'region_id' => $region->id,
                'type' => 'Wildlife', 'is_accredited' => true, 'rating' => 4, 'review_count' => 5, 'price_tier' => 'Mid-range',
                'latitude' => self::CENTRE[0] + $km / 111.2, 'longitude' => self::CENTRE[1],
            ]);
        }

        $this->post('/plan', [
            'travel_days' => 1, 'travel_type' => 'Solo', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any',
            'distance_pref' => 'moderate', 'transport_pref' => TransportMode::JEEP, 'travel_purpose' => 'Leisure',
            'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Cebu City', 'activities' => ['Wildlife'], 'amenities' => ['Parking Area'],
        ])->assertRedirect(route('plan.itinerary'));

        $this->get('/plan/itinerary')->assertOk()->assertSee('within about 15 km', false);
    }
}
