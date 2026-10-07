<?php

namespace App\Console\Commands;

use App\Services\Embeddings\DestinationEmbeddingService;
use Illuminate\Console\Command;

/**
 * Run on a machine that has Ollama (your PC), then commit the JSON file and deploy.
 * The live server only needs `embeddings:import`.
 */
class BuildDestinationEmbeddings extends Command
{
    protected $signature = 'embeddings:build
        {--force : Re-embed every destination, not just the ones whose text changed}
        {--no-export : Do not rewrite database/data/destination-embeddings.json}';

    protected $description = 'Embed each destination with the pretrained embedding model (needs Ollama) and export the vectors.';

    public function handle(DestinationEmbeddingService $service): int
    {
        if (! $service->isConfigured()) {
            $this->error('EMBEDDINGS_URL is not set.');

            return self::FAILURE;
        }

        $this->info('Embedding with '.$service->model().' ...');

        try {
            $result = $service->build((bool) $this->option('force'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $this->line('Is Ollama running, and did you run `ollama pull '.$service->model().'`?');

            return self::FAILURE;
        }

        $this->line("Embedded {$result['embedded']}, unchanged {$result['unchanged']}, total {$result['total']}.");

        if (! $this->option('no-export')) {
            $count = $service->export();
            $this->info("Exported {$count} vectors to ".DestinationEmbeddingService::EXPORT_PATH);
        }

        return self::SUCCESS;
    }
}
