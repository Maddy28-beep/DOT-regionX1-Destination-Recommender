<?php
/*
 * Section 2.3.9 re-run, part 2: the stay-choice row. Generates N random trips of 2 to 6 days with a fixed seed, once with the
 * stay chosen by "total distance + 2 x farthest stop" (the shipped rule) and once by "total distance alone" (the same code
 * with STAY_FARTHEST_WEIGHT patched from 2.0 to 0.0 in an in-memory copy of the class), and reports for each how many trips
 * have a sightseeing stop more than 50 km from that night's stay, the mean farthest stop, and the mean distance per stop.
 * Runs inside a rolled-back transaction: nothing is saved.
 *
 *   DB_* env for the database to evaluate, then:  SEED=2026 N=150 php deploy/qa/eval/eval_stay_distance.php
 */
$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\PreferenceActivity;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Support\Facades\DB;

$seed = (int) (getenv('SEED') ?: 2026);
$n = (int) (getenv('N') ?: 150);

// In-memory copy of the generator with the farthest-stop weight at zero ("total distance alone").
$file = (new ReflectionClass(ItineraryGenerationService::class))->getFileName();
$src = file_get_contents($file);
$patched = str_replace(['class ItineraryGenerationService', 'STAY_FARTHEST_WEIGHT = 2.0;'], ['class ItineraryGenerationServiceTotalOnly', 'STAY_FARTHEST_WEIGHT = 0.0;'], $src, $count);
if ($count !== 2 || ! str_contains($src, 'STAY_FARTHEST_WEIGHT = 2.0;')) {
    fwrite(STDERR, "The generator source no longer matches what this script patches; check STAY_FARTHEST_WEIGHT.\n");
    exit(1);
}
$tmp = sys_get_temp_dir().'/ItineraryGenerationServiceTotalOnly.php';
file_put_contents($tmp, $patched);
require $tmp;
$variants = [
    'farthest + total (shipped)' => app(ItineraryGenerationService::class),
    'total distance alone' => app(\App\Services\Recommendation\ItineraryGenerationServiceTotalOnly::class),
];

$hav = function ($a, $b, $c, $d) {
    $dl = deg2rad($c - $a);
    $dn = deg2rad($d - $b);
    $x = sin($dl / 2) ** 2 + cos(deg2rad($a)) * cos(deg2rad($c)) * sin($dn / 2) ** 2;

    return 6371 * 2 * asin(sqrt($x));
};
$interests = ['Beach & Island', 'Nature & Adventure', 'Cultural Heritage', 'Wildlife', 'Food Tourism', 'Shopping & Souvenirs', 'Hiking & Trekking', 'Relaxation & Wellness'];

$stats = [];
DB::beginTransaction();
try {
    foreach ($variants as $label => $generator) {
        mt_srand($seed); // the same trips for both variants
        $s = ['trips' => 0, 'measured' => 0, 'over50' => 0, 'farthest_sum' => 0.0, 'stop_sum' => 0.0, 'stops' => 0];
        for ($i = 0; $i < $n; $i++) {
            $p = TouristPreference::create([
                'travel_days' => mt_rand(2, 6), 'travel_type' => 'Solo', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
                'budget' => ['Budget-Friendly', 'Mid-range', 'Premium'][mt_rand(0, 2)], 'accommodation_pref' => 'Any',
                'distance_pref' => ['near', 'moderate', 'far'][mt_rand(0, 2)], 'variation' => 0,
            ]);
            foreach ((array) array_rand(array_flip($interests), mt_rand(1, 3)) as $a) {
                PreferenceActivity::create(['preference_id' => $p->id, 'activity' => $a]);
            }
            try {
                $it = $generator->generate($p->load('activities', 'amenities'));
            } catch (\Throwable) {
                continue;
            }
            $s['trips']++;
            $items = $it->items()->get();
            $stayByDay = [];
            foreach ($items->where('kind', 'overnight')->whereNotNull('accommodation_id') as $ov) {
                $stayByDay[$ov->day_number] = Accommodation::find($ov->accommodation_id);
            }
            $farthest = null;
            foreach ($items->where('kind', 'activity')->whereNotNull('destination_id') as $stop) {
                $stay = $stayByDay[$stop->day_number] ?? null;
                $d = Destination::find($stop->destination_id);
                if (! $stay || ! $stay->latitude || ! $d || ! $d->latitude) {
                    continue;
                }
                $km = $hav($stay->latitude, $stay->longitude, $d->latitude, $d->longitude);
                $farthest = max($farthest ?? 0, $km);
                $s['stop_sum'] += $km;
                $s['stops']++;
            }
            if ($farthest !== null) {
                $s['measured']++;
                $s['farthest_sum'] += $farthest;
                $s['over50'] += $farthest > 50 ? 1 : 0;
            }
        }
        $stats[$label] = $s;
    }
} finally {
    DB::rollBack();
    @unlink($tmp);
}

printf("seed=%d requested=%d trips per variant (rolled back)\n", $seed, $n);
foreach ($stats as $label => $s) {
    printf("  %-28s generated=%-3d measurable=%-3d trips with a stop >50 km from the stay: %3d (%4.1f%%) | mean farthest stop %.1f km | mean distance per stop %.1f km\n",
        $label, $s['trips'], $s['measured'], $s['over50'], $s['measured'] ? 100 * $s['over50'] / $s['measured'] : 0,
        $s['measured'] ? $s['farthest_sum'] / $s['measured'] : 0, $s['stops'] ? $s['stop_sum'] / $s['stops'] : 0);
}
