<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itineraries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tourist_id')->constrained('tourists')->cascadeOnDelete();
            $table->foreignId('preference_id')->constrained('tourist_preferences')->cascadeOnDelete();
            $table->smallInteger('total_days');
            $table->decimal('est_budget_total', 12, 2)->nullable();
            $table->smallInteger('est_party_size')->nullable();
            $table->timestamp('generated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itineraries');
    }
};
