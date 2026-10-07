<?php

namespace App\Console\Commands;

use App\Models\Destination;
use App\Models\DestinationEmbedding;
use App\Services\Embeddings\DestinationEmbeddingService;
use Illuminate\Console\Command;

/**
 * Offline quality check for the similar-place suggestions, for the evaluation
 * section of the manuscript. It needs no model: it only reads stored vectors.
 *
 * Measure: for every destination, does its single most similar place share at
 * least one human-assigned category tag with it? That is compared with the
 * rate you would get by picking a random other destination, so the number
 * shows what the embedding model adds over chance.
 */
class EvaluateDestinationEmbeddings extends Command
{
    protected $signature = 'embeddings:evaluate {--top=3 : Neighbours to list per destination} {--quiet-list : Print only the summary}';

    protected $description = 'Report how well embedding similarity agrees with the destinations\' category tags.';

    public function handle(): int
    {
        $embeddings = DestinationEmbedding::with('destination.tags')->get()->filter(fn ($e) => $e->destination);

        if ($embeddings->count() < 3) {
            $this->warn('Need at least 3 stored vectors. Run `php artisan embeddings:build` or `embeddings:import` first.');

            return self::FAILURE;
        }

        $tags = fn (Destination $d) => $d->tags->where('kind', 'category')->pluck('value')->map(fn ($v) => mb_strtolower((string) $v))->all();
        $top = max(1, (int) $this->option('top'));

        $rows = [];
        $topOneShares = 0;
        $topOneSim = [];
        $evaluable = 0;
        $randomHits = 0;
        $randomPairs = 0;

        foreach ($embeddings as $a) {
            $ranked = $embeddings
                ->where('destination_id', '!=', $a->destination_id)
                ->map(fn ($b) => ['e' => $b, 'sim' => DestinationEmbeddingService::similarity($a->vector, $b->vector)])
                ->sortByDesc('sim')
                ->values();

            $aTags = $tags($a->destination);
            $first = $ranked->first();
            $shares = $aTags !== [] && $tags($first['e']->destination) !== []
                && array_intersect($aTags, $tags($first['e']->destination)) !== [];

            if ($aTags !== [] && $tags($first['e']->destination) !== []) {
                $evaluable++;
                $topOneShares += $shares ? 1 : 0;
            }
            $topOneSim[] = $first['sim'];

            foreach ($ranked as $other) {
                $oTags = $tags($other['e']->destination);
                if ($aTags !== [] && $oTags !== []) {
                    $randomPairs++;
                    $randomHits += array_intersect($aTags, $oTags) !== [] ? 1 : 0;
                }
            }

            if (! $this->option('quiet-list')) {
                $rows[] = [
                    $a->destination->name,
                    $ranked->take($top)->map(fn ($r) => sprintf('%s (%d%%)', $r['e']->destination->name, round($r['sim'] * 100)))->implode('; '),
                ];
            }
        }

        if ($rows !== []) {
            $this->table(['Destination', 'Most similar places (similarity)'], $rows);
        }

        $agree = $evaluable ? $topOneShares / $evaluable : 0;
        $chance = $randomPairs ? $randomHits / $randomPairs : 0;

        $this->line('');
        $this->info('Embedding model: '.$embeddings->first()->model.'  ('.$embeddings->first()->dimensions.' dimensions)');
        $this->line('Destinations with a vector: '.$embeddings->count());
        $this->line(sprintf('Mean similarity of each place to its closest match: %.1f%%', array_sum($topOneSim) / count($topOneSim) * 100));
        $this->line(sprintf('Closest match shares a category tag: %d of %d (%.1f%%)', $topOneShares, $evaluable, $agree * 100));
        $this->line(sprintf('A random other place would share one: %.1f%%   -> the model is %.1fx better than chance', $chance * 100, $chance > 0 ? $agree / $chance : 0));

        return self::SUCCESS;
    }
}
