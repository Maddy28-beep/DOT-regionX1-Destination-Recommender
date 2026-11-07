<?php
/*
 * Section 2.3.9 re-run, part 1 (READ-ONLY, writes nothing): closest-match tag test (with and without tags) and the
 * interest top/bottom ranking, on two fixtures.
 *
 *   FIXTURE "official": every destination that is NOT sample data (SampleData::isSample() false), by id, listed in the output.
 *   FIXTURE "plannable": every destination that is publicly visible (accredited, not archived), not role "excluded" and not
 *                        closed or temporarily closed, sample places included. This is the pool a trip can draw from today.
 *
 * Run from the project root with the .env (or DB_* variables) of the database to evaluate:
 *   php deploy/qa/eval/eval_embeddings.php                  # stored vectors only
 *   WITH_OLLAMA=1 php deploy/qa/eval/eval_embeddings.php    # also embeds the "without tags" text (needs `ollama serve`)
 */
$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Destination;
use App\Models\DestinationEmbedding;
use App\Models\InterestEmbedding;
use App\Services\Embeddings\DestinationEmbeddingService;
use App\Support\EmbeddingText;
use App\Support\SampleData;

$svc = $app->make(DestinationEmbeddingService::class);
$sampleData = app(SampleData::class);
$sim = fn (array $a, array $b) => DestinationEmbeddingService::similarity($a, $b);
$cat = fn (Destination $d) => $d->tags->where('kind', 'category')->pluck('value')->map(fn ($v) => mb_strtolower((string) $v))->all();

$vec = DestinationEmbedding::pluck('vector', 'destination_id')->all();
$all = Destination::with('tags')->get()->filter(fn ($d) => isset($vec[$d->id]))->values();

$fixtures = [
    'official' => $all->filter(fn ($d) => ! $sampleData->isSample($d))->values(),
    'plannable' => $all->filter(fn ($d) => $d->is_accredited && $d->archived_at === null && $d->itinerary_role !== 'excluded'
        && ! in_array($d->operating_status, ['closed', 'temporarily_closed'], true))->values(),
];

echo 'Snapshot: '.now()->toDateTimeString().' | model '.$svc->model().' | destinations '.Destination::count().' | with a stored vector '.count($vec)."\n";
foreach ($fixtures as $name => $set) {
    echo "\nFIXTURE $name: ".$set->count().' places, ids '.$set->pluck('id')->implode(',')."\n";
    if ($name === 'official') {
        echo '  '.$set->map(fn ($d) => $d->id.' '.$d->name)->implode(' | ')."\n";
    }
}

/** Closest-match test over $set with the given id=>vector map. */
$tagTest = function (string $label, $set, array $vectors) use ($sim, $cat) {
    $hits = $eval = $rh = $rp = 0;
    $sims = [];
    foreach ($set as $a) {
        $best = null;
        $ta = $cat($a);
        foreach ($set as $b) {
            if ($a->id === $b->id) {
                continue;
            }
            $s = $sim($vectors[$a->id], $vectors[$b->id]);
            if ($best === null || $s > $best['s']) {
                $best = ['s' => $s, 'b' => $b];
            }
            $tb = $cat($b);
            if ($ta !== [] && $tb !== []) {
                $rp++;
                $rh += array_intersect($ta, $tb) !== [] ? 1 : 0;
            }
        }
        if ($best === null) {
            continue;
        }
        $sims[] = $best['s'];
        $tb = $cat($best['b']);
        if ($ta !== [] && $tb !== []) {
            $eval++;
            $hits += array_intersect($ta, $tb) !== [] ? 1 : 0;
        }
    }
    $agree = $eval ? $hits / $eval : 0;
    $chance = $rp ? $rh / $rp : 0;
    printf("  %-44s n=%-3d evaluable=%-3d closest shares a tag: %3d (%5.1f%%) | random other %5.1f%% | %.2fx | mean closest similarity %.1f%%\n",
        $label, $set->count(), $eval, $hits, $agree * 100, $chance * 100, $chance > 0 ? $agree / $chance : 0, $sims ? array_sum($sims) / count($sims) * 100 : 0);
};

echo "\n== TAG TEST (before freeze) ==\n";
foreach ($fixtures as $name => $set) {
    $tagTest("$name, stored vectors", $set, $vec);
}

if (getenv('WITH_OLLAMA')) {
    $withText = fn (Destination $d) => $svc->textFor($d);
    $noTagText = fn (Destination $d) => trim(implode('. ', array_filter([
        EmbeddingText::meaningOnly($d->name), $d->type, EmbeddingText::meaningOnly(trim((string) $d->description)),
    ])));
    foreach ($fixtures as $name => $set) {
        foreach (['with tags (fresh embed, current text)' => $withText, 'WITHOUT tags (fresh embed)' => $noTagText] as $label => $textFn) {
            $texts = $set->map($textFn)->all();
            $vectors = [];
            foreach (array_chunk(array_keys($texts), 32) as $chunk) {
                $out = $svc->embed(array_map(fn ($k) => $texts[$k], $chunk));
                foreach ($chunk as $i => $k) {
                    $vectors[$set[$k]->id] = $out[$i];
                }
            }
            $tagTest("$name, $label", $set, $vectors);
        }
    }
} else {
    echo "  (the 'without tags' rows need WITH_OLLAMA=1)\n";
}

echo "\n== INTEREST RANKING (before freeze): top 3 and bottom 3 places per interest, by similarity to the interest vector ==\n";
$interests = InterestEmbedding::all();
foreach ($fixtures as $name => $set) {
    echo "\nFIXTURE $name\n";
    foreach ($interests as $i) {
        $ranked = $set->map(fn ($d) => ['d' => $d, 's' => $sim($i->vector, $vec[$d->id])])->sortByDesc('s')->values();
        $fmt = fn ($rows) => $rows->map(fn ($r) => $r['d']->id.' '.mb_substr(preg_replace('/ \[SAMPLE\]$/', '', $r['d']->name), 0, 34).sprintf(' (%.0f%%)', $r['s'] * 100))->implode('; ');
        printf("  %-22s TOP: %s\n  %-22s BOTTOM: %s\n", $i->interest, $fmt($ranked->take(3)), '', $fmt($ranked->slice(-3)->reverse()));
    }
}
