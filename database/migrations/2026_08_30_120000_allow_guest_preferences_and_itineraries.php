<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a visitor plan a trip without an account.
 *
 * The travel-preference survey and the generated itinerary were both bound to
 * a tourist by a NOT NULL foreign key, so the whole recommendation flow --
 * the headline feature -- sat behind registration. Making the owner optional
 * lets a guest run it end to end; the row is claimed later by setting
 * tourist_id if they sign up, so nothing they filled in is lost.
 *
 * The FK itself is kept (still cascades on delete), so a real account's rows
 * behave exactly as before. Only "must belong to someone" is relaxed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tourist_preferences', function (Blueprint $table) {
            $table->foreignUuid('tourist_id')->nullable()->change();
        });

        Schema::table('itineraries', function (Blueprint $table) {
            $table->foreignUuid('tourist_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Guest rows have no owner, so they cannot satisfy a NOT NULL column.
        // Drop them first rather than letting the change() fail half-applied.
        DB::table('itineraries')->whereNull('tourist_id')->delete();
        DB::table('tourist_preferences')->whereNull('tourist_id')->delete();

        Schema::table('tourist_preferences', function (Blueprint $table) {
            $table->foreignUuid('tourist_id')->nullable(false)->change();
        });

        Schema::table('itineraries', function (Blueprint $table) {
            $table->foreignUuid('tourist_id')->nullable(false)->change();
        });
    }
};
