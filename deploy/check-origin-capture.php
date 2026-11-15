<?php

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['features.trip_location_v2' => true]);
if (in_array('--postgres', $argv ?? [], true)) {
    config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'exploredvo_origin_test_20261115']);
} else {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
}
Illuminate\Support\Facades\DB::purge();
if (!in_array(Illuminate\Support\Facades\DB::connection()->getDatabaseName(), [':memory:', 'exploredvo_origin_test_20261115'], true)) {
    throw new RuntimeException('Tests require the isolated test database.');
}
Illuminate\Support\Facades\DB::beginTransaction();
(require database_path('migrations/2026_11_14_000100_create_visitor_origin_daily_table.php'))->up();
(require database_path('migrations/2026_11_15_120000_add_origin_confirmation_receipts.php'))->up();
$checks = 0;
$check = function (bool $ok, string $message) use (&$checks) {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$count = fn ($column = 'visitors') => (int) Illuminate\Support\Facades\DB::table('visitor_origin_daily')->sum($column);
$token = (string) Illuminate\Support\Str::uuid();
$request = function (array $data, ?string $browser = null) use ($app, $token) {
    $request = Illuminate\Http\Request::create('/plan/origin-confirmation', 'POST', $data, ['visitor_token' => $browser ?? $token]);
    $request->setLaravelSession(new Illuminate\Session\Store('test', new Illuminate\Session\ArraySessionHandler(120)));
    $app->instance('request', $request);
    return $request;
};
$controller = app(App\Http\Controllers\TripPlannerController::class);
$origin = ['visitor_origin_country' => 'JP', 'visitor_origin_city' => 'Tokyo'];
$response = $controller->confirmOrigin($request($origin));
$check($response->getStatusCode() === 200 && $count() === 1, 'Confirmation without a trip must count immediately');
$controller->confirmOrigin($request($origin));
$check($count() === 1, 'Fresh session with the same cookie must not duplicate');
$origin['visitor_origin_city'] = 'Osaka';
$controller->confirmOrigin($request($origin));
$check($count() === 1 && Illuminate\Support\Facades\DB::table('visitor_origin_daily')->where('city', 'Tokyo')->sum('visitors') === 0, 'Changed answers must move, not add');
foreach ([['visitor_origin_country' => 'ZZ'], ['visitor_origin_country' => 'PH']] as $bad) {
    try { $controller->confirmOrigin($request($bad)); throw new RuntimeException('Invalid origin accepted'); }
    catch (Illuminate\Validation\ValidationException $e) { $check($count() === 1, 'Invalid origins must not count'); }
}
$answer = ['answer' => 'chosen', 'country' => 'JP', 'province' => '', 'city' => 'Osaka'];
App\Services\Privacy\VisitorOriginCapture::record($request($origin), $answer, true);
App\Services\Privacy\VisitorOriginCapture::record($request($origin), $answer, true);
$check($count() === 1 && $count('generated_visitors') === 1, 'Generation must count once without adding another origin');
$origin['visitor_origin_city'] = 'Kyoto';
$controller->confirmOrigin($request($origin));
$check($count() === 1 && $count('generated_visitors') === 1 && Illuminate\Support\Facades\DB::table('visitor_origin_daily')->where('city', 'Kyoto')->sum('generated_visitors') === 1, 'Correction after generation must move both counters');
$controller->confirmOrigin($request($origin, (string) Illuminate\Support\Str::uuid()));
$check($count() === 2, 'A different browser must have its own response');
Illuminate\Support\Carbon::setTestNow(now('Asia/Manila')->addDay());
$controller->confirmOrigin($request($origin));
$check($count() === 3, 'A new Philippine day can count again');
$hashes = Illuminate\Support\Facades\DB::table('visitor_origin_receipts')->pluck('browser_hash');
$check(!$hashes->contains($token) && $hashes->every(fn ($hash) => strlen($hash) === 64), 'Receipts must not store raw browser tokens');
$check($count('generated_visitors') === 1, 'An origin-only response must not count as a generated itinerary');
foreach ([1, 2] as $extra) {
    $controller->confirmOrigin($request($origin, (string) Illuminate\Support\Str::uuid()));
}
$admin = app(App\Http\Controllers\Admin\AdminVisitorOriginController::class);
$tables = new ReflectionMethod($admin, 'tables');
$responses = $tables->invoke($admin, null, null, 'responses');
$generated = $tables->invoke($admin, null, null, 'generated');
$check($responses['By country']['total'] === 5 && $responses['By country']['enough'], 'Admin must show confirmed origins independently of trip generation');
$check($generated['By country']['total'] === 1 && !$generated['By country']['enough'], 'Generated metric must use separate counts and retain small-group suppression');
Illuminate\Support\Facades\Schema::create('itineraries', function ($table) { $table->uuid('id')->primary(); $table->unsignedBigInteger('preference_id'); });
$legacy = new App\Models\TouristPreference();
$legacy->forceFill(['id' => 123, 'created_at' => now(), 'visitor_origin_source' => 'chosen',
    'visitor_origin_country_code' => 'JP', 'visitor_origin_city' => 'Kyoto']);
Illuminate\Support\Facades\DB::table('itineraries')->insert(['id' => (string) Illuminate\Support\Str::uuid(), 'preference_id' => $legacy->id]);
$legacyAnswer = App\Services\Privacy\VisitorOriginStats::answerOf($legacy);
App\Services\Privacy\VisitorOriginStats::move(null, $legacyAnswer, now());
$legacyRequest = $request($origin, (string) Illuminate\Support\Str::uuid());
App\Services\Privacy\VisitorOriginCapture::record($legacyRequest, $legacyAnswer, false, $legacy);
$check($count() === 6, 'An already-counted legacy trip must not add another origin');
App\Services\Privacy\VisitorOriginCapture::record($legacyRequest, $legacyAnswer, true, $legacy);
$check($count() === 6 && $count('generated_visitors') === 2, 'A legacy browser completion must be marked once');
config(['features.trip_location_v2' => false]);
try { $controller->confirmOrigin($request($origin)); throw new RuntimeException('Feature-off endpoint was enabled'); }
catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { $check($e->getStatusCode() === 404 && $count() === 6, 'Feature-off must refuse capture'); }
Illuminate\Support\Carbon::setTestNow();
Illuminate\Support\Facades\DB::rollBack();
echo $checks.' origin-capture checks passed in the isolated database; live data untouched.'.PHP_EOL;
