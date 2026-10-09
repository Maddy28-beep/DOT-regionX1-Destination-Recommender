<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One pretrained-embedding vector per survey interest ("Wildlife", "Relaxation & Wellness", ...), made from
 * a short description of the interest. Places are ranked against what the traveller picked by comparing
 * their vectors with these (InterestEmbeddingService), instead of by a typed keyword list.
 *
 * Like destination_embeddings, the vectors are produced offline (embeddings:build) and shipped in
 * database/data/destination-embeddings.json, so the live server needs no embedding model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interest_embeddings', function (Blueprint $table) {
            $table->id();
            $table->string('interest', 80)->unique();
            $table->string('model', 80);
            $table->unsignedSmallInteger('dimensions');
            // SHA-1 of the description that was embedded: lets embeddings:build skip unchanged interests.
            $table->string('text_hash', 40);
            // Unit-length vector, so cosine similarity is a plain dot product.
            $table->json('vector');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interest_embeddings');
    }
};
