<?php

// Run from an isolated current-code app; every QA record is rolled back.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('https://www.exploredavao.com/portal/admin/exit-surveys'));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config(['session.driver' => 'array', 'cache.default' => 'array']);
auth('admin')->setUser(App\Models\AdminUser::firstOrFail());
$written = fn ($query, $field) => $query->whereNotNull($field)->whereRaw("TRIM($field) <> ''");
$inventory = [
    'real_exit_survey_comments' => $written(App\Models\ExitSurvey::query()->where('data_source', 'real'), 'comments')->count(),
    'test_exit_survey_comments' => $written(App\Models\ExitSurvey::withoutGlobalScopes()->where('data_source', 'test'), 'comments')->count(),
    'establishment_written_reviews' => $written(App\Models\Review::query(), 'comment')->count(),
];
echo json_encode($inventory, JSON_PRETTY_PRINT).PHP_EOL;
if (in_array('--inventory', $argv, true)) exit;
$checks = 0;
$check = function ($ok, $message) use (&$checks) {
    if (!$ok) throw new RuntimeException($message);
    $checks++; echo "PASS: $message\n";
};
$session = $app['session']->driver(); $session->start();
$bind = function ($path, $data = [], $method = 'GET', $cookies = []) use ($app, $session) {
    $request = Illuminate\Http\Request::create('https://www.exploredavao.com'.$path, $method, $data, $cookies);
    $request->setLaravelSession($session); $app->instance('request', $request); return $request;
};
Illuminate\Support\Facades\DB::beginTransaction();
try {
    $tag = 'QA-written-'.bin2hex(random_bytes(5));
    $text = "$tag: Please add clearer signs.\n<script>qaComment()</script>";
    $request = $bind('/exit-survey', ['overall_rating' => 4, 'would_recommend' => 'Yes', 'comments' => $text, 'travel_purpose' => 'Leisure', 'test' => 1], 'POST');
    app(App\Http\Controllers\ExitSurveyController::class)->store($request);
    $survey = App\Models\ExitSurvey::withoutGlobalScopes()->findOrFail($session->get('last_exit_survey_id'));
    $check($survey->comments === $text, 'Exit survey stores the submitted comment including line breaks');
    $survey->forceFill(['data_source' => 'real', 'submitted_at' => now()->addSecond()])->save();
    $dashboard = app(App\Http\Controllers\Admin\AdminDashboardController::class);
    $view = $dashboard->exitSurveys($bind('/portal/admin/exit-surveys'));
    $check($view->getData()['surveyComments']->contains('id', $survey->id), 'Real written response is in the admin comment collection');
    $html = $view->render();
    $check(str_contains($html, $tag) && str_contains($html, 'Comments &amp; suggestions'), 'DOT Exit Surveys renders the saved suggestion');
    $check(str_contains($html, '&lt;script&gt;qaComment()&lt;/script&gt;') && !str_contains($html, '<script>qaComment()</script>'), 'Visitor markup is escaped rather than executed');
    $check(str_contains($html, 'clearer signs.'), 'Full suggestion text is visible');
    App\Models\ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes', 'data_source' => 'demo', 'comments' => "$tag-demo", 'submitted_at' => now()->addSeconds(2)]);
    App\Models\ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes', 'data_source' => 'test', 'comments' => "$tag-test", 'submitted_at' => now()->addSeconds(3)]);
    $html = $dashboard->exitSurveys($bind('/portal/admin/exit-surveys'))->render();
    $check(!str_contains($html, "$tag-demo") && !str_contains($html, "$tag-test"), 'Default comments exclude demo and test submissions');
    $html = $dashboard->exitSurveys($bind('/portal/admin/exit-surveys', ['include_simulated' => 1]))->render();
    $check(str_contains($html, "$tag-demo") && str_contains($html, 'Simulated response') && !str_contains($html, "$tag-test"), 'Demo comments require explicit inclusion and remain labeled; test comments stay excluded');
    $filtered = $dashboard->exitSurveys($bind('/portal/admin/exit-surveys', ['purpose' => 'Business']))->getData();
    $check(!$filtered['surveyComments']->contains('id', $survey->id), 'Profile filters apply to written comments');
    $empty = $dashboard->exitSurveys($bind('/portal/admin/exit-surveys', ['from' => '2099-01-01', 'to' => '2099-01-02']))->render();
    $check(!str_contains($empty, $tag) && str_contains($empty, 'No written feedback in this selection'), 'Date-filtered empty state is explicit');
    for ($i = 0; $i < 16; $i++) App\Models\ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes', 'data_source' => 'real', 'comments' => "$tag page $i", 'travel_purpose' => 'Medical', 'submitted_at' => now()->addSeconds(10 + $i)]);
    $paged = $dashboard->exitSurveys($bind('/portal/admin/exit-surveys', ['purpose' => 'Medical', 'comments_page' => 2]))->getData()['surveyComments'];
    $check($paged->currentPage() === 2 && $paged->count() > 0 && $paged->count() <= 15, 'Older comments are reachable through pagination');
    $check(str_contains($paged->url(1), 'purpose=Medical') && str_contains($paged->url(1), '#survey-comments'), 'Comment pagination keeps filters and its section anchor');

    $region = App\Models\Region::firstOrFail();
    $place = App\Models\Destination::create(['slug' => strtolower($tag), 'name' => "$tag Place", 'location' => 'Davao City', 'region_id' => $region->id, 'type' => 'Nature', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0]);
    $token = (string) Illuminate\Support\Str::uuid();
    App\Models\TouristVisit::create(['visitor_token' => $token, 'listing_kind' => 'destination', 'listing_id' => $place->id, 'visit_date' => now()->toDateString(), 'source' => 'qr_scan']);
    app(App\Http\Controllers\ReviewController::class)->store($bind('/reviews', ['rating' => 4, 'comment' => "$tag review <b>keep plain text</b>"], 'POST', ['visitor_token' => $token]), 'destinations', $place->id);
    $review = App\Models\Review::where('visitor_token', $token)->firstOrFail();
    $check(str_contains($review->comment, $tag), 'Checked-in establishment visitor review stores its written comment');
    $review->update(['owner_reply' => "$tag reply <i>plain</i>"]);
    $feedback = app(App\Http\Controllers\Admin\AdminFeedbackAnalysisController::class);
    $statistics = app(App\Services\Feedback\ReviewStatistics::class);
    $reviewView = $feedback->index($bind('/portal/admin/feedback-analysis', ['establishment' => 'destination:'.$place->id]), $statistics);
    $html = $reviewView->render();
    $check(str_contains($html, "$tag review") && str_contains($html, "$tag Place"), 'DOT Visitor Feedback displays the specific review and establishment');
    $check(str_contains($html, '&lt;b&gt;keep plain text&lt;/b&gt;') && !str_contains($html, '<b>keep plain text</b>'), 'Establishment review text is escaped');
    $check(str_contains($html, "$tag reply") && str_contains($html, '&lt;i&gt;plain&lt;/i&gt;'), 'Establishment reply is visible and escaped');
    $check(!str_contains($html, $token), 'Visitor token is never displayed with the written review');
    $other = $feedback->index($bind('/portal/admin/feedback-analysis', ['establishment' => 'destination:999999999']), $statistics)->getData();
    $check(!$other['writtenReviews']->contains('id', $review->id), 'Establishment filter restricts the written review list');
    $outside = $feedback->index($bind('/portal/admin/feedback-analysis', ['from' => '2099-01-01', 'to' => '2099-01-02']), $statistics)->getData();
    $check(!$outside['writtenReviews']->contains('id', $review->id), 'Date filters restrict the written review list');
    $request = $bind('/portal/admin/exit-surveys');
    auth('admin')->setUser(App\Models\AdminUser::firstOrFail());
    $response = $kernel->handle($request);
    $check($response->getStatusCode() === 200 && str_contains($response->getContent(), 'survey-comments'), 'Authenticated Exit Surveys route renders the comment section');
    $request = $bind('/portal/admin/feedback-analysis');
    $response = $kernel->handle($request);
    $check($response->getStatusCode() === 200 && str_contains($response->getContent(), 'written-reviews'), 'Authenticated Visitor Feedback route renders written reviews');
} finally {
    Illuminate\Support\Facades\DB::rollBack();
}
echo "$checks written-feedback checks passed; all QA records rolled back.\n";
