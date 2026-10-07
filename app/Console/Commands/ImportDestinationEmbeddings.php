<?php

namespace App\Console\Commands;

use App\Services\Embeddings\DestinationEmbeddingService;
use Illuminate\Console\Command;

/** Loads the shipped vectors into the database. Needs no Ollama, so it is the one the live server runs. */
class ImportDestinationEmbeddings extends Command
{
    protected $signature = 'embeddings:import';

    protected $description = 'Load database/data/destination-embeddings.json into the database (no model needed).';

    public function handle(DestinationEmbeddingService $service): int
    {
        $count = $service->import();

        if ($count === 0) {
            $this->warn('No vectors imported. Run `php artisan embeddings:build` on a machine with Ollama first.');

            return self::SUCCESS;
        }

        $this->info("Imported {$count} destination vectors.");

        return self::SUCCESS;
    }
}
