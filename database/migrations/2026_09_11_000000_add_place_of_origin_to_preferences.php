<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOT Region XI asked for visitor origin data specifically because the exit
 * survey's own "origin" field (Table 33) only ever reaches whoever finishes
 * that optional, post-trip survey -- a small fraction of travellers. Asking
 * here instead, in the one form every itinerary has to pass through, is what
 * actually gets DOT origin data at scale.
 *
 * Named place_of_origin rather than reusing "origin": this table already has
 * origin_lat/origin_lng/origin_label for the trip's physical starting point
 * (used to sequence the itinerary), which is a different fact about the
 * traveller than where they call home.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tourist_preferences', function (Blueprint $table) {
            $table->string('place_of_origin', 150)->nullable()->after('visitor_type');
        });
    }

    public function down(): void
    {
        Schema::table('tourist_preferences', function (Blueprint $table) {
            $table->dropColumn('place_of_origin');
        });
    }
};
