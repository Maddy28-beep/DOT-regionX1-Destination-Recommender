<?php

namespace Database\Seeders;

use App\Services\Embeddings\DestinationEmbeddingService;
use Illuminate\Database\Seeder;

/**
 * Loads the shipped pretrained-embedding vectors (database/data/destination-embeddings.json)
 * so a fresh database has similar-place swaps without needing the embedding model.
 * Idempotent; also what `php artisan embeddings:import` does.
 */
class DestinationEmbeddingSeeder extends Seeder
{
    public function run(DestinationEmbeddingService $embeddings): void
    {
        $embeddings->import();
    }
}
