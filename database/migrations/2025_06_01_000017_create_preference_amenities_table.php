<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preference_amenities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preference_id')->constrained('tourist_preferences')->cascadeOnDelete();
            $table->string('amenity', 100);

            $table->unique(['preference_id', 'amenity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preference_amenities');
    }
};
