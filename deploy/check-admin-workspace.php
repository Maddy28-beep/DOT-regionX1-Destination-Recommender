<?php

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('https://www.exploredavao.com/portal/admin', 'GET'));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config(['session.driver' => 'array', 'cache.default' => 'array']);
$admin = App\Models\AdminUser::firstOrFail();
$routes = [
    ['admin.overview', []], ['admin.listings.index', ['type' => 'destinations']],
    ['admin.listings.index', ['type' => 'accommodations']], ['admin.listings.index', ['type' => 'restaurants']],
    ['admin.listings.index', ['type' => 'souvenir-centers']], ['admin.listings.index', ['type' => 'tour-operators']],
    ['admin.listings.index', ['type' => 'packages']], ['admin.establishments', []], ['admin.package-approvals', []],
    ['admin.accreditation', []], ['admin.advisories.index', []], ['admin.events.index', []], ['admin.postcard-slides.index', []],
    ['admin.reports', []], ['admin.exit-surveys', []], ['admin.visitor-origin', []],
    ['admin.visitor-origin', ['metric' => 'generated']], ['admin.association-rules', []],
    ['admin.feedback-analysis', []], ['admin.data-completeness', []], ['admin.audit-log', []],
    ['admin.listings.create', ['type' => 'destinations']], ['admin.events.create', []], ['admin.advisories.create', []],
    ['admin.listings.index', ['type' => 'destinations', 'status' => 'archived']],
    ['admin.listings.index', ['type' => 'destinations', 'status' => 'all', 'q' => 'zz-no-matching-listing-qa']],
    ['admin.accreditation', ['status' => 'Expired']], ['admin.accreditation', ['status' => 'Expiring Soon']],
    ['admin.visitor-origin', ['from' => now('Asia/Manila')->toDateString(), 'to' => now('Asia/Manila')->toDateString()]],
    ['admin.reports', ['from' => now('Asia/Manila')->toDateString(), 'to' => now('Asia/Manila')->toDateString()]],
    ['admin.establishments', ['status' => 'approved']], ['admin.establishments', ['status' => 'rejected']], ['admin.establishments', ['status' => 'all']],
    ['admin.expenditure', []], ['admin.expenditure', ['include_simulated' => 1]],
    ['admin.expenditure', ['from' => '2099-01-01', 'to' => '2099-01-02']],
    ['admin.expenditure', ['residency' => 'Foreign Tourist']],
];
$failures = [];
$results = [];
Illuminate\Support\Facades\DB::beginTransaction();
try {
    foreach ($routes as [$name, $parameters]) {
        $request = Illuminate\Http\Request::create(route($name, $parameters), 'GET');
        $app->instance('request', $request);
        auth('admin')->setUser($admin);
        $response = $kernel->handle($request);
        $html = $response->getContent();
        $status = $response->getStatusCode();
        $ok = $status === 200 && str_contains($html, 'admin-workspace.css') && str_contains($html, 'id="admin-main"');
        $results[] = ['route' => $name, 'parameters' => $parameters, 'status' => $status, 'shell' => $ok];
        if (!$ok) $failures[] = $name.':'.$status;
        if ($name === 'admin.expenditure' && $ok) {
            if (!str_contains($html, 'Reported expenditure ranges') || !str_contains($html, 'excludes accommodation paid separately in advance')) {
                $failures[] = 'Expenditure ranges or scope disclosure missing';
            }
        }
        if ($name === 'admin.establishments' && $ok) {
            $approvalDom = new DOMDocument();
            @$approvalDom->loadHTML($html);
            $approvalPath = new DOMXPath($approvalDom);
            foreach ($approvalPath->query('//button[contains(concat(" ",normalize-space(@class)," ")," approval-action ")]') as $button) {
                if (!$button->hasAttribute('title') || !$button->hasAttribute('aria-label') || $button->getElementsByTagName('svg')->length !== 1) {
                    $failures[] = 'Approval icon action missing accessible name, tooltip or icon';
                }
            }
        }
        if ($name === 'admin.listings.index' && $ok) {
            $tableDom = new DOMDocument();
            @$tableDom->loadHTML($html);
            $tablePath = new DOMXPath($tableDom);
            if ($tablePath->query('//table/colgroup/col')->length !== $tablePath->query('//table/thead/tr/th')->length) {
                $failures[] = 'Listing grid column mismatch';
            }
        }
        if ($name === 'admin.overview' && $ok) {
            $css = file_get_contents(public_path('css/admin-workspace.css'));
            $preview = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
            if (!in_array('--no-preview', $argv ?? [], true)) {
                file_put_contents(getcwd().'/admin-overview-preview.html', $preview);
            }
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            if ($xpath->query('//aside[@id="adminSidebar"]//a')->length < 15) $failures[] = 'Missing navigation modules';
            if ($xpath->query('//button[@data-sidebar-toggle]')->length !== 1) $failures[] = 'Missing mobile navigation toggle';
            if (!str_contains($html, 'Review queues')) $failures[] = 'Missing review queue shortcuts';
            if ($xpath->query('//a[contains(@class,"adm-kpi--link")]')->length !== 5) $failures[] = 'KPI destinations are not actionable';
        }
    }
} finally {
    Illuminate\Support\Facades\DB::rollBack();
}
echo json_encode(['pages' => $results, 'failures' => $failures, 'preview' => 'admin-overview-preview.html'], JSON_PRETTY_PRINT).PHP_EOL;
if ($failures !== []) exit(1);
