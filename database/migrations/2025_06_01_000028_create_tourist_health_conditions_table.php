<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tourist_health_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('health_profile_id')->constrained('tourist_health_profiles')->cascadeOnDelete();
            $table->string('condition', 20);

            $table->unique(['health_profile_id', 'condition']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tourist_health_conditions');
    }
};
