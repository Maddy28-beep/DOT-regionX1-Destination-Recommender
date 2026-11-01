<?php

namespace App\Console\Commands;

use App\Models\Destination;
use App\Models\DestinationEmbedding;
use Illuminate\Console\Command;

class EmbeddingCoverage extends Command
{
    protected $signature = 'embeddings:coverage {--json : Print public catalogue coverage as JSON}';

    protected $description = 'Check public destination embedding coverage without changing records or planner logic';

    public function handle(): int
    {
        $places = Destination::publiclyVisible()->orderBy('id')->get(['id', 'slug', 'name']);
        $vectors = DestinationEmbedding::whereIn('destination_id', $places->pluck('id'))->get()->keyBy('destination_id');
        $missing = [];
        $invalid = [];
        $dimensions = [];
        foreach ($places as $place) {
            $embedding = $vectors->get($place->id);
            if (! $embedding) {
                $missing[] = $place->slug;

                continue;
            }
            $vector = $embedding->vector;
            if (! is_array($vector) || $vector === [] || count($vector) !== (int) $embedding->dimensions
                || collect($vector)->contains(fn ($n) => ! is_numeric($n) || ! is_finite((float) $n))
                || array_sum(array_map(fn ($n) => (float) $n ** 2, $vector)) <= 0) {
                $invalid[] = $place->slug;

                continue;
            }
            $dimensions[(int) $embedding->dimensions] = true;
        }
        $result = [
            'public_destinations' => $places->count(),
            'covered' => $places->count() - count($missing) - count($invalid),
            'missing_slugs' => $missing,
            'invalid_slugs' => $invalid,
            'dimensions' => array_keys($dimensions),
            'complete' => $missing === [] && $invalid === [] && count($dimensions) <= 1,
        ];
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line("Covered {$result['covered']} of {$result['public_destinations']} public destinations.");
            foreach ($missing as $slug) {
                $this->warn('Missing: '.$slug);
            }
            foreach ($invalid as $slug) {
                $this->warn('Invalid: '.$slug);
            }
            if (count($dimensions) > 1) {
                $this->warn('Mixed vector dimensions; refresh with one compatible model.');
            }
        }

        return $result['complete'] ? self::SUCCESS : self::FAILURE;
    }
}
