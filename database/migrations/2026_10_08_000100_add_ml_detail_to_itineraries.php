<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ml_skeleton_applied says WHETHER the pre-trained model grouped an itinerary's
 * days. These two say how that went, so the admin overview can report how often
 * the model's answer was usable as it came and how long it takes:
 *
 *   ml_skeleton_repaired  true when the answer repeated or dropped an id and the
 *                         deterministic repair had to fix it; null when the
 *                         model was not used
 *   ml_skeleton_seconds   round trip to the model, null when no request was sent
 *
 * Both nullable for the same reason as ml_skeleton_applied: an itinerary from
 * before this column, or a package itinerary, simply has no answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->boolean('ml_skeleton_repaired')->nullable()->after('ml_skeleton_applied');
            $table->decimal('ml_skeleton_seconds', 6, 2)->nullable()->after('ml_skeleton_repaired');
        });
    }

    public function down(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->dropColumn(['ml_skeleton_repaired', 'ml_skeleton_seconds']);
        });
    }
};
