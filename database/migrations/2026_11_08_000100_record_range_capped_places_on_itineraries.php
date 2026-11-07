<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a Close or Commutable trip has to widen its range to find enough places, places whose round trip would not fit in a
 * day's driving ceiling (8 hours) are left out. This keeps how many, so the itinerary page can tell the traveller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->unsignedSmallInteger('range_capped_places')->default(0)->after('range_widened');
        });
    }

    public function down(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->dropColumn('range_capped_places');
        });
    }
};
