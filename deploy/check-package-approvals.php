<?php

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('https://www.exploredavao.com/portal/admin/package-approvals'));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config(['session.driver' => 'array', 'cache.default' => 'array', 'hashing.bcrypt.rounds' => 4]);
$inventory = [
    'packages_total' => App\Models\Package::count(),
    'public_packages' => App\Models\Package::publiclyVisible()->count(),
    'operator_linked' => App\Models\Package::whereNotNull('tour_operator_id')->count(),
    'ever_submitted' => App\Models\Package::whereNotNull('submitted_at')->count(),
    'waiting_for_review' => App\Models\Package::whereNotNull('submitted_at')->where('status', 'in_review')->count(),
    'by_status' => App\Models\Package::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
];
echo json_encode(['live_inventory' => $inventory], JSON_PRETTY_PRINT).PHP_EOL;
if (in_array('--inventory', $argv, true)) exit;
// CSRF is skipped only inside this CLI QA app; real route authorization still runs.
$app->instance('env', 'testing');
$checks = 0;
$check = function ($ok, $message) use (&$checks) {
    if (!$ok) throw new RuntimeException($message);
    $checks++; echo "PASS: $message\n";
};
$send = function ($method, $path, $data = [], $guard = null, $user = null, $json = false) use ($app, $kernel) {
    $request = Illuminate\Http\Request::create('https://www.exploredavao.com'.$path, $method, $data, [], [], ['HTTP_ACCEPT' => $json ? 'application/json' : 'text/html', 'HTTP_REFERER' => 'https://www.exploredavao.com/portal/establishment/packages']);
    $app->instance('request', $request);
    $app['auth']->forgetGuards();
    $app['auth']->shouldUse($guard ?: 'web');
    if ($guard && $user) $app['auth']->guard($guard)->setUser($user);
    return $kernel->handle($request);
};
$ok = function ($response, $status, $message) use ($check) {
    $check($response->getStatusCode() === $status, $message.' (HTTP '.$response->getStatusCode().')'.($response->getStatusCode() === $status ? '' : ' '.substr(strip_tags($response->getContent()), 0, 250)));
};
Illuminate\Support\Facades\DB::beginTransaction();
try {
    $tag = 'qa-link-'.bin2hex(random_bytes(5));
    $region = App\Models\Region::firstOrFail();
    $makeOperator = function ($suffix) use ($tag, $region) {
        $operator = App\Models\TourOperator::create(['slug' => "$tag-$suffix", 'name' => "QA Operator $tag $suffix", 'location' => 'Davao City', 'region_id' => $region->id, 'is_accredited' => true, 'rating' => 0, 'review_count' => 0]);
        App\Models\AccreditationRecord::create(['listing_kind' => 'tour_operator', 'listing_id' => $operator->id, 'accreditation_number' => 'QA-'.$operator->id, 'status' => 'Active', 'issue_date' => now()->subYear(), 'expiration_date' => now()->addYear(), 'is_placeholder' => false]);
        $account = App\Models\EstablishmentAccount::create(['business_name' => $operator->name, 'listing_kind' => 'tour_operator', 'matched_listing_id' => $operator->id, 'portal_key' => (string) Illuminate\Support\Str::uuid(), 'email' => "$tag-$suffix@example.invalid", 'password_hash' => Illuminate\Support\Facades\Hash::make('QA-only-no-login'), 'contact_person' => 'QA', 'contact_number' => '00000000000', 'status' => 'approved', 'submitted_at' => now()]);
        return [$operator, $account];
    };
    [$operator, $account] = $makeOperator('one');
    [$otherOperator, $otherAccount] = $makeOperator('two');
    $admin = App\Models\AdminUser::create(['email' => "$tag-admin@example.invalid", 'password_hash' => Illuminate\Support\Facades\Hash::make('QA-only-no-login'), 'full_name' => 'QA DOT admin', 'role' => 'super_admin']);
    $payload = ['intent' => 'draft', 'name' => "QA Package $tag", 'description' => 'A transaction-only package to verify operator submission and the DOT approval workflow.', 'price_per_pax' => 2450, 'price_tier' => 'Mid-range', 'meeting_point' => 'QA meeting point', 'inclusions' => ['Meals', 'Boat or van'], 'itinerary' => "Day one | QA itinerary detail\nDay two | QA itinerary detail"];
    $ok($send('GET', '/portal/admin/package-approvals'), 302, 'Guest cannot access DOT package queue');
    $ok($send('GET', '/portal/admin/package-approvals', [], 'establishment', $account), 302, 'Operator cannot access DOT package queue');
    $ok($send('GET', '/portal/establishment/packages', [], 'establishment', $account), 200, 'Linked operator can open My packages');
    $ok($send('POST', '/portal/establishment/packages', $payload, 'establishment', $account, true), 200, 'Operator creates a package draft');
    $package = App\Models\Package::where('tour_operator_id', $operator->id)->latest('id')->firstOrFail();
    $check($package->status === 'draft' && !$package->is_accredited && $package->itineraryDays()->count() === 2, 'Draft is linked to the correct operator with its itinerary');
    $ok($send('GET', '/packages/'.$package->slug), 404, 'Draft is hidden from public package detail');
    $ok($send('GET', '/portal/establishment/packages/'.$package->id, [], 'establishment', $otherAccount), 403, 'Another operator cannot open the package');
    $ok($send('POST', '/portal/establishment/packages/'.$package->id.'/submit', [], 'establishment', $account), 302, 'Operator submits the draft to DOT');
    $package->refresh();
    $check($package->status === 'in_review' && $package->submitted_at && !$package->is_accredited, 'Submitted package enters review and remains hidden');
    $queue = $send('GET', '/portal/admin/package-approvals', [], 'admin', $admin);
    $ok($queue, 200, 'DOT queue renders after operator submission');
    $check(str_contains($queue->getContent(), $package->name) && str_contains($queue->getContent(), '/package-approvals/'.$package->id), 'Submitted package appears with a working review link');
    $data = app(App\Http\Controllers\Admin\AdminPackageApprovalController::class)->index(Illuminate\Http\Request::create('/portal/admin/package-approvals'))->getData();
    $check($data['counts']['waiting'] === $inventory['waiting_for_review'] + 1 && $data['counts']['all'] === $inventory['ever_submitted'] + 1, 'Waiting and All counts are connected to submitted records');
    $ok($send('GET', '/portal/admin/package-approvals/'.$package->id, [], 'admin', $admin), 200, 'DOT can open the full review screen');
    $ok($send('POST', '/portal/admin/package-approvals/'.$package->id.'/decide', ['decision' => 'changes'], 'admin', $admin, true), 422, 'Requesting changes requires an explanation');
    $note = 'QA: please clarify the itinerary meeting time.';
    $ok($send('POST', '/portal/admin/package-approvals/'.$package->id.'/decide', ['decision' => 'changes', 'note' => $note], 'admin', $admin, true), 200, 'DOT requests changes');
    $package->refresh();
    $check($package->status === 'changes_requested' && $package->review_note === $note && !$package->is_accredited, 'Changes status and exact DOT note are stored');
    $changesData = app(App\Http\Controllers\Admin\AdminPackageApprovalController::class)->index(Illuminate\Http\Request::create('/portal/admin/package-approvals?tab=changes'))->getData();
    $check($changesData['rows']->contains('id', $package->id) && $changesData['counts']['waiting'] === $inventory['waiting_for_review'] && $changesData['counts']['changes'] > 0, 'Changes-requested tab and counts update after the DOT decision');
    $check(App\Models\Notification::where('user_type', 'establishment')->where('user_id', $account->id)->where('message', 'like', '%'.$note.'%')->exists(), 'The linked operator receives the in-system change notification');
    $operatorPage = $send('GET', '/portal/establishment/packages/'.$package->id, [], 'establishment', $account);
    $check($operatorPage->getStatusCode() === 200 && str_contains($operatorPage->getContent(), $note), 'Operator can read the DOT note on their package');
    $ok($send('POST', '/portal/establishment/packages/'.$package->id.'/submit', [], 'establishment', $account), 302, 'Operator resubmits after changes');
    $operator->accreditationRecords()->update(['status' => 'Expired']);
    $ok($send('POST', '/portal/admin/package-approvals/'.$package->id.'/decide', ['decision' => 'approve'], 'admin', $admin, true), 422, 'Expired operator cannot have a package approved');
    $operator->accreditationRecords()->update(['status' => 'Active']);
    $ok($send('POST', '/portal/admin/package-approvals/'.$package->id.'/decide', ['decision' => 'approve'], 'admin', $admin, true), 200, 'DOT approves the resubmitted package');
    $package->refresh();
    $check($package->status === 'live' && $package->is_accredited && $package->reviewed_at && $package->review_note === null, 'Approval updates public flag and review metadata');
    $approvedData = app(App\Http\Controllers\Admin\AdminPackageApprovalController::class)->index(Illuminate\Http\Request::create('/portal/admin/package-approvals?tab=approved'))->getData();
    $check($approvedData['rows']->contains('id', $package->id) && $approvedData['counts']['approved'] > 0 && $approvedData['approvedThisMonth'] > 0, 'Approved tab and approved-this-month KPI update after approval');
    $check($approvedData['averageDays'] !== null && $approvedData['averageDays'] >= 0, 'Average decision time is calculated from submitted and reviewed timestamps');
    $ok($send('GET', '/packages/'.$package->slug), 200, 'Approved package is accessible to public visitors');
    $public = $send('GET', '/packages?q='.urlencode($package->name));
    $check($public->getStatusCode() === 200 && str_contains($public->getContent(), $package->name), 'Approved package is listed in public search');
    $ok($send('POST', '/portal/admin/package-approvals/'.$package->id.'/decide', ['decision' => 'approve'], 'admin', $admin, true), 422, 'Already decided package cannot be approved twice');
    $ok($send('POST', '/portal/admin/package-approvals/'.$package->id.'/pause', [], 'admin', $admin, true), 200, 'DOT can pause an approved package');
    $ok($send('GET', '/packages/'.$package->slug), 404, 'Paused package is hidden from public visitors');
    $ok($send('POST', '/portal/admin/package-approvals/'.$package->id.'/resume', [], 'admin', $admin, true), 200, 'DOT can resume a paused package');
    $ok($send('GET', '/packages/'.$package->slug), 200, 'Resumed package is public again');
    $check($package->events()->whereIn('action', ['submitted', 'changes_requested', 'approved', 'paused', 'resumed'])->count() === 6, 'Package history links submission, decisions, pause and resume');
    foreach (['changes', 'approved', 'all'] as $tab) $ok($send('GET', '/portal/admin/package-approvals?tab='.$tab, [], 'admin', $admin), 200, 'Queue tab '.$tab.' renders');
    $searched = $send('GET', '/portal/admin/package-approvals?tab=all&q='.urlencode($operator->name), [], 'admin', $admin);
    $check($searched->getStatusCode() === 200 && str_contains($searched->getContent(), $package->name), 'DOT search finds submitted packages by operator');
    $send('POST', '/portal/admin/package-approvals/'.$package->id.'/pause', [], 'admin', $admin, true);
    $operator->accreditationRecords()->update(['status' => 'Expired']);
    $eventCount = $package->events()->count();
    $notificationCount = App\Models\Notification::where('user_type', 'establishment')->where('user_id', $account->id)->count();
    $resume = $send('POST', '/portal/admin/package-approvals/'.$package->id.'/resume', [], 'admin', $admin, true);
    $ok($resume, 422, 'DOT cannot resume a package after operator accreditation expires');
    $package->refresh();
    $check($package->status === 'paused' && !$package->is_accredited, 'Rejected resume keeps the package paused and hidden');
    $check($package->events()->count() === $eventCount && App\Models\Notification::where('user_type', 'establishment')->where('user_id', $account->id)->count() === $notificationCount, 'Rejected resume creates no false history or notification');
    $ok($send('GET', '/packages/'.$package->slug), 404, 'Package remains inaccessible publicly after rejected resume');
    $operator->accreditationRecords()->update(['status' => 'Active']);
    $ok($send('POST', '/portal/admin/package-approvals/'.$package->id.'/resume', [], 'admin', $admin, true), 200, 'DOT can resume again after accreditation is restored');
} finally {
    Illuminate\Support\Facades\DB::rollBack();
}
if (App\Models\Package::count() !== $inventory['packages_total']) throw new RuntimeException('QA package rollback verification failed');
echo "$checks checks passed; all QA packages, accounts, notifications and history rolled back.\n";
