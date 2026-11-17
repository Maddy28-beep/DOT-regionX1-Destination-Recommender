<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\TouristPreference;
use App\Services\Geocoding\AddressSuggestionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PickupOriginRegressionTest extends TestCase
{
    private function validatePickup(array $data, ?array $resolved = null): array
    {
        $this->mock(AddressSuggestionService::class, function ($mock) use ($resolved) {
            $mock->shouldReceive('resolve')->andReturn($resolved);
        });
        $controller = app(TripPlannerController::class);
        return (new \ReflectionMethod($controller, 'validateTripLocation'))->invoke($controller, Request::create('/plan', 'POST'), $data);
    }

    public function test_pickup_accepts_davao_coordinates_regardless_of_home_country(): void
    {
        foreach (['JP', 'PH'] as $country) {
            $this->assertSame([7.13, 125.645], $this->validatePickup([
                'visitor_origin_country' => $country, 'origin_lat' => 7.13, 'origin_lng' => 125.645,
            ]));
        }
    }

    public function test_pickup_refuses_tokyo_coordinates_even_for_a_davao_resident(): void
    {
        $this->expectException(ValidationException::class);
        $this->validatePickup(['visitor_origin_country' => 'PH', 'origin_lat' => 35.6762, 'origin_lng' => 139.6503]);
    }

    public function test_pickup_refuses_an_unresolved_typed_place(): void
    {
        $this->expectException(ValidationException::class);
        $this->validatePickup(['origin_label' => 'unrecognized hotel']);
    }

    public function test_pickup_refuses_an_empty_start(): void
    {
        $this->expectException(ValidationException::class);
        $this->validatePickup([]);
    }

    public function test_pickup_returns_the_resolved_point_for_reuse_without_another_lookup(): void
    {
        $this->assertSame([7.13, 125.645], $this->validatePickup(['origin_label' => 'Davao Airport'], ['lat' => 7.13, 'lng' => 125.645]));
    }
}
