<?php

namespace Tests\Unit;

use App\Services\Recommendation\TravelTimeEstimator;
use PHPUnit\Framework\TestCase;

class CheckedRoadDistancesTest extends TestCase
{
    public function test_the_checked_city_road_detour_is_not_scheduled_as_a_fifteen_minute_leg(): void
    {
        $estimator = new TravelTimeEstimator();
        $rogen = ['lat' => 7.0731829, 'lng' => 125.6044565];
        $park = ['lat' => 7.0972103, 'lng' => 125.598976];
        $fallback = $estimator->estimate(2.8);
        $checked = $estimator->estimate(2.8, 'Davao City', 'Davao City', 'personal_car', $rogen, $park);

        $this->assertSame(6.6, $checked['distance_km']);
        $this->assertSame(30, $checked['max_minutes']);
        $this->assertGreaterThan($fallback['max_minutes'], $checked['max_minutes']);
        $this->assertSame($fallback, $estimator->estimate(2.8, null, null, null, ['lat' => 7.08, 'lng' => 125.61], $park), 'An unchecked endpoint keeps the explicit fallback.');
    }

    public function test_the_checked_hill_route_and_transport_pace_are_used_together(): void
    {
        $estimator = new TravelTimeEstimator();
        $eden = ['lat' => 7.0304604, 'lng' => 125.3979848];
        $malagos = ['lat' => 7.1910411, 'lng' => 125.4276581];
        $car = $estimator->estimate(18.15, 'Davao City', 'Davao City', 'personal_car', $eden, $malagos);
        $jeep = $estimator->estimate(18.15, 'Davao City', 'Davao City', 'jeep', $eden, $malagos);

        $this->assertSame(37.5, $car['distance_km']);
        $this->assertSame($car['distance_km'], $jeep['distance_km']);
        $this->assertGreaterThanOrEqual(90, $car['max_minutes']);
        $this->assertGreaterThan($car['max_minutes'], $jeep['max_minutes']);
    }
}
