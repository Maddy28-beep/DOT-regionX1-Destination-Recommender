<?php
// Reproduce the paper's Table 10 example (Samal Island -> Altavista Beach Resort: support 60%, confidence 75%) with the real service.
$wt = getenv('WT'); chdir($wt);
require $wt.'/vendor/autoload.php';
$app = require $wt.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Accommodation; use App\Models\Destination; use App\Models\ExitSurvey; use App\Models\Restaurant;
use App\Services\Recommendation\AprioriService;
use Illuminate\Support\Facades\DB;
DB::beginTransaction();
try {
    $d = fn ($n) => Destination::where('name', 'like', "$n%")->first();
    $samal = $d('Samal Island'); $eden = $d('Eden Nature'); $malagos = $d('Malagos'); $eagle = $d('Philippine Eagle');
    $alta = Accommodation::where('name', 'like', 'Altavista Beach%')->first(); $marina = Restaurant::where('name', 'like', 'Marina Tuna%')->first();
    $baskets = [
        [[$samal, 'destination'], [$alta, 'accommodation']],                             // T1 (Hagimit Falls not in catalogue -> omitted; does not change A or A&B counts)
        [[$samal, 'destination'], [$alta, 'accommodation']],                             // T2
        [[$samal, 'destination'], [$marina, 'restaurant']],                              // T3 (Paradise Island omitted, same reason)
        [[$eden, 'destination'], [$malagos, 'destination'], [$eagle, 'destination']],    // T4
        [[$samal, 'destination'], [$alta, 'accommodation'], [$marina, 'restaurant']],    // T5
    ];
    foreach ($baskets as $b) {
        $s = ExitSurvey::create(['submitted_at' => now(), 'data_source' => 'itinerary', 'comments' => 'table-10-check']);
        foreach ($b as [$m, $k]) { $s->visits()->create(['listing_kind' => $k, 'listing_id' => $m->id]); }
    }
    $svc = $app->make(AprioriService::class)->onlySources(['itinerary']);
    $rules = $svc->getAssociatedListings('destination', $samal->id, 10, 0.1, 2);
    foreach ($rules as $r) { printf("Samal Island -> %s:%d  co=%d support=%.2f confidence=%.2f\n", $r['listing_kind'], $r['listing_id'], $r['co_count'], $r['support'], $r['confidence']); }
    echo "(paper: Samal -> Altavista support 0.60, confidence 0.75; Samal -> Marina Tuna co=2 support 0.40 confidence 0.50)\n";
    echo "Altavista id=", $alta->id, " Marina id=", $marina->id, "\n";
    // rule below the co-occurrence threshold is NOT returned:
    $single = $svc->getAssociatedListings('destination', $eden->id, 10, 0.1, 2);
    echo "Eden -> anything with co>=2: ", $single->count(), " (T4 is the only basket with Eden, so co=1 and nothing qualifies)\n";
    // lift is not computed anywhere in the rule rows:
    echo "fields returned per rule: ", implode(', ', array_keys($rules->first())), "\n";
} finally { DB::rollBack(); }
