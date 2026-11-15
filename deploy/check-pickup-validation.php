<?php

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$addresses = new class extends App\Services\Geocoding\AddressSuggestionService {
    public ?array $point = null;
    public function resolve(string $query): ?array { return $this->point; }
};
$app->instance(App\Services\Geocoding\AddressSuggestionService::class, $addresses);
$controller = app(App\Http\Controllers\TripPlannerController::class);
$method = new ReflectionMethod($controller, 'validateTripLocation');
$passed = 0;
foreach (['JP', 'PH'] as $country) {
    foreach ([[7.13, 125.645, true], [35.6762, 139.6503, false]] as [$lat, $lng, $accepted]) {
        try {
            $point = $method->invoke($controller, Illuminate\Http\Request::create('/plan', 'POST'), ['visitor_origin_country' => $country, 'origin_lat' => $lat, 'origin_lng' => $lng]);
            if (! $accepted || $point !== [$lat, $lng]) { throw new RuntimeException('Wrong coordinate validation result'); }
        } catch (Illuminate\Validation\ValidationException $e) {
            if ($accepted) { throw $e; }
        }
        $passed++;
    }
}
foreach ([[], ['origin_label' => 'Unrecognized place']] as $data) {
    try {
        $method->invoke($controller, Illuminate\Http\Request::create('/plan', 'POST'), $data);
        throw new RuntimeException('An unresolved pick-up was accepted');
    } catch (Illuminate\Validation\ValidationException $e) {
        $passed++;
    }
}
$addresses->point = ['lat' => 7.13, 'lng' => 125.645];
$point = $method->invoke($controller, Illuminate\Http\Request::create('/plan', 'POST'), ['origin_label' => 'Davao airport']);
if ($point !== [7.13, 125.645]) { throw new RuntimeException('Resolved point was not returned'); }
echo (++$passed).' backend checks passed; no database rows written.'.PHP_EOL;
