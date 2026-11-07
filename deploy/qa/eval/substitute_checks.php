<?php
// Q2(e): forged swap requests. The controller accepts replacement R for stop S only if R is among
// SimilarDestinationService::alternatives(S, itinerary, 10). Everything runs in a rolled-back transaction.
$wt = getenv('WT'); chdir($wt);
require $wt.'/vendor/autoload.php';
$app = require $wt.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Advisory; use App\Models\Destination; use App\Models\DestinationEmbedding; use App\Models\PreferenceActivity; use App\Models\TouristPreference;
use App\Services\Embeddings\SimilarDestinationService;
use Illuminate\Support\Facades\DB;

$gen = $app->make(App\Services\Recommendation\ItineraryGenerationService::class);
$sim = $app->make(SimilarDestinationService::class);
$hav = function ($a, $b, $c, $d) { $dl = deg2rad($c - $a); $dn = deg2rad($d - $b); $x = sin($dl / 2) ** 2 + cos(deg2rad($a)) * cos(deg2rad($c)) * sin($dn / 2) ** 2; return 6371 * 2 * asin(sqrt($x)); };
DB::beginTransaction();
try {
    $p = TouristPreference::create(['travel_days' => 4, 'travel_type' => 'Solo', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate', 'start_date' => '2026-11-16', 'variation' => 0]);
    foreach (['Nature & Adventure', 'Wildlife'] as $a) { PreferenceActivity::create(['preference_id' => $p->id, 'activity' => $a]); }
    $it = $gen->generate($p->load('activities', 'amenities'));
    $stops = $it->items()->where('kind', 'activity')->whereNotNull('destination_id')->pluck('destination_id')->unique()->values();
    echo "trip stops: ", $stops->implode(','), "\n";
    $S = Destination::find($stops->first());
    printf("stop under test: #%d %s\n", $S->id, $S->name);
    $allowed = $sim->alternatives($S, $it, 10);
    echo "offered (top-10): ", $allowed->map(fn ($r) => $r['destination']->id.'('.$r['distance_km'].'km,'.round($r['similarity'] * 100).'%)')->implode(' '), "\n";
    $max = $allowed->max(fn ($r) => (float) $r['distance_km']);
    printf("max distance among offered = %.1f km (limit %.0f)\n", $max, SimilarDestinationService::MAX_DISTANCE_KM);
    $offeredIds = $allowed->map(fn ($r) => $r['destination']->id)->all();
    $big = $sim->alternatives($S, $it, 1000);
    $bigIds = $big->map(fn ($r) => $r['destination']->id)->all();
    $accepts = fn (int $id) => in_array($id, $offeredIds, true);   // exactly the controller's rule (limit 10)

    $forge = [];
    $pick = fn ($q) => $q->whereNotIn('id', array_merge($stops->all(), [$S->id]))->first();
    $forge['closed_for_good'] = Destination::where('is_accredited', true)->whereNull('archived_at')->whereIn('id', $bigIds)->first();
    if ($forge['closed_for_good']) { Destination::whereKey($forge['closed_for_good']->id)->update(['operating_status' => 'closed']); }
    $forge['temp_closed_no_reopen'] = Destination::whereIn('id', $bigIds)->where('operating_status', '!=', 'closed')->skip(1)->first() ?? null;
    if ($forge['temp_closed_no_reopen']) { Destination::whereKey($forge['temp_closed_no_reopen']->id)->update(['operating_status' => 'temporarily_closed', 'reopens_on' => null]); }
    $forge['danger_advisory'] = Destination::whereIn('id', $bigIds)->skip(2)->first();
    if ($forge['danger_advisory']) { Advisory::create(['title' => 'T', 'message' => 'm', 'severity' => 'danger', 'listing_kind' => 'destination', 'listing_id' => $forge['danger_advisory']->id, 'starts_at' => '2026-11-01', 'ends_at' => '2026-12-31']); }
    $forge['archived'] = Destination::whereIn('id', $bigIds)->skip(3)->first();
    if ($forge['archived']) { Destination::whereKey($forge['archived']->id)->update(['archived_at' => now()]); }
    $forge['excluded_role'] = Destination::where('itinerary_role', 'excluded')->first();
    $forge['not_accredited'] = Destination::where('is_accredited', false)->first();
    $forge['already_in_trip'] = Destination::find($stops->get(1));
    $forge['far_over_60km'] = Destination::whereNotNull('latitude')->get()->first(fn ($d) => $S->latitude !== null && $hav($S->latitude, $S->longitude, $d->latitude, $d->longitude) > 60 && DestinationEmbedding::where('destination_id', $d->id)->exists() && $d->is_accredited);
    $forge['valid_but_rank_11_plus'] = Destination::find(collect($bigIds)->diff($offeredIds)->first());
    $forge['nonexistent_id'] = new Destination(['id' => 999999]); $forge['nonexistent_id']->id = 999999;
    $forge['the_stop_itself'] = $S;
    echo "\nforged replacement -> would the swap endpoint accept? (re-evaluated AFTER the injected closures)\n";
    $offeredAfter = $sim->alternatives($S, $it, 10)->map(fn ($r) => $r['destination']->id)->all();
    foreach ($forge as $label => $d) {
        if (! $d) { printf("  %-26s (no such row in this catalogue)\n", $label); continue; }
        printf("  %-26s id=%-7s accepted=%s%s\n", $label, $d->id, in_array($d->id, $offeredAfter, true) ? 'YES  <-- FAIL' : 'no', $label === 'valid_but_rank_11_plus' ? '  (valid place, just outside the 10 offered)' : '');
    }
    // distance filter: a mapped place >60 km can never be offered; an UNMAPPED one has distance null and is not ruled out
    $unmapped = Destination::whereNull('latitude')->where('is_accredited', true)->whereNull('archived_at')->get()->filter(fn ($d) => DestinationEmbedding::where('destination_id', $d->id)->exists());
    printf("\nunmapped (no coordinates) plannable places with a vector: %d ; offered despite unknown distance: %d\n", $unmapped->count(), $unmapped->filter(fn ($d) => in_array($d->id, $bigIds, true))->count());
} finally { DB::rollBack(); }
