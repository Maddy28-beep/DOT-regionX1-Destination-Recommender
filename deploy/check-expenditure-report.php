<?php

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$controller = app(App\Http\Controllers\Admin\AdminDashboardController::class);
$checks = 0;
foreach ([[], ['include_simulated' => 1], ['residency' => 'Foreign Tourist'], ['from' => '2099-01-01', 'to' => '2099-01-02']] as $filters) {
    $request = Illuminate\Http\Request::create('/portal/admin/expenditure', 'GET', $filters);
    $app->instance('request', $request);
    $data = $controller->expenditure($request)->getData();
    $expected = App\Models\ExitSurvey::respondents()->whereIn('data_source', empty($filters['include_simulated']) ? ['real'] : ['real', 'demo'])
        ->when($filters['residency'] ?? null, fn ($q, $value) => $q->where('residency_type', $value))
        ->when($filters['from'] ?? null, fn ($q, $value) => $q->whereDate('submitted_at', '>=', $value))
        ->when($filters['to'] ?? null, fn ($q, $value) => $q->whereDate('submitted_at', '<=', $value))->get(['estimated_total_spend']);
    $answered = $expected->whereIn('estimated_total_spend', array_keys(App\Http\Controllers\ExitSurveyController::SPEND_BRACKETS))->count();
    if ($data['expenditure']['total'] !== $expected->count() || $data['expenditure']['answered'] !== $answered
        || array_sum(array_column($data['expenditure']['ranges'], 'count')) !== $answered) {
        throw new RuntimeException('Report source, filter or denominator mismatch');
    }
    $checks++;
    ob_start();
    $controller->expenditureCsv($request)->sendContent();
    $csv = ob_get_clean();
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $csv);
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream)) !== false) { $rows[$row[0]] = $row; }
    fclose($stream);
    if ((int) ($rows['Answered spending question'][1] ?? -1) !== $answered || !str_contains($csv, 'not exact expenditure')
        || !str_contains($csv, 'excludes accommodation paid separately in advance')) {
        throw new RuntimeException('CSV totals or scope disclosure differ from report');
    }
    if (!empty($filters['include_simulated']) && !str_contains($csv, 'demonstration results')) {
        throw new RuntimeException('Demo export must disclose simulated data');
    }
    $checks++;
}
foreach ([['from' => 'bad-date'], ['from' => '2026-11-15', 'to' => '2026-11-01']] as $filters) {
    try {
        $request = Illuminate\Http\Request::create('/portal/admin/expenditure', 'GET', $filters);
        $app->instance('request', $request);
        $controller->expenditure($request);
        throw new RuntimeException('Invalid expenditure date filter accepted');
    } catch (Illuminate\Validation\ValidationException $e) { $checks++; }
}
echo $checks.' expenditure source, filter, CSV and date-validation checks passed; no rows written.'.PHP_EOL;
