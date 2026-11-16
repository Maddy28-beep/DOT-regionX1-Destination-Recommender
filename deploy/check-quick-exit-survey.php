<?php

// Run in the isolated live-code copy. All submitted test responses are rolled back.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('https://www.exploredavao.com/exit-survey'));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config(['session.driver' => 'array', 'cache.default' => 'array']);
$checks = 0;
$check = function (bool $ok, string $message) use (&$checks) {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
    echo "PASS: $message\n";
};
$request = Illuminate\Http\Request::create('https://www.exploredavao.com/exit-survey');
$app->instance('request', $request);
$response = $kernel->handle($request);
$check($response->getStatusCode() === 200, 'Public survey renders');
$dom = new DOMDocument();
@$dom->loadHTML($response->getContent());
$path = new DOMXPath($dom);
$check($path->query('//details[contains(@class,"survey-extra")]')->length === 2 && $path->query('//details[@open]')->length === 0, 'Two optional sections start collapsed');
$required = [];
foreach ($path->query('//div[contains(@class,"survey-quick")]//form//input[@required]') as $input) $required[] = $input->getAttribute('name');
$check(array_values(array_unique($required)) === ['overall_rating', 'would_recommend'], 'Only two questions required');
$check($path->query('//input[@name="estimated_total_spend"]')->length === 6 && $path->query('//details//input[@name="estimated_total_spend"]')->length === 0, 'Spending ranges visible outside optional disclosures');
$check($path->query('//button[@type="submit" and contains(@class,"survey-submit")]')->length === 1, 'Single submit action');

$session = $app['session']->driver();
$session->start();
$bind = function (array $data = [], string $method = 'POST') use ($app, $session) {
    $request = Illuminate\Http\Request::create('https://www.exploredavao.com/exit-survey', $method, $data);
    $request->setLaravelSession($session);
    $app->instance('request', $request);
    return $request;
};
$controller = $app->make(App\Http\Controllers\ExitSurveyController::class);
Illuminate\Support\Facades\DB::beginTransaction();
try {
    $before = App\Models\ExitSurvey::withoutGlobalScopes()->count();
    $controller->store($bind(['overall_rating' => 4, 'would_recommend' => 'Yes', 'test' => 1]));
    $survey = App\Models\ExitSurvey::withoutGlobalScopes()->findOrFail($session->get('last_exit_survey_id'));
    $check($survey->data_source === 'test' && $survey->estimated_total_spend === null && (int)$survey->overall_rating === 4, 'Two-answer submission succeeds with no spending value invented');

    $place = App\Models\Destination::publiclyVisible()->firstOrFail();
    $controller->store($bind([
        'overall_rating' => 5, 'would_recommend' => 'Yes', 'test' => 1,
        'estimated_total_spend' => '20000_50000', 'places_visited' => ['destination:'.$place->id],
        'activities' => ['Food Tourism'], 'actual_days_stayed' => 3,
        'origin' => 'Cebu City, Philippines', 'residency_type' => 'Domestic Tourist',
        'travel_purpose' => 'Leisure', 'comments' => 'Rollback-only QA',
        'destination_relevant' => 4, 'itinerary_useful' => 5,
    ]));
    $survey = App\Models\ExitSurvey::withoutGlobalScopes()->findOrFail($session->get('last_exit_survey_id'));
    $check($survey->estimated_total_spend === '20000_50000' && $survey->origin === 'Cebu City, Philippines' && (int)$survey->itinerary_useful === 5, 'Optional trip and feedback data retained');
    $check(App\Models\ExitSurveyVisit::withoutGlobalScopes()->where('exit_survey_id', $survey->id)->count() === 1 && App\Models\ExitSurveyActivity::withoutGlobalScopes()->where('exit_survey_id', $survey->id)->count() === 1, 'Visited places and activities still feed the existing pipeline');

    foreach ([['would_recommend' => 'Yes'], ['overall_rating' => 4], ['overall_rating' => 4, 'would_recommend' => 'Maybe'], ['overall_rating' => 4, 'would_recommend' => 'Yes', 'estimated_total_spend' => 'invalid']] as $invalid) {
        $rejected = false;
        try { $controller->store($bind($invalid + ['test' => 1])); }
        catch (Illuminate\Validation\ValidationException $e) { $rejected = true; }
        $check($rejected, 'Invalid or missing answer rejected: '.implode(',', array_keys($invalid)));
    }

    $session->put('_old_input', ['overall_rating' => 4, 'would_recommend' => 'Yes', 'estimated_total_spend' => '10000_20000', 'comments' => 'Kept comment', 'origin' => 'Cebu', 'places_visited' => ['destination:'.$place->id], 'test' => 1]);
    $bind(['test' => 1], 'GET');
    $html = $controller->create()->with('errors', new Illuminate\Support\ViewErrorBag())->render();
    @$dom->loadHTML($html);
    $path = new DOMXPath($dom);
    $check($path->query('//details[@open]')->length === 2 && str_contains($html, 'Kept comment'), 'Answered optional sections reopen with preserved input');
    $check($path->query('//input[@name="estimated_total_spend" and @value="10000_20000" and @checked]')->length === 1 && str_contains($html, 'Test mode.'), 'Spending selection and test mode preserved');
    $check(App\Models\ExitSurvey::withoutGlobalScopes()->count() === $before + 2, 'Invalid submissions create no records');

    $session->forget('_old_input');
    $reviewRequest = $bind([], 'GET');
    $token = (string) Illuminate\Support\Str::uuid();
    $reviewRequest->cookies->set('visitor_token', $token);
    $reviewData = ['listing' => $place, 'kind' => 'destination', 'type' => 'destinations', 'errors' => new Illuminate\Support\ViewErrorBag()];
    $html = view('partials.review-form', $reviewData)->render();
    $check(!str_contains($html, 'data-review-form') && str_contains($html, 'Scan the DOT QR code'), 'Establishment review still requires a QR check-in');
    App\Models\TouristVisit::create(['visitor_token' => $token, 'listing_kind' => 'destination', 'listing_id' => $place->id, 'visit_date' => now()->toDateString(), 'source' => 'qr_scan']);
    $html = view('partials.review-form', $reviewData)->render();
    @$dom->loadHTML($html);
    $path = new DOMXPath($dom);
    $check($path->query('//textarea[@name="comment" and not(ancestor::details)]')->length === 1 && $path->query('//fieldset[contains(@class,"review-form__rating--aspect") and not(ancestor::details)]')->length === 2, 'Comments and optional service/amenities ratings are directly visible');
    $session->put('_old_input', ['service_rating' => 4, 'comment' => 'Preserved review']);
    $html = view('partials.review-form', $reviewData)->render();
    @$dom->loadHTML($html);
    $path = new DOMXPath($dom);
    $check($path->query('//input[@name="service_rating" and @value="4" and @checked]')->length === 1 && str_contains($html, 'Preserved review'), 'Establishment review keeps previously entered ratings and comment');
} finally {
    Illuminate\Support\Facades\DB::rollBack();
}
echo "$checks checks passed; all test submissions rolled back.\n";
