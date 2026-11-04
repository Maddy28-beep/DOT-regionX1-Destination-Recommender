<?php
// Read-only timing of the planner on production: every scenario runs inside a transaction that is ROLLED BACK,
// so nothing is stored. No HTTP overhead is included (that is measured separately from outside).
require '/var/www/exploredvo/vendor/autoload.php';
$app = require '/var/www/exploredvo/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PreferenceActivity;
use App\Models\PreferenceAmenity;
use App\Models\TouristPreference;
use App\Services\Recommendation\ContentBasedRecommendationService;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Support\Facades\DB;

$gen = app(ItineraryGenerationService::class);
$rec = app(ContentBasedRecommendationService::class);
$start = now('Asia/Manila')->next('Saturday')->startOfDay()->toDateString();

$interestSets = [
    ['Nature & Adventure', 'Cultural Heritage'],
    ['Wildlife', 'Beach & Island', 'Food Tourism'],
    ['Hiking & Trekking', 'Nature & Adventure'],
    ['Shopping & Souvenirs', 'Relaxation & Wellness', 'Cultural Heritage'],
];
$airport = ['lat' => 7.130, 'lng' => 125.645, 'label' => 'Davao International Airport'];

$queries = 0;
$queryMs = 0.0;
$slowest = ['ms' => 0, 'sql' => ''];
DB::listen(function ($q) use (&$queries, &$queryMs, &$slowest) {
    $queries++;
    $queryMs += $q->time;
    if ($q->time > $slowest['ms']) {
        $slowest = ['ms' => round($q->time, 1), 'sql' => substr(preg_replace('/\s+/', ' ', $q->sql), 0, 160)];
    }
});

$rows = [];
foreach (['near', 'moderate', 'far'] as $distance) {
    foreach ($interestSets as $si => $interests) {
        foreach ([2, 3] as $days) {
            $sig = [];
            $times = [];
            $recTimes = [];
            $qCount = [];
            for ($rep = 0; $rep < 2; $rep++) {
                DB::beginTransaction();
                try {
                    $p = TouristPreference::create([
                        'travel_days' => $days, 'travel_type' => 'Couple', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
                        'place_of_origin' => 'Cebu City', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => $distance,
                        'start_date' => $start, 'arrival_time' => '09:00',
                        'origin_lat' => $airport['lat'], 'origin_lng' => $airport['lng'], 'origin_label' => $airport['label'],
                    ]);
                    foreach ($interests as $a) { PreferenceActivity::create(['preference_id' => $p->id, 'activity' => $a]); }
                    foreach (['Restaurant', 'Parking Area'] as $a) { PreferenceAmenity::create(['preference_id' => $p->id, 'amenity' => $a]); }
                    $p->load('activities', 'amenities');

                    $t = hrtime(true);
                    $ranked = $rec->rank($p);
                    $recTimes[] = (hrtime(true) - $t) / 1e6;

                    $queries = 0;
                    $t = hrtime(true);
                    $it = $gen->generate($p);
                    $times[] = (hrtime(true) - $t) / 1e6;
                    $qCount[] = $queries;
                    $sig[] = md5(json_encode($it->items()->orderBy('day_number')->orderBy('sort_order')->get(['day_number', 'kind', 'title', 'starts_at', 'ends_at', 'destination_id'])->toArray()));
                } finally {
                    DB::rollBack();
                }
            }
            $rows[] = ['distance' => $distance, 'set' => $si, 'days' => $days, 'gen_ms' => array_map(fn ($x) => round($x), $times),
                'rank_ms' => array_map(fn ($x) => round($x), $recTimes), 'queries' => $qCount, 'identical' => $sig[0] === $sig[1]];
        }
    }
}

echo json_encode(['rows' => $rows, 'slowest_query' => $slowest, 'stored_rows_after' => [
    'tourist_preferences' => DB::table('tourist_preferences')->count(), 'itineraries' => DB::table('itineraries')->count(),
]], JSON_UNESCAPED_UNICODE), "\n";
