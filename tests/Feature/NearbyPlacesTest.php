<?php

namespace Tests\Feature;

use App\Models\{Accommodation, Destination, Package, Region, Restaurant, SouvenirCenter, TourOperator};
use App\Support\{ActiveFilters, NearbyPlaces};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class NearbyPlacesTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = [
        [Destination::class, '/destinations', 'destinations', ['type' => 'Nature & Adventure']],
        [Accommodation::class, '/accommodations', 'accommodations', ['type' => 'Hotel', 'price_per_night' => 1000]],
        [Restaurant::class, '/restaurants', 'restaurants', ['cuisine_type' => 'Local']],
        [SouvenirCenter::class, '/souvenir-centers', 'souvenirCenters', []],
        [Package::class, '/packages', 'packages', ['type' => 'Adventure', 'duration_label' => 'Day trip', 'duration_days' => 1, 'price_per_pax' => 1000, 'status' => Package::STATUS_LIVE]],
        [TourOperator::class, '/tour-operators', 'tourOperators', ['specialization' => 'Nature & Adventure']],
    ];

    private function listing(string $model, string $name, array $extra = [], array $attributes = [])
    {
        return $model::create($attributes + $extra + [
            'name' => $name, 'slug' => str($name)->slug()->toString(), 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'is_accredited' => true, 'price_tier' => 'Mid-range', 'rating' => 4.5, 'review_count' => 1,
        ]);
    }

    public function test_nearby_filters_all_six_catalogues_before_pagination_and_preserves_other_filters(): void
    {
        foreach (self::PAGES as [$model, $path, $key, $extra]) {
            $point = NearbyPlaces::POINTS['davao'];
            $near = $this->listing($model, 'Near place', $extra, ['latitude' => $point['lat'] + .006, 'longitude' => $point['lng']]);
            $this->listing($model, 'Middle place', $extra, ['latitude' => $point['lat'] + .06, 'longitude' => $point['lng'], 'rating' => 3]);
            $this->listing($model, 'Far place', $extra, ['latitude' => $point['lat'] + .3, 'longitude' => $point['lng']]);
            $this->listing($model, 'Unknown location', $extra);
            $response = $this->get($path.'?nearby_origin=davao&nearby_km=1')->assertOk();
            $this->assertSame(['Near place'], $response->viewData($key)->pluck('name')->all(), $path);
            $this->assertSame(1, $response->viewData($key)->total());
            if ($key === 'destinations') {
                $this->assertSame([$near->id], $response->viewData('mapDestinations')->pluck('id')->all());
            }
            $this->assertSame(2, $this->get($path.'?nearby_origin=davao&nearby_km=10')->viewData($key)->total());
            $this->assertSame(['Near place'], $this->get($path.'?nearby_origin=davao&nearby_km=10&rating_min=4')->viewData($key)->pluck('name')->all());
            $html = $response->getContent();
            $this->assertStringContainsString('Nearby places', $html);
            $this->assertStringContainsString('Within 1 km of Davao City centre', $html);
            $this->assertStringNotContainsString('With photos only', $html);
            $this->assertSame(4, $this->get($path)->viewData($key)->total(), 'Without nearby, unmapped listings remain included.');
        }
    }

    public function test_current_location_uses_actual_radial_distance_and_closed_toggle_still_works(): void
    {
        $p = NearbyPlaces::POINTS['davao'];
        $this->listing(Destination::class, 'Inside', ['type' => 'Nature & Adventure'], ['latitude' => $p['lat'], 'longitude' => $p['lng']]);
        $this->listing(Destination::class, 'Outside circle', ['type' => 'Nature & Adventure'], ['latitude' => $p['lat'] + .008, 'longitude' => $p['lng'] + .008]);
        $closed = $this->listing(Destination::class, 'Closed nearby', ['type' => 'Nature & Adventure'], ['latitude' => $p['lat'], 'longitude' => $p['lng']]);
        $closed->forceFill(['operating_status' => 'temporarily_closed'])->save();
        $params = ['nearby_origin' => 'current', 'nearby_lat' => $p['lat'], 'nearby_lng' => $p['lng'], 'nearby_km' => 1, 'open_now' => 1];
        $response = $this->get('/destinations?'.http_build_query($params))->assertOk();
        $this->assertSame(['Inside'], $response->viewData('destinations')->pluck('name')->all());
        $this->assertStringContainsString('Options', $response->getContent());
        $this->assertStringContainsString('Show results (2 filters)', $response->getContent());
    }

    public function test_invalid_or_incomplete_nearby_parameters_do_not_filter_or_break_catalogues(): void
    {
        $this->listing(Destination::class, 'Still visible', ['type' => 'Nature & Adventure']);
        foreach ([['nearby_km' => 1], ['nearby_origin' => 'missing'], ['nearby_origin' => 'davao', 'nearby_km' => 999],
            ['nearby_origin' => ['davao']], ['nearby_origin' => 'current'],
            ['nearby_origin' => 'current', 'nearby_lat' => 1000, 'nearby_lng' => 125],
            ['nearby_origin' => 'current', 'nearby_lat' => ['bad'], 'nearby_lng' => 125]] as $params) {
            $response = $this->get('/destinations?'.http_build_query($params))->assertOk();
            $this->assertSame(1, $response->viewData('destinations')->total());
        }
    }

    public function test_nearby_counts_as_one_removable_filter_and_radius_without_origin_is_inactive(): void
    {
        $regions = collect();
        $filters = ActiveFilters::from(Request::create('/', 'GET', ['nearby_origin' => 'airport', 'nearby_km' => 5]), $regions);
        $this->assertSame(['nearby_origin' => 'Within 5 km of Davao International Airport'], $filters->all());
        $this->assertEmpty(ActiveFilters::from(Request::create('/', 'GET', ['nearby_km' => 5]), $regions));
    }
}
