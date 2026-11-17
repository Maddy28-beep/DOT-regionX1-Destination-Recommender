<?php

// Live verification uses an outer transaction so QA origin responses are never committed.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$token = (string) Illuminate\Support\Str::uuid();
$day = now('Asia/Manila')->toDateString();
$hash = hash_hmac('sha256', 'origin-confirmation|'.$day.'|'.$token, (string) config('app.key'));
$request = Illuminate\Http\Request::create('/plan/origin-confirmation', 'POST', [
    'visitor_origin_country' => 'JP', 'visitor_origin_city' => 'Origin Capture Qa',
], ['visitor_token' => $token]);
$request->setLaravelSession(new Illuminate\Session\Store('qa', new Illuminate\Session\ArraySessionHandler(120)));
$app->instance('request', $request);
$checks = [];
Illuminate\Support\Facades\DB::beginTransaction();
try {
    $controller = app(App\Http\Controllers\TripPlannerController::class);
    $response = $controller->confirmOrigin($request);
    $row = Illuminate\Support\Facades\DB::table('visitor_origin_receipts')->where(['day' => $day, 'browser_hash' => $hash])->first();
    $checks['origin_without_trip_recorded'] = $response->getStatusCode() === 200 && $row !== null && !$row->generated;
    $answer = json_decode($row->answer, true, 512, JSON_THROW_ON_ERROR);
    $before = (int) Illuminate\Support\Facades\DB::table('visitor_origin_daily')->where(['day' => $day, 'country_code' => 'JP', 'city' => 'Origin Capture Qa'])->sum('visitors');
    $controller->confirmOrigin($request);
    $after = (int) Illuminate\Support\Facades\DB::table('visitor_origin_daily')->where(['day' => $day, 'country_code' => 'JP', 'city' => 'Origin Capture Qa'])->sum('visitors');
    $checks['duplicate_not_added'] = $before === $after;
    App\Services\Privacy\VisitorOriginCapture::record($request, $answer, true);
    $checks['completion_marked_separately'] = (bool) Illuminate\Support\Facades\DB::table('visitor_origin_receipts')->where(['day' => $day, 'browser_hash' => $hash])->value('generated');
} finally {
    Illuminate\Support\Facades\DB::rollBack();
}
$checks['qa_receipt_not_saved'] = !Illuminate\Support\Facades\DB::table('visitor_origin_receipts')->where(['day' => $day, 'browser_hash' => $hash])->exists();
$html = Illuminate\Support\Facades\Http::get('https://www.exploredavao.com/plan')->body();
$checks['confirmation_button_served'] = str_contains($html, 'Confirm origin and continue');
$checks['privacy_notice_served'] = str_contains($html, 'even if you do not finish planning');
$checks['capture_script_served'] = Illuminate\Support\Facades\Http::get('https://www.exploredavao.com/js/plan-origin-capture.js')->successful();
echo json_encode($checks, JSON_PRETTY_PRINT).PHP_EOL;
if (in_array(false, $checks, true)) exit(1);
