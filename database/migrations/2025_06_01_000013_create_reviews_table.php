<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->string('listing_kind', 30);
            $table->unsignedBigInteger('listing_id');
            $table->string('author_name', 100)->nullable();
            $table->smallInteger('rating');
            $table->string('comment', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['listing_kind', 'listing_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
