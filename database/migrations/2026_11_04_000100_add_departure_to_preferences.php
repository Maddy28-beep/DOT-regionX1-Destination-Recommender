<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Departure-aware scheduling: when and from where the traveller leaves.
 *
 * All optional. departure_time is what the planner uses (the last travel day is planned so the journey to the
 * departure point still makes it); departure_date is only checked against the arrival date and the number of days.
 * The departure point is chosen by the traveller, never inferred. Coordinates are stored at 3 decimal places like
 * the starting point (see TripPlannerController::applyDeparture).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tourist_preferences', function (Blueprint $table) {
            $table->date('departure_date')->nullable()->after('arrival_time');
            $table->time('departure_time')->nullable()->after('departure_date');
            $table->decimal('departure_lat', 8, 3)->nullable()->after('departure_time');
            $table->decimal('departure_lng', 8, 3)->nullable()->after('departure_lat');
            $table->string('departure_label', 200)->nullable()->after('departure_lng');
        });
    }

    public function down(): void
    {
        Schema::table('tourist_preferences', function (Blueprint $table) {
            $table->dropColumn(['departure_date', 'departure_time', 'departure_lat', 'departure_lng', 'departure_label']);
        });
    }
};
