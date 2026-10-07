<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One pretrained-embedding vector per destination, used to rank "similar
 * place" swap suggestions by meaning. The vector is produced offline
 * (embeddings:build) and shipped as database/data/destination-embeddings.json,
 * so the live server never needs the embedding model itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destination_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('model', 80);
            $table->unsignedSmallInteger('dimensions');
            // SHA-1 of the text that was embedded: lets embeddings:build skip unchanged places.
            $table->string('text_hash', 40);
            // Unit-length vector, so cosine similarity is a plain dot product.
            $table->json('vector');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_embeddings');
    }
};
