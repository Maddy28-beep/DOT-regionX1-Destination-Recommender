<?php
// Q3 audit harness (scratch, read-only queries only).
$wt = getenv('WT'); chdir($wt);
require $wt.'/vendor/autoload.php';
$app = require $wt.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\ExitSurvey; use App\Models\ExitSurveyVisit; use App\Models\Destination;
use App\Services\Recommendation\AprioriService;
use Illuminate\Support\Facades\DB;

$svc = $app->make(AprioriService::class);
echo "== transactions by data_source ==\n";
foreach (ExitSurvey::select('data_source', DB::raw('count(*) c'))->groupBy('data_source')->get() as $r) {
    $visits = ExitSurveyVisit::whereIn('exit_survey_id', ExitSurvey::where('data_source', $r->data_source)->select('id'))->count();
    printf("  %-10s transactions=%d visit rows=%d\n", $r->data_source ?? 'NULL', $r->c, $visits);
}
echo "  total surveys=", ExitSurvey::count(), " visit rows=", ExitSurveyVisit::count(), " distinct listing kinds: ", ExitSurveyVisit::select('listing_kind')->distinct()->pluck('listing_kind')->implode(','), "\n";
$sizes = ExitSurveyVisit::select('exit_survey_id', DB::raw('count(distinct listing_kind||listing_id) n'))->groupBy('exit_survey_id')->get()->pluck('n');
printf("  basket size: min=%d max=%d mean=%.2f ; baskets with 1 item=%d, 2+=%d, 3+=%d\n", $sizes->min(), $sizes->max(), $sizes->avg(), $sizes->filter(fn ($n) => $n == 1)->count(), $sizes->filter(fn ($n) => $n >= 2)->count(), $sizes->filter(fn ($n) => $n >= 3)->count());

echo "\n== rule counts under the production thresholds ==\n";
foreach ([['all sources (as the planner uses)', null], ['real only', ['real']], ['itinerary only', ['itinerary']], ['demo only', ['demo']], ['real+itinerary', ['real', 'itinerary']]] as [$label, $src]) {
    $s = $svc->onlySources($src);
    $admin = $s->topRules(100000, 2, 0.15);
    $planner = $s->topRules(100000, 2, 0.10);
    $strict = $s->topRules(100000, 1, 0.0);
    printf("  %-34s admin page (co>=2, conf>=15%%): %5d | planner (co>=2, conf>=10%%): %5d | every observed pair (co>=1): %5d\n", $label, $admin->count(), $planner->count(), $strict->count());
}

// brute-force independent check of one rule family
echo "\n== independent recount (PHP brute force) vs service ==\n";
$baskets = ExitSurveyVisit::select('exit_survey_id', 'listing_kind', 'listing_id')->get()->groupBy('exit_survey_id')->map(fn ($g) => $g->map(fn ($v) => $v->listing_kind.':'.$v->listing_id)->unique()->values()->all());
$total = ExitSurvey::count();
$A = []; $P = [];
foreach ($baskets as $items) { foreach ($items as $a) { $A[$a] = ($A[$a] ?? 0) + 1; foreach ($items as $b) { if ($a !== $b) { $P["$a|$b"] = ($P["$a|$b"] ?? 0) + 1; } } } }
$mine = 0; $brute = [];
foreach ($P as $k => $co) { [$a, $b] = explode('|', $k); $conf = round($co / $A[$a], 4); if ($co >= 2 && $conf >= 0.15) { $brute[$k] = [$co, $conf, round($co / $total, 4)]; } }
$svcRules = $svc->topRules(100000, 2, 0.15)->mapWithKeys(fn ($r) => [$r['a_kind'].':'.$r['a_id'].'|'.$r['b_kind'].':'.$r['b_id'] => [$r['co_count'], $r['confidence'], $r['support']]]);
$diff = 0; foreach ($brute as $k => $v) { if (! isset($svcRules[$k]) || $svcRules[$k] !== $v) { $diff++; } }
printf("  brute-force rules=%d  service rules (after dropping unresolvable listings)=%d  mismatching/missing in service=%d\n", count($brute), $svcRules->count(), $diff);

echo "\n== what the planner actually receives ==\n";
$withRules = 0; $totalRules = 0; $byKind = [];
$dests = Destination::publiclyVisible()->where(fn ($q) => $q->whereNull('itinerary_role')->orWhere('itinerary_role', 'sightseeing'))->get();
foreach ($dests as $d) {
    foreach (['accommodation', 'restaurant', 'souvenir_center'] as $kind) {
        $rules = $svc->getAssociatedListings('destination', $d->id, 100, 0.1, 2, $kind);
        $res = $svc->resolveListings($rules);
        if ($res->isNotEmpty()) { $byKind[$kind] = ($byKind[$kind] ?? 0) + 1; }
        $totalRules += $res->count();
    }
}
printf("  plannable destinations=%d ; destinations with >=1 usable stay rule=%d, restaurant rule=%d, souvenir rule=%d ; usable (destination->listing) rules=%d\n", $dests->count(), $byKind['accommodation'] ?? 0, $byKind['restaurant'] ?? 0, $byKind['souvenir_center'] ?? 0, $totalRules);
printf("  rules by source of the evidence for those pairs: real-only rules (co>=2,conf>=10%%) = %d\n", $svc->onlySources(['real'])->topRules(100000, 2, 0.10)->count());
// is any destination-to-listing rule supported only by demo rows?
$demoOnly = 0; $any = 0;
foreach ($svc->topRules(100000, 2, 0.10) as $r) {
    $any++;
    $real = $svc->onlySources(['real', 'itinerary']);
}
echo "  (provenance split of planner-visible rules is reported above by source)\n";
