<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOT asked for full/complete packages a tourist can follow directly instead
 * of building their own itinerary. A package's own inclusions list already
 * says what's covered; this adds what a self-generated itinerary already
 * has -- a real day-by-day breakdown -- so a tour operator's package reads
 * as a ready-to-follow schedule rather than just an ad with a checklist.
 *
 * Self-service like every other listing detail a tour operator manages
 * (price, description, photos, promos): no separate DOT approval step,
 * consistent with how the establishment portal already works.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_itinerary_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('day_number');
            $table->string('title', 150);
            $table->string('description', 500)->nullable();

            $table->unique(['package_id', 'day_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_itinerary_days');
    }
};
