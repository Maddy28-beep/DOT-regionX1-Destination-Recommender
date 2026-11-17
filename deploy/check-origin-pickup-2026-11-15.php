<?php

// Diagnostic only: generated trip rows are always rolled back.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$controller = app(App\Http\Controllers\TripPlannerController::class);
$origin = new ReflectionMethod($controller, 'applyVisitorOrigin');
$validate = new ReflectionMethod($controller, 'validateTripLocation');
$cases = [];
foreach (['JP' => 'Tokyo', 'PH' => 'Davao City'] as $country => $city) {
    foreach (['Davao airport' => [7.13, 125.645], 'Tokyo' => [35.6762, 139.6503]] as $pickup => [$lat, $lng]) {
        $preference = new App\Models\TouristPreference();
        $origin->invoke($controller, $preference, ['visitor_origin_country' => $country, 'visitor_origin_province' => 'Davao del Sur', 'visitor_origin_city' => $city]);
        try {
            $validate->invoke($controller, Illuminate\Http\Request::create('/plan', 'POST'), ['origin_lat' => $lat, 'origin_lng' => $lng, 'origin_label' => $pickup]);
            $status = 'accepted';
        } catch (Illuminate\Validation\ValidationException $e) {
            $status = 'rejected';
        }
        $cases[] = ['visitor_origin' => $preference->place_of_origin, 'pickup' => $pickup, 'pickup_validation' => $status];
    }
}

Illuminate\Support\Facades\DB::beginTransaction();
try {
    $preference = App\Models\TouristPreference::create([
        'travel_days' => 2, 'travel_type' => 'Couple', 'travel_purpose' => 'Leisure',
        'visitor_type' => 'First-time Visitor', 'budget' => 'Mid-range', 'accommodation_pref' => 'Hotel',
        'distance_pref' => 'moderate', 'transport_pref' => 'personal_car',
        'start_date' => now('Asia/Manila')->addDays(2)->toDateString(), 'arrival_time' => '09:00',
        'origin_lat' => 7.13, 'origin_lng' => 125.645, 'origin_label' => 'Davao International Airport',
    ]);
    $origin->invoke($controller, $preference, ['visitor_origin_country' => 'JP', 'visitor_origin_city' => 'Tokyo']);
    $preference->save();
    foreach (['Nature & Adventure', 'Cultural Heritage'] as $activity) {
        $preference->activities()->create(['activity' => $activity]);
    }
    foreach (['Parking Area', 'Restaurant'] as $amenity) {
        $preference->amenities()->create(['amenity' => $amenity]);
    }
    $preference->load('activities', 'amenities');
    $itinerary = app(App\Services\Recommendation\ItineraryGenerationService::class)->generate($preference);
    $checks = app(App\Services\Recommendation\ItineraryAudit::class)->audit($itinerary, $preference);
    $trip = [
        'visitor_origin' => $preference->place_of_origin,
        'start' => $preference->origin_label,
        'items' => $itinerary->items()->orderBy('day_number')->orderBy('sort_order')->get(['day_number', 'kind', 'title', 'starts_at', 'ends_at'])->toArray(),
        'failed_checks' => array_values(array_filter($checks, fn ($check) => $check['status'] === 'fail')),
        'unknown_checks' => array_values(array_filter($checks, fn ($check) => $check['status'] === 'unknown')),
    ];
} finally {
    Illuminate\Support\Facades\DB::rollBack();
}
echo json_encode(['validation_cases' => $cases, 'generated_trip' => $trip, 'saved' => false], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
