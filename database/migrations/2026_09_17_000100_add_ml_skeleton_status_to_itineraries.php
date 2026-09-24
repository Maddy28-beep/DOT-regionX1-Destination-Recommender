<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the one fact about the pretrained ML skeleton step
 * (ItinerarySkeletonMlService::proposeSkeleton, manuscript Sec. 2.3.4) that
 * generate() already computes but, until now, discarded once the request
 * finished: whether the model's day-grouping was actually used, or whether
 * generation fell back to the Haversine/Nearest-Neighbor order on its own.
 *
 * Nullable, not boolean-with-default: a package itinerary never calls
 * proposeSkeleton() at all (PackageController builds it from the provider's
 * published days), and an itinerary generated before this column existed has
 * no recorded answer either. Both of those are genuinely "unknown", not
 * "fallback was used" -- collapsing them into false would let the itinerary
 * page assert a specific outcome it never actually observed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->boolean('ml_skeleton_applied')->nullable()->after('range_widened');
        });
    }

    public function down(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->dropColumn('ml_skeleton_applied');
        });
    }
};
