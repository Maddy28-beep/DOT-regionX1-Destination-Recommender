<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the stay planner decided about the whole trip, beyond what the overnight rows already say.
 *
 * The stay for each night and every change of stay are already stored on the itinerary's `overnight` rows
 * (accommodation_id), so none of that is copied here. This holds what has no other home: the notices (destinations
 * that were planned but could not be placed, and why; nights with no accredited stay) and the record of how the
 * stays were settled. It is versioned (`version`) so a later shape can be read next to this one.
 *
 * Nullable on purpose: itineraries made before this column existed stay null and render exactly as they did.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('itineraries', 'stay_plan')) {
            Schema::table('itineraries', function (Blueprint $table) {
                $table->json('stay_plan')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('itineraries', 'stay_plan')) {
            Schema::table('itineraries', function (Blueprint $table) {
                $table->dropColumn('stay_plan');
            });
        }
    }
};

