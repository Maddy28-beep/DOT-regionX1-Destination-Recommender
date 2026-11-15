<?php

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('https://www.exploredavao.com/exit-survey'));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config(['session.driver' => 'array', 'cache.default' => 'array']);
$checks = 0;
$check = function ($condition, $label) use (&$checks) {
    if (!$condition) throw new RuntimeException($label);
    echo "PASS: $label\n";
    $checks++;
};
$session = $app['session']->driver();
$session->start();
$bind = function ($data = [], $method = 'GET') use ($app, $session) {
    $request = Illuminate\Http\Request::create('https://www.exploredavao.com/exit-survey', $method, $data);
    $request->setLaravelSession($session);
    $app->instance('request', $request);
    return $request;
};
Illuminate\Support\Facades\DB::beginTransaction();
try {
    if (!Illuminate\Support\Facades\Schema::hasTable('exit_survey_stays')) {
        (require getcwd().'/database/migrations/2026_11_15_150000_create_exit_survey_stays_table.php')->up();
    }
    $controller = $app->make(App\Http\Controllers\ExitSurveyController::class);
    $admin = $app->make(App\Http\Controllers\Admin\AdminAccommodationStayController::class);
    $submit = function ($stays = [], $source = 'test') use ($bind, $controller, $session) {
        $controller->store($bind(['overall_rating' => 4, 'would_recommend' => 'Yes', 'test' => 1, 'stays' => $stays], 'POST'));
        $id = $session->get('last_exit_survey_id');
        if ($source !== 'test') App\Models\ExitSurvey::withoutGlobalScopes()->whereKey($id)->update(['data_source' => $source]);
        return $id;
    };
    $bind();
    $html = $controller->create()->with('errors', new Illuminate\Support\ViewErrorBag())->render();
    $check(str_contains($html, 'Where did you stay?') && str_contains($html, 'Other / not in the list'), 'Neutral accommodation question and Other option render');
    $check(!str_contains($html, 'identify accommodations to check') && !str_contains($html, 'unaccredited businesses'), 'Visitor wording does not disclose internal follow-up workflow');
    $directory = App\Services\SurveyStayCapture::directory()->firstOrFail();
    $sample = App\Models\Accommodation::whereHas('accreditationRecords', fn ($q) => $q->where('is_placeholder', true))->first();
    $check(!$sample || !App\Services\SurveyStayCapture::directory()->whereKey($sample->id)->exists(), 'Sample accommodations excluded from directory options');
    $id = $submit();
    $check(Illuminate\Support\Facades\DB::table('exit_survey_stays')->where('exit_survey_id', $id)->count() === 0, 'Existing two-answer submission still works');
    $manual = ['place' => 'other', 'name' => '=Rental QA', 'locality' => 'Matina, Davao City'];
    $first = $submit([$manual, $manual], 'real');
    $row = Illuminate\Support\Facades\DB::table('exit_survey_stays')->where('exit_survey_id', $first)->first();
    $check($row->place_name === '=Rental QA' && $row->directory_status === 'unverified' && $row->accommodation_id === null, 'Manual stay saved as unverified, never as an accreditation finding');
    $check(Illuminate\Support\Facades\DB::table('exit_survey_stays')->where('exit_survey_id', $first)->count() === 1, 'Duplicate stay within one survey counted once');
    $second = $submit([['place' => 'other', 'name' => '  =rental   qa ', 'locality' => 'MATINA, DAVAO CITY']], 'real');
    $third = $submit([array_replace($manual, ['locality' => 'Tagum City'])], 'real');
    $submit([$manual], 'demo');
    $submit([$manual]);
    $listedId = $submit([['place' => 'accommodation:'.$directory->id]], 'real');
    $listed = Illuminate\Support\Facades\DB::table('exit_survey_stays')->where('exit_survey_id', $listedId)->first();
    $check($listed->accommodation_id === $directory->id && $listed->directory_status === 'directory_match', 'Directory selection snapshots its name and match without inventing verification');
    $privateId = $submit([['place' => 'friends_family', 'name' => 'Must not save private host', 'locality' => 'Must not save address']]);
    $privateRow = Illuminate\Support\Facades\DB::table('exit_survey_stays')->where('exit_survey_id', $privateId)->first();
    $check($privateRow->place_name === null && $privateRow->locality === null, 'Private-host details are discarded');

    $invalidCases = [
        [['place' => 'other', 'name' => '']],
        [['place' => 'other', 'name' => 'Hotel', 'locality' => '   ']],
        [['place' => 'accommodation:999999999']],
        [['place' => 'bad-choice']],
        [['place' => 'day_trip'], $manual],
        array_fill(0, 6, $manual),
        [array_replace($manual, ['name' => str_repeat('a', 181)])],
        [array_replace($manual, ['directory_status' => 'accredited'])],
    ];
    if ($sample) $invalidCases[] = [['place' => 'accommodation:'.$sample->id]];
    foreach ($invalidCases as $index => $answers) {
        $rejected = false;
        try { $submit($answers); } catch (Illuminate\Validation\ValidationException $e) { $rejected = true; }
        $check($rejected, 'Invalid stay case '.($index + 1).' rejected');
    }
    $request = $bind();
    $data = $admin->index($request)->getData();
    $check($data['unverified'] === 3 && $data['places']->total() === 2, 'Real-only report excludes demo/test and keeps different areas separate');
    $matina = $data['places']->firstWhere('locality', 'MATINA, DAVAO CITY') ?? $data['places']->firstWhere('locality', 'Matina, Davao City');
    $check($matina && (int)$matina->responses === 2, 'Case and whitespace variants group into two survey responses');
    $allData = $admin->index($bind(['status' => 'all']))->getData();
    $check($allData['places']->total() === 3, 'All-properties report includes the directory match');
    $response = $admin->export($bind());
    ob_start(); $response->sendContent(); $csv = ob_get_clean();
    $check(str_contains($csv, "'=Rental QA") && str_contains($csv, 'Unverified') && str_contains($csv, 'not confirmed unaccredited'), 'CSV escapes formula-like property names and labels unverified leads');
    $check(substr_count($csv, 'Unverified — manual entry') === 2, 'CSV and report have the same grouped rows');
    $invalidDate = false;
    try { $admin->index($bind(['from' => '2026-11-15', 'to' => '2026-11-01'])); }
    catch (Illuminate\Validation\ValidationException $e) { $invalidDate = true; }
    $check($invalidDate, 'Reversed date range rejected');
    $future = $admin->index($bind(['from' => '2099-01-01', 'to' => '2099-01-02']))->getData();
    $check($future['places']->total() === 0 && $future['answered'] === 0, 'Empty date selection supported');
    $oneDate = $admin->index($bind(['to' => '2099-01-01']))->getData();
    $check($oneDate['unverified'] === 3, 'End-date-only filter supported');

    $session->put('_old_input', ['stays' => [$manual, ['place' => 'accommodation:'.$directory->id]]]);
    $bind();
    $html = $controller->create()->with('errors', new Illuminate\Support\ViewErrorBag())->render();
    $check(str_contains($html, 'value="=Rental QA"') && str_contains($html, 'name="stays[1][place]"'), 'Multiple stay answers survive validation');
    $session->forget('_old_input');
    $request = Illuminate\Http\Request::create('https://www.exploredavao.com/portal/admin/accommodation-stays');
    $app->instance('request', $request);
    auth('admin')->setUser(App\Models\AdminUser::firstOrFail());
    $response = $kernel->handle($request);
    $check($response->getStatusCode() === 200 && str_contains($response->getContent(), 'Reported places to stay'), 'Authenticated DOT report renders with the shared admin shell');
} finally {
    Illuminate\Support\Facades\DB::rollBack();
}
echo "$checks checks passed; schema changes and QA submissions rolled back.\n";
