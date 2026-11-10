<?php
/*
 * Regenerates the data behind the paper's Tables 7, 8 and 12 and one swap example and one theme-day example by running the
 * real recommender and itinerary generator on the exact inputs of Table 11 (3 days, Leisure, First-time Visitor, Moderate
 * budget, Beach Resort, Nature & Adventure + Wildlife + Cultural Heritage, Family, arrival 08:00, Francisco Bangoy airport).
 * The distance range is not stated in Table 11, so all three are run and labelled. Inside a rolled-back transaction.
 *
 *   DB_* env for the database to evaluate, then:  START=2026-12-14 php deploy/qa/eval/eval_tables.php
 */
$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Destination;
use App\Models\PreferenceActivity;
use App\Models\TouristPreference;
use App\Services\Embeddings\SimilarDestinationService;
use App\Services\Recommendation\ContentBasedRecommendationService;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Support\SampleData;
use Illuminate\Support\Facades\DB;

$start = getenv('START') ?: '2026-12-14';
$sample = app(SampleData::class);
$label = fn (?Destination $d) => $d ? ($d->id.' '.preg_replace('/ \[SAMPLE\]$/', '', $d->name).($sample->isSample($d) ? ' [sample]' : ' [official]')) : '?';
echo 'Run: '.now()->toDateTimeString().' UTC | start date '.$start.' | destinations '.Destination::count()."\n";

DB::beginTransaction();
try {
    foreach (['near' => 'Close Proximity', 'moderate' => 'Commutable', 'far' => 'Remote'] as $dist => $name) {
        $p = TouristPreference::create([
            'travel_days' => 3, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Beach Resort', 'distance_pref' => $dist, 'start_date' => $start,
            'arrival_time' => '08:00', 'origin_lat' => 7.1255, 'origin_lng' => 125.6467, 'origin_label' => 'Francisco Bangoy International Airport',
            'variation' => 0,
        ]);
        foreach (['Nature & Adventure', 'Wildlife', 'Cultural Heritage'] as $a) {
            PreferenceActivity::create(['preference_id' => $p->id, 'activity' => $a]);
        }
        $p->load('activities', 'amenities');

        $rows = app(ContentBasedRecommendationService::class)->rank($p);
        echo "\n==== RANGE $name ($dist): TABLE 7 / TABLE 8 (top 5 of ".$rows->count()." candidates) ====\n";
        printf("  %-52s %4s %4s %4s %4s %4s | %5s %s\n", 'Destination', 'PM', 'RS', 'PS', 'DS', 'AS', 'DRS', 'Rank');
        foreach ($rows->take(5)->values() as $i => $r) {
            printf("  %-52s %4.2f %4.2f %4.2f %4.2f %4.2f | %5.2f %d\n", mb_substr($label($r['destination']), 0, 52), $r['pm'], $r['rs'], $r['ps'], $r['ds'], $r['as'], $r['drs'], $i + 1);
        }

        $it = app(ItineraryGenerationService::class)->generate($p);
        echo "\n  -- TABLE 12 ($name): generated itinerary --\n";
        foreach ($it->items()->orderBy('day_number')->orderBy('sort_order')->get() as $item) {
            $when = trim(($item->starts_at ?? '').($item->ends_at ? ' - '.$item->ends_at : ''));
            $travel = $item->distance_km !== null ? sprintf('%.1f km, %s-%s min', $item->distance_km, $item->travel_min_minutes, $item->travel_max_minutes) : '';
            printf("  Day %d | %-13s | %-9s | %s%s\n", $item->day_number, $when, $item->kind, $item->title, $travel !== '' ? ' | '.$travel : '');
        }

        if ($dist === 'moderate') {
            $stops = $it->items()->where('kind', 'activity')->whereNotNull('destination_id')->pluck('destination_id')->unique()->values();
            $orig = Destination::find($stops->first());
            echo "\n  -- SWAP EXAMPLE: stop ".$label($orig)." --\n";
            foreach (app(SimilarDestinationService::class)->alternatives($orig, $it, 3) as $alt) {
                printf("     offered: %s | similarity %.0f%% | %.1f km away\n", $label($alt['destination']), $alt['similarity'] * 100, $alt['distance_km']);
            }
            echo "\n  -- THEME-DAY EXAMPLE ($name) --\n";
            $themes = $it->day_themes ?? null;
            echo '     summary: '.json_encode($themes['summary'] ?? null, JSON_UNESCAPED_UNICODE)."\n";
            echo '     days: '.json_encode($themes['days'] ?? [], JSON_UNESCAPED_UNICODE)."\n";
        }
    }
} finally {
    DB::rollBack();
}
