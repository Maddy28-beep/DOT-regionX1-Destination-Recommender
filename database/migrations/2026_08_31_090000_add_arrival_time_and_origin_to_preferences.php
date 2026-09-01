<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two inputs the itinerary generator needs but never had: when the traveller
 * lands, and where they are starting from.
 *
 * Arrival time: the plan used to give every trip a full Day 1, so someone
 * landing at 6pm was handed a morning stop they could not make. Storing the
 * time lets the generator drop the slots that have already passed.
 *
 * Origin: distance and the nearest-neighbour sequencing were always measured
 * from a hardcoded Davao City centre, which is wrong for anyone starting
 * anywhere else. A live location was already read on the "regenerate" button
 * but thrown away afterwards, so the first plan and every later one disagreed.
 * Persisting it on the preference makes one baseline for the whole trip.
 *
 * On precision: coordinates are stored at 3 decimal places (~110 m). That is
 * far finer than "which of these stops is nearest" needs and deliberately
 * coarser than a person's actual position -- this system holds no accounts and
 * should not start holding precise locations either. See TripPlannerController,
 * which does the rounding before anything is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tourist_preferences', function (Blueprint $table) {
            $table->time('arrival_time')->nullable()->after('start_date');
            $table->decimal('origin_lat', 8, 3)->nullable()->after('arrival_time');
            $table->decimal('origin_lng', 8, 3)->nullable()->after('origin_lat');
            $table->string('origin_label', 60)->nullable()->after('origin_lng');
        });
    }

    public function down(): void
    {
        Schema::table('tourist_preferences', function (Blueprint $table) {
            $table->dropColumn(['arrival_time', 'origin_lat', 'origin_lng', 'origin_label']);
        });
    }
};
