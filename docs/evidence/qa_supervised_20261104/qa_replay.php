<?php
// Rolled-back replay of the supervised QA submission (preference 723 / itinerary 01a1a5c8-...): same inputs, every run inside a
// transaction on the one default connection that is ROLLED BACK. No HTTP, no session, no cache, no queue is involved in generation.
require '/var/www/exploredvo/vendor/autoload.php';
$app = require '/var/www/exploredvo/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PreferenceActivity;
use App\Models\PreferenceAmenity;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Support\Facades\DB;

$IID = '01a1a5c8-2a21-7061-95d1-768d9e0d44b9';
$gen = app(ItineraryGenerationService::class);
$sigOf = fn ($items) => md5(json_encode($items->map(fn ($i) => [$i->day_number, $i->kind, $i->title, (string) $i->starts_at, (string) $i->ends_at, $i->destination_id, $i->accommodation_id, $i->note])->all()));

$tables = ['tourist_preferences', 'preference_activities', 'preference_amenities', 'itineraries', 'itinerary_matches', 'itinerary_items', 'sessions', 'cache', 'exit_surveys', 'chatbot_logs'];
$count = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();

$out = ['connection' => DB::getDefaultConnection(), 'driver' => DB::connection()->getDriverName(), 'level_before' => DB::transactionLevel()];
$out['seq_before'] = DB::selectOne('select last_value from tourist_preferences_id_seq')->last_value;
$before = $count();

$stored = App\Models\ItineraryItem::where('itinerary_id', $IID)->orderBy('day_number')->orderBy('sort_order')->get();
$storedSig = $sigOf($stored);
$out['stored_items'] = $stored->count();

$runs = [];
for ($rep = 0; $rep < 6; $rep++) {
    DB::beginTransaction();
    $level = DB::transactionLevel();
    $queries = 0;
    DB::listen(function () use (&$queries) { $queries++; });
    try {
        $p = TouristPreference::create([
            'travel_days' => 3, 'travel_type' => 'Solo', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'place_of_origin' => 'QA-REPLAY-ROLLBACK-20261104', 'budget' => 'Mid-range', 'accommodation_pref' => 'Hotel',
            'distance_pref' => 'moderate', 'transport_pref' => 'personal_car', 'start_date' => '2026-11-14', 'arrival_time' => '09:00',
            'departure_date' => '2026-11-16', 'departure_time' => '17:30', 'departure_lat' => 7.130, 'departure_lng' => 125.645,
            'departure_label' => 'Davao International Airport',
        ]);
        foreach (['Nature & Adventure', 'Food Tourism'] as $a) { PreferenceActivity::create(['preference_id' => $p->id, 'activity' => $a]); }
        PreferenceAmenity::create(['preference_id' => $p->id, 'amenity' => 'Air Conditioning']);
        $p->load('activities', 'amenities');

        $t = hrtime(true);
        $it = $gen->generate($p);
        $ms = (hrtime(true) - $t) / 1e6;

        $items = $it->items()->orderBy('day_number')->orderBy('sort_order')->get();
        $runs[] = ['rep' => $rep, 'tx_level' => $level, 'gen_ms' => round($ms), 'queries' => $queries, 'items' => $items->count(),
            'matches' => $it->matches()->count(), 'same_as_stored' => $sigOf($items) === $storedSig];
    } finally {
        DB::rollBack();
    }
}
$out['runs'] = $runs;
$out['level_after'] = DB::transactionLevel();
$out['connections_used'] = array_keys(DB::getConnections());
$after = $count();
$out['counts_before'] = $before;
$out['counts_after'] = $after;
$out['replay_marker_rows_left'] = DB::table('tourist_preferences')->where('place_of_origin', 'like', 'QA-REPLAY%')->count();
$out['seq_after'] = DB::selectOne('select last_value from tourist_preferences_id_seq')->last_value;
$out['qa_records_intact'] = ['pref' => DB::table('tourist_preferences')->where('id', 723)->count(), 'itinerary' => DB::table('itineraries')->where('id', $IID)->count(), 'items' => DB::table('itinerary_items')->where('itinerary_id', $IID)->count()];
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
