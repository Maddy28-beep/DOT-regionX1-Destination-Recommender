<?php
// Item 8b: time DOT-admin pages through the real HTTP kernel (middleware, controller, Blade) on the local production copy.
// An admin user is created inside a transaction that is rolled back. Each page: 1 warm-up + 7 timed requests.
$wt = getenv('WT'); chdir($wt); require $wt.'/vendor/autoload.php'; $app = require $wt.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
use Illuminate\Http\Request; use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Hash; use App\Models\AdminUser;
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
DB::beginTransaction();
try {
    $admin = AdminUser::create(['email' => 'timing@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Timing', 'role' => 'super_admin']);
    $from = '2026-09-01'; $to = '2026-11-07';
    $pages = [
        'overview' => '/portal/admin', 'establishments' => '/portal/admin/establishments', 'accreditation' => '/portal/admin/accreditation',
        'exit-surveys' => '/portal/admin/exit-surveys', 'association-rules' => '/portal/admin/association-rules', 'audit-log' => '/portal/admin/audit-log',
        'data-completeness' => '/portal/admin/data-completeness', 'feedback-analysis' => '/portal/admin/feedback-analysis',
        'reports (exit surveys)' => "/portal/admin/reports?report_type=Exit+Survey+Responses&from=$from&to=$to",
        'reports (destination visits)' => "/portal/admin/reports?report_type=Destination+Visits&from=$from&to=$to",
        'listings: destinations' => '/portal/admin/listings/destinations', 'listings: restaurants' => '/portal/admin/listings/restaurants',
        'package-approvals' => '/portal/admin/package-approvals', 'advisories' => '/portal/admin/advisories',
        'public: /destinations (map)' => '/destinations',
    ];
    $res = [];
    foreach ($pages as $label => $uri) {
        $times = []; $status = null;
        for ($i = 0; $i < 8; $i++) {
            auth()->shouldUse('admin'); auth('admin')->setUser($admin);
            $req = Request::create($uri, 'GET');
            $t0 = hrtime(true);
            $resp = $kernel->handle($req);
            $ms = (hrtime(true) - $t0) / 1e6; $status = $resp->getStatusCode();
            $kernel->terminate($req, $resp);
            if ($i > 0) { $times[] = $ms; }
        }
        sort($times);
        $res[] = [$label, $status, round($times[intdiv(count($times), 2)]), round(max($times)), strlen((string) $resp->getContent())];
    }
    printf("%-30s %-6s %8s %8s %9s\n", 'page', 'status', 'p50 ms', 'max ms', 'bytes');
    foreach ($res as $r) { printf("%-30s %-6s %8d %8d %9d\n", ...$r); }
} finally { DB::rollBack(); }
