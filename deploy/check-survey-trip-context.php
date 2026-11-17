<?php

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('https://www.exploredavao.com/exit-survey'));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config(['session.driver' => 'array', 'cache.default' => 'array']);
$checks = 0;
$check = function ($ok, $label) use (&$checks) {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
    $checks++;
};
$pref = new App\Models\TouristPreference();
$pref->forceFill(['visitor_origin_source' => 'chosen', 'visitor_origin_country_code' => 'JP', 'visitor_origin_city' => 'Tokyo', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'origin_label' => 'Davao Airport', 'travel_days' => 8]);
$context = App\Services\ExitSurveyTripContext::from($pref);
$check($context['origin'] === 'Tokyo, Japan' && $context['residency_type'] === 'Foreign Tourist', 'Origin uses home country, not pick-up or citizenship');
$check($context['travel_purpose'] === 'Leisure' && $context['visitor_type'] === 'First-time Visitor', 'Known purpose and visitor type reused');
$check(!array_key_exists('actual_days_stayed', $context), 'Planned duration is never presented as actual days');
$pref->forceFill(['visitor_origin_country_code' => 'PH', 'visitor_origin_province' => 'Cebu', 'visitor_origin_city' => 'Cebu City']);
$check(App\Services\ExitSurveyTripContext::from($pref)['residency_type'] === 'Domestic Tourist', 'Philippine residence outside Region XI classified domestic');
$pref->visitor_origin_province = 'Davao del Sur';
$check(App\Services\ExitSurveyTripContext::from($pref)['residency_type'] === 'Local Resident', 'Region XI residence classified local');
$pref->visitor_origin_province = null;
$check(!isset(App\Services\ExitSurveyTripContext::from($pref)['residency_type']), 'Missing province does not invent local/domestic classification');
$pref->forceFill(['visitor_origin_source' => 'unspecified', 'place_of_origin' => 'Old origin']);
$check(!isset(App\Services\ExitSurveyTripContext::from($pref)['origin']), 'Explicit unspecified origin does not revive stale text');
$pref->visitor_origin_source = null;
$check(App\Services\ExitSurveyTripContext::from($pref)['origin'] === 'Old origin', 'Legacy origin label remains usable without geographic guessing');
$check(App\Services\ExitSurveyTripContext::from(null) === [], 'No plan creates no invented profile');

$session = $app['session']->driver(); $session->start();
$bind = function ($data = [], $method = 'GET') use ($app, $session) {
    $request = Illuminate\Http\Request::create('https://www.exploredavao.com/exit-survey', $method, $data);
    $request->setLaravelSession($session); $app->instance('request', $request); return $request;
};
Illuminate\Support\Facades\DB::beginTransaction();
try {
    $originBefore = Illuminate\Support\Facades\DB::table('visitor_origin_daily')->sum('visitors');
    $controller = $app->make(App\Http\Controllers\ExitSurveyController::class);
    $bind();
    $html = $controller->create()->with('errors', new Illuminate\Support\ViewErrorBag())->render();
    $dom = new DOMDocument(); @$dom->loadHTML($html); $path = new DOMXPath($dom);
    $check(!str_contains($html, '<summary>About your trip') && $path->query('//input[@name="origin" or @name="actual_days_stayed"] | //select[@name="residency_type" or @name="travel_purpose"]')->length === 0, 'All four repeated trip questions removed');
    $check(str_contains($html, 'Where did you stay?') && str_contains($html, 'About how much did you spend?'), 'Actual stays and spending questions retained');
    $submit = function ($extra = []) use ($controller, $bind, $session) {
        $controller->store($bind($extra + ['overall_rating' => 4, 'would_recommend' => 'Yes', 'test' => 1], 'POST'));
        return App\Models\ExitSurvey::withoutGlobalScopes()->findOrFail($session->get('last_exit_survey_id'));
    };
    $session->forget(App\Http\Controllers\TripPlannerController::PREFERENCE_KEY);
    $survey = $submit();
    $check($survey->preference_id === null && $survey->origin === null && $survey->travel_purpose === null, 'Direct survey without a plan still submits');
    $plan = App\Models\TouristPreference::create([
        'travel_days' => 8, 'travel_type' => 'Solo', 'budget' => 'Moderate', 'accommodation_pref' => 'Hotel', 'distance_pref' => 'moderate',
        'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
        'visitor_origin_source' => 'chosen', 'visitor_origin_country_code' => 'JP', 'visitor_origin_city' => 'Tokyo',
        'place_of_origin' => 'Tokyo, Japan', 'origin_label' => 'Davao Airport',
    ]);
    $session->put(App\Http\Controllers\TripPlannerController::PREFERENCE_KEY, $plan->id);
    $survey = $submit();
    $check((int)$survey->preference_id === $plan->id && $survey->origin === 'Tokyo, Japan' && $survey->travel_purpose === 'Leisure', 'Submission automatically snapshots profile from its session-linked plan');
    $check($survey->visitor_type === 'First-time Visitor' && $survey->residency_type === 'Foreign Tourist', 'Admin profile/filter columns receive the reused details');
    $check($survey->actual_days_stayed === null && $survey->data_source === 'test', 'Actual days remain unanswered and test source stays separate');
    $survey = $submit(['origin' => 'Explicit old-form answer', 'travel_purpose' => 'Business', 'actual_days_stayed' => 2]);
    $check($survey->origin === 'Explicit old-form answer' && $survey->travel_purpose === 'Business' && (int)$survey->actual_days_stayed === 2, 'Answers from a previously opened form are preserved');
    $session->put(App\Http\Controllers\TripPlannerController::PREFERENCE_KEY, 999999999);
    $survey = $submit(['preference_id' => $plan->id]);
    $check($survey->preference_id === null && $survey->origin === null, 'Deleted session plan is safe; posted plan IDs cannot attach another plan');
    $check(Illuminate\Support\Facades\DB::table('visitor_origin_daily')->sum('visitors') === $originBefore, 'Survey does not count visitor origin again');
    $request = Illuminate\Http\Request::create('https://www.exploredavao.com/portal/admin/exit-surveys');
    $app->instance('request', $request); auth('admin')->setUser(App\Models\AdminUser::firstOrFail());
    $response = $kernel->handle($request);
    $check($response->getStatusCode() === 200 && str_contains($response->getContent(), 'Profile details can be reused'), 'Admin insights renders with data-source explanation');
} finally {
    Illuminate\Support\Facades\DB::rollBack();
}
echo "$checks checks passed; all QA records rolled back.\n";
