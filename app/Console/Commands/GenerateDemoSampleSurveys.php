<?php

namespace App\Console\Commands;

use App\Http\Controllers\ExitSurveyController;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\ExitSurveyActivity;
use App\Models\ExitSurveyVisit;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Services\Embeddings\InterestEmbeddingService;
use App\Support\SampleData;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Simulated exit surveys that include the sample destinations, so the sample places can take part in association
 * rules (they have no survey history of their own).
 *
 * Every row is data_source = 'demo' and carries a batch name, so it can be told apart from every other demo row and
 * removed on its own. This is separate from DemoExitSurveySeeder, which deletes demo rows that have no batch: this
 * command never touches those, nor real surveys, nor another batch.
 *
 * What the simulation assumes (it is not observed behaviour, and must be described that way):
 *   - each respondent has one or two interests; places are chosen by how well they fit those interests, using the
 *     same embedding vectors the recommender uses, with a share of mixed and random picks as noise;
 *   - distance is only a small nudge, so rules reflect interest and not just nearness. The dry run measures this.
 *
 * Without --apply nothing is written. --revert deletes the batch's rows (and only those).
 */
class GenerateDemoSampleSurveys extends Command
{
    protected $signature = 'surveys:generate-demo-samples
        {--count=150 : How many surveys to simulate}
        {--batch=demo-samples-2026-11 : The batch name written on every row}
        {--seed=2026 : Same seed, same surveys}
        {--apply : Write the surveys (default is a report only)}
        {--revert : Delete the surveys of --batch instead}';

    protected $description = 'Simulate demo exit surveys that include the sample destinations (dry run by default).';

    /** Interest => weight among simulated respondents. */
    private const PERSONAS = [
        'Nature & Adventure' => 25, 'Beach & Island' => 18, 'Cultural Heritage' => 18, 'Wildlife' => 12,
        'Hiking & Trekking' => 12, 'Relaxation & Wellness' => 8, 'Food Tourism' => 4, 'Shopping & Souvenirs' => 3,
    ];

    /** How many destinations one survey lists. */
    private const BASKET_SIZES = [2 => 30, 3 => 35, 4 => 25, 5 => 10];

    /** Of the destination picks: from the main interest, from the second interest, anywhere (noise). */
    private const PICK_MIX = ['main' => 62, 'second' => 20, 'noise' => 18];

    /** A pick within this distance of a place already in the basket gets a small boost. Deliberately mild. */
    private const NEAR_KM = 25;

    private const NEAR_BOOST = 1.25;

    private const MIN_SUPPORT_COUNT = 2;

    private const MIN_CONFIDENCE = 0.15;

    /** @var Collection<int, Destination> */
    private Collection $destinations;

    /** @var array<string, array<int, float>> interest => destination id => fit */
    private array $fit = [];

    /** @var array<string, int> "kind:id" => times picked so far (for gentle coverage balancing) */
    private array $picked = [];

    public function handle(): int
    {
        $batch = (string) $this->option('batch');

        if ($this->option('revert')) {
            return $this->revert($batch);
        }

        $count = max(1, (int) $this->option('count'));

        if (ExitSurvey::withoutGlobalScopes()->where('batch', $batch)->exists()) {
            $this->error("Batch '{$batch}' already exists. Revert it first or choose another name.");

            return self::FAILURE;
        }

        if (Destination::publiclyVisible()->count() < 2) {
            $this->error('Needs at least two visible destinations to pair.');

            return self::FAILURE;
        }

        mt_srand((int) $this->option('seed'));
        $surveys = $this->simulate($count);
        $this->report($surveys, $batch);

        if (! $this->option('apply')) {
            $this->info("Dry run: nothing written. Add --apply to create these {$count} surveys under batch '{$batch}'.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($surveys, $batch) {
            foreach ($surveys as $s) {
                $survey = ExitSurvey::create($s['fields'] + ['data_source' => 'demo', 'batch' => $batch]);

                foreach ($s['places'] as $place) {
                    [$kind, $id] = explode(':', $place);
                    ExitSurveyVisit::create(['exit_survey_id' => $survey->id, 'listing_kind' => $kind, 'listing_id' => (int) $id]);
                }
                foreach ($s['activities'] as $activity) {
                    ExitSurveyActivity::create(['exit_survey_id' => $survey->id, 'activity' => $activity]);
                }
            }
        });

        $this->info('Created '.count($surveys)." surveys. To undo: php artisan surveys:generate-demo-samples --revert --batch={$batch} --apply");

        return self::SUCCESS;
    }

    private function revert(string $batch): int
    {
        $rows = ExitSurvey::withoutGlobalScopes()->where('batch', $batch)->where('data_source', 'demo');
        $this->line($rows->count()." demo surveys in batch '{$batch}'.");

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing deleted. Add --apply to delete them.');

            return self::SUCCESS;
        }

        $deleted = $rows->delete();
        $this->info("Deleted {$deleted} surveys.");

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------------------------------

    /** @return list<array{fields: array, places: list<string>, activities: list<string>, interests: list<string>}> */
    private function simulate(int $count): array
    {
        // A command object can run more than once in a process (tests, scheduled runs): start from a clean slate.
        $this->picked = [];
        $this->fit = [];

        $this->destinations = Destination::publiclyVisible()
            ->where(fn ($q) => $q->whereNull('itinerary_role')->orWhere('itinerary_role', 'sightseeing'))
            ->with('region', 'tags')->get();

        foreach (array_keys(self::PERSONAS) as $interest) {
            $this->fit[$interest] = app(InterestEmbeddingService::class)->fitFor([$interest], $this->destinations)?->all() ?? [];
        }

        $stays = Accommodation::publiclyVisible()->with('region')->get();
        $restaurants = Restaurant::publiclyVisible()->with('region')->get();
        $shops = SouvenirCenter::publiclyVisible()->get();
        $surveys = [];

        for ($i = 0; $i < $count; $i++) {
            $main = $this->weighted(self::PERSONAS);
            $second = mt_rand(1, 100) <= 35 ? $this->weighted(array_diff_key(self::PERSONAS, [$main => 1])) : null;
            $size = (int) $this->weighted(self::BASKET_SIZES);
            $chosen = [];

            for ($n = 0; $n < $size; $n++) {
                $mode = $this->weighted(self::PICK_MIX);
                $interest = $mode === 'second' && $second ? $second : $main;
                $pick = $this->pickDestination($mode === 'noise' ? null : $interest, $chosen);

                if ($pick) {
                    $chosen[] = $pick;
                    $this->picked['destination:'.$pick->id] = ($this->picked['destination:'.$pick->id] ?? 0) + 1;
                }
            }

            if ($chosen === []) {
                continue;
            }

            $places = collect($chosen)->map(fn ($d) => 'destination:'.$d->id)->unique()->values()->all();
            $regions = collect($chosen)->map(fn ($d) => $d->region?->name)->filter()->unique()->values();

            if (mt_rand(1, 100) <= 55 && $stays->isNotEmpty()) {
                $beachy = in_array($main, ['Beach & Island', 'Relaxation & Wellness'], true);
                $pool = $stays->filter(fn ($a) => $regions->contains($a->region?->name) || mt_rand(1, 100) <= 30);
                $pool = $pool->isEmpty() ? $stays : $pool;
                $stay = $this->pickWeighted($pool, fn ($a) => in_array($a->type, ['Resort', 'Beach Resort'], true) === $beachy ? 3.0 : 1.0);
                $places[] = 'accommodation:'.$stay->id;
            }
            if (mt_rand(1, 100) <= 45 && $restaurants->isNotEmpty()) {
                $pool = mt_rand(1, 100) <= 55 ? $restaurants->filter(fn ($r) => $regions->contains($r->region?->name)) : $restaurants;
                $pool = $pool->isEmpty() ? $restaurants : $pool;
                $places[] = 'restaurant:'.$pool->values()[mt_rand(0, $pool->count() - 1)]->id;
            }
            if (mt_rand(1, 100) <= 20 && $shops->isNotEmpty()) {
                $places[] = 'souvenir_center:'.$shops->values()[mt_rand(0, $shops->count() - 1)]->id;
            }

            $surveys[] = $this->fields($main, $second, array_values(array_unique($places)), count($chosen));
        }

        return $surveys;
    }

    /** One destination, by fit to $interest (or any, when null), not already in the basket. */
    private function pickDestination(?string $interest, array $chosen): ?Destination
    {
        $taken = collect($chosen)->pluck('id');
        $pool = $this->destinations->reject(fn ($d) => $taken->contains($d->id));

        if ($pool->isEmpty()) {
            return null;
        }

        return $this->pickWeighted($pool, function (Destination $d) use ($interest, $chosen) {
            $base = $interest === null ? 1.0 : (($this->fit[$interest][$d->id] ?? 0.3) ** 2) + 0.02;
            // Gentle coverage balancing: a place already used a lot is a little less likely, so every sample appears.
            $base /= 1 + 0.12 * ($this->picked['destination:'.$d->id] ?? 0);

            foreach ($chosen as $c) {
                if ($this->km($c, $d) !== null && $this->km($c, $d) <= self::NEAR_KM) {
                    return $base * self::NEAR_BOOST;
                }
            }

            return $base;
        });
    }

    private function pickWeighted(Collection $pool, callable $weight)
    {
        $weights = $pool->map($weight)->values();
        $items = $pool->values();
        $roll = mt_rand() / mt_getrandmax() * $weights->sum();

        foreach ($weights as $i => $w) {
            $roll -= $w;
            if ($roll <= 0) {
                return $items[$i];
            }
        }

        return $items->last();
    }

    private function km($a, $b): ?float
    {
        if ($a->latitude === null || $b->latitude === null) {
            return null;
        }

        $dLat = deg2rad((float) $b->latitude - (float) $a->latitude);
        $dLng = deg2rad((float) $b->longitude - (float) $a->longitude);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad((float) $a->latitude)) * cos(deg2rad((float) $b->latitude)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * asin(min(1.0, sqrt($h)));
    }

    /** @param  list<string>  $places */
    private function fields(string $main, ?string $second, array $places, int $destinationCount): array
    {
        $rating = (int) $this->weighted([5 => 35, 4 => 40, 3 => 18, 2 => 5, 1 => 2]);
        $residency = $this->weighted(['Domestic Tourist' => 60, 'Local Resident' => 30, 'Foreign Tourist' => 10]);
        $days = (int) $this->weighted([1 => 30, 2 => 30, 3 => 22, 4 => 12, 5 => 6]);
        $near = fn (int $v) => max(1, min(5, $v + mt_rand(-1, 1)));

        return [
            'fields' => [
                'submitted_at' => now()->subDays(mt_rand(1, 45))->subMinutes(mt_rand(0, 1439)),
                'residency_type' => $residency,
                'visitor_type' => $this->weighted(['First-time Visitor' => 55, 'Returning Visitor' => 30, 'Regular / Local' => 15]),
                'origin' => $residency === 'Foreign Tourist'
                    ? $this->weighted(['Tokyo, Japan' => 3, 'Seoul, South Korea' => 3, 'Sydney, Australia' => 2, 'Singapore' => 2])
                    : $this->weighted(['Metro Manila' => 18, 'Cebu City' => 10, 'General Santos' => 9, 'Cagayan de Oro' => 8, 'Zamboanga City' => 5, 'Iloilo City' => 4, 'Davao City (local)' => 14]),
                'travel_purpose' => $this->weighted(['Leisure' => 144, 'Visiting Friends/Family' => 120, 'Medical' => 21, 'Business' => 20, 'Other' => 11]),
                'actual_days_stayed' => $days,
                'estimated_total_spend' => $this->weighted($days <= 1
                    ? ['under_10000' => 70, '10000_20000' => 25, '20000_50000' => 5]
                    : ['under_10000' => 25, '10000_20000' => 40, '20000_50000' => 28, '50000_100000' => 6, 'over_100000' => 1]),
                'overall_rating' => $rating,
                'destination_relevant' => $near($rating), 'itinerary_useful' => $near($rating), 'attractions_quality' => $near($rating),
                'accommodation_rating' => $near($rating), 'transport_rating' => $near($rating - 1),
                'would_recommend' => mt_rand(1, 100) <= ($rating >= 4 ? 92 : 35) ? 'Yes' : 'No',
            ],
            'places' => $places,
            'activities' => array_values(array_intersect(array_filter([$main, $second]), ExitSurveyController::ACTIVITIES)),
            'interests' => array_values(array_filter([$main, $second])),
        ];
    }

    /** @param  array<string|int, int|float>  $weights */
    private function weighted(array $weights): string|int
    {
        $roll = mt_rand() / mt_getrandmax() * array_sum($weights);

        foreach ($weights as $key => $w) {
            $roll -= $w;
            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    // -------------------------------------------------------------------------------------------------

    private function report(array $surveys, string $batch): void
    {
        $sample = app(SampleData::class);
        $visits = collect($surveys)->flatMap(fn ($s) => $s['places']);
        $byKind = $visits->countBy(fn ($p) => explode(':', $p)[0]);

        $this->info("DRY RUN: batch '{$batch}', ".count($surveys).' simulated surveys (data_source = demo)');
        $this->line('visits: '.$visits->count().' ('.$byKind->map(fn ($n, $k) => "{$k} {$n}")->implode(', ').')');
        $this->line('distinct places: '.$visits->unique()->count().'; average places per survey: '.round($visits->count() / max(1, count($surveys)), 1));

        // ---- coverage per destination
        $destVisits = $visits->filter(fn ($p) => str_starts_with($p, 'destination:'))->countBy();
        $rows = $this->destinations->map(fn ($d) => [
            'name' => $d->name, 'n' => $destVisits[('destination:'.$d->id)] ?? 0, 'sample' => $sample->isSample($d),
        ])->sortByDesc('n')->values();
        $this->newLine();
        $this->line('Visits per destination (sample places marked *):');
        $this->table(['place', 'visits', ''], $rows->map(fn ($r) => [$r['name'], $r['n'], $r['sample'] ? '*' : ''])->all());
        $sampleNs = $rows->where('sample', true)->pluck('n');
        $this->line('sample places: min '.$sampleNs->min().', median '.$sampleNs->sort()->values()->get(intdiv($sampleNs->count(), 2)).', max '.$sampleNs->max().'; none with 0 visits: '.($sampleNs->min() > 0 ? 'yes' : 'NO'));

        // ---- does the simulation reflect interest rather than only distance?
        $near = $same = $pairs = 0;
        $byId = $this->destinations->keyBy('id');
        $interestOf = fn ($d, $interests) => collect($interests)->contains(fn ($i) => ($this->fit[$i][$d->id] ?? 0) >= 0.6);
        foreach ($surveys as $s) {
            $ids = collect($s['places'])->filter(fn ($p) => str_starts_with($p, 'destination:'))->map(fn ($p) => (int) explode(':', $p)[1])->values();
            foreach ($ids as $i => $a) {
                foreach ($ids->slice($i + 1) as $b) {
                    $pairs++;
                    $d = $this->km($byId[$a], $byId[$b]);
                    $near += $d !== null && $d <= 25 ? 1 : 0;
                    $same += $interestOf($byId[$a], $s['interests']) && $interestOf($byId[$b], $s['interests']) ? 1 : 0;
                }
            }
        }
        $all = $this->destinations->values();
        $basePairs = $baseNear = 0;
        foreach ($all as $i => $a) {
            foreach ($all->slice($i + 1) as $b) {
                $basePairs++;
                $d = $this->km($a, $b);
                $baseNear += $d !== null && $d <= 25 ? 1 : 0;
            }
        }
        $this->newLine();
        $this->line('Is it interest, not just distance? (destination pairs within one survey)');
        $this->line(sprintf('  pairs within 25 km: %s%% of simulated pairs; %s%% of ALL possible pairs in the catalogue (the random baseline)', round(100 * $near / max(1, $pairs)), round(100 * $baseNear / max(1, $basePairs))));
        // The same measure for pairs picked at random (two destinations at random for each survey's interests).
        $randSame = $randPairs = 0;
        $flat = $this->destinations->values();
        foreach ($surveys as $s) {
            $i = mt_rand(0, $flat->count() - 1);
            $j = ($i + mt_rand(1, $flat->count() - 1)) % $flat->count();
            $randPairs++;
            $randSame += $interestOf($flat[$i], $s['interests']) && $interestOf($flat[$j], $s['interests']) ? 1 : 0;
        }
        $this->line(sprintf('  pairs where both places fit the respondent\'s interest (fit >= 0.6): %s%% of simulated pairs; %s%% for random pairs', round(100 * $same / max(1, $pairs)), round(100 * $randSame / max(1, $randPairs))));

        // ---- the rules, counted exactly as AprioriService::topRules does, over existing surveys + these
        $this->newLine();
        [$before, $beforeTotal] = $this->rules(collect());
        [$after, $afterTotal] = $this->rules(collect($surveys));
        $isSample = fn ($key) => ($m = explode(':', $key)) && $m[0] === 'destination' && ($d = $byId[(int) $m[1]] ?? null) && $sample->isSample($d);

        $this->line(sprintf('Association rules at support count >= %d and confidence >= %s (all sources, as the admin page shows):', self::MIN_SUPPORT_COUNT, self::MIN_CONFIDENCE));
        $this->line(sprintf('  before: %d rules from %d surveys, %d involving a sample place', $before->count(), $beforeTotal, $before->filter(fn ($r) => $isSample($r['a']) || $isSample($r['b']))->count()));
        $this->line(sprintf('  after : %d rules from %d surveys, %d involving a sample place', $after->count(), $afterTotal, $after->filter(fn ($r) => $isSample($r['a']) || $isSample($r['b']))->count()));

        $name = function (string $key) use ($byId) {
            [$k, $id] = explode(':', $key);
            $model = ['destination' => Destination::class, 'accommodation' => Accommodation::class, 'restaurant' => Restaurant::class, 'souvenir_center' => SouvenirCenter::class][$k] ?? null;

            return $k === 'destination' ? ($byId[(int) $id]->name ?? $key) : ($model ? ($model::find($id)?->name ?? $key) : $key);
        };

        $sampleRules = $after->filter(fn ($r) => $isSample($r['a']) || $isSample($r['b']))->values();
        $strong = $after->filter(fn ($r) => $r['n'] >= 4 && str_starts_with($r['a'], 'destination:') && str_starts_with($r['b'], 'destination:') && (str_contains($name($r['a']), '[SAMPLE]') || str_contains($name($r['b']), '[SAMPLE]')))
            ->sort(fn ($x, $y) => [$y['n'], $y['confidence']] <=> [$x['n'], $x['confidence']])->values();
        $this->newLine();
        $this->line('Destination-to-destination rules with co-count >= 4 that involve one of the new [SAMPLE] places (top 15 by co-count): '.$strong->count().' in all');
        $this->table(['if they visit', 'they also visit', 'n', 'support', 'confidence'], $strong->take(15)->map(fn ($r) => [$name($r['a']), $name($r['b']), $r['n'], $r['support'], $r['confidence']])->all());
        $this->line('new [SAMPLE] places that start at least one such rule: '.$strong->pluck('a')->unique()->filter(fn ($k) => str_contains($name($k), '[SAMPLE]'))->count().' of 18');

        $withRules = $this->destinations->filter(fn ($d) => $sample->isSample($d) && $sampleRules->contains(fn ($r) => $r['a'] === 'destination:'.$d->id));
        $this->line('sample places that become the START of at least one rule (so Apriori can credit them in an itinerary): '.$withRules->count().' of '.$this->destinations->filter(fn ($d) => $sample->isSample($d))->count());
    }

    /**
     * Rules over the existing surveys plus the given simulated ones. Mirrors AprioriService::topRules: a transaction
     * is a survey's set of visited places; confidence = co-count / transactions containing A; support = co-count /
     * all transactions (including those that visited nothing).
     *
     * @return array{0: Collection<int, array>, 1: int}
     */
    private function rules(Collection $simulated): array
    {
        $transactions = ExitSurveyVisit::query()->select('exit_survey_id', 'listing_kind', 'listing_id')->get()
            ->groupBy('exit_survey_id')->map(fn ($v) => $v->map(fn ($x) => $x->listing_kind.':'.$x->listing_id)->unique()->values()->all())->values()
            ->concat($simulated->map(fn ($s) => $s['places']));
        $total = ExitSurvey::query()->count() + $simulated->count();

        $a = $pairs = [];
        foreach ($transactions as $items) {
            foreach ($items as $x) {
                $a[$x] = ($a[$x] ?? 0) + 1;
            }
            foreach ($items as $x) {
                foreach ($items as $y) {
                    if ($x !== $y) {
                        $pairs[$x.'|'.$y] = ($pairs[$x.'|'.$y] ?? 0) + 1;
                    }
                }
            }
        }

        $rules = collect($pairs)->map(function ($n, $key) use ($a, $total) {
            [$x, $y] = explode('|', $key);

            return ['a' => $x, 'b' => $y, 'n' => $n, 'support' => round($n / $total, 4), 'confidence' => round($n / $a[$x], 4)];
        })->filter(fn ($r) => $r['n'] >= self::MIN_SUPPORT_COUNT && $r['confidence'] >= self::MIN_CONFIDENCE)->values();

        return [$rules, $total];
    }
}
