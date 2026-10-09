<?php

namespace App\Console\Commands;

use App\Services\Embeddings\DestinationEmbeddingService;
use App\Services\Embeddings\InterestEmbeddingService;
use Illuminate\Console\Command;

/**
 * Run on a machine that has Ollama (your PC), then commit the JSON file and deploy.
 * The live server only needs `embeddings:import`.
 */
class BuildDestinationEmbeddings extends Command
{
    protected $signature = 'embeddings:build
        {--force : Re-embed every destination, not just the ones whose text changed}
        {--interests-only : Embed only the 8 survey interests and update only the "interests" part of the file}
        {--no-export : Do not rewrite database/data/destination-embeddings.json}';

    protected $description = 'Embed each destination with the pretrained embedding model (needs Ollama) and export the vectors.';

    public function handle(DestinationEmbeddingService $service, InterestEmbeddingService $interests): int
    {
        if (! $service->isConfigured()) {
            $this->error('EMBEDDINGS_URL is not set.');

            return self::FAILURE;
        }

        $this->info('Embedding with '.$service->model().' ...');

        // Touches nothing about the places, so it is safe from a computer whose own database is not the live one.
        if ($this->option('interests-only')) {
            try {
                $interestResult = $interests->build((bool) $this->option('force'));
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
                $this->line('Is Ollama running, and did you run `ollama pull '.$service->model().'`?');

                return self::FAILURE;
            }

            $this->line("Interests: embedded {$interestResult['embedded']}, unchanged {$interestResult['unchanged']}, total {$interestResult['total']}.");

            if (! $this->option('no-export')) {
                $count = $interests->exportToFile();
                $this->info("Wrote {$count} interest vectors to ".DestinationEmbeddingService::EXPORT_PATH.' (place vectors untouched).');
            }

            return self::SUCCESS;
        }

        try {
            $result = $service->build((bool) $this->option('force'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $this->line('Is Ollama running, and did you run `ollama pull '.$service->model().'`?');

            return self::FAILURE;
        }

        $this->line("Embedded {$result['embedded']}, unchanged {$result['unchanged']}, total {$result['total']}.");

        // The survey interests, so a traveller's picks can be matched to places by meaning.
        try {
            $interestResult = $interests->build((bool) $this->option('force'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Interests: embedded {$interestResult['embedded']}, unchanged {$interestResult['unchanged']}, total {$interestResult['total']}.");

        if (! $this->option('no-export')) {
            $count = $service->export();
            $this->info("Exported {$count} vectors to ".DestinationEmbeddingService::EXPORT_PATH);
        }

        return self::SUCCESS;
    }
}
