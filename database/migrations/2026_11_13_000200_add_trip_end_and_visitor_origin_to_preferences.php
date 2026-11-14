<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trip end ("finish by") and where the visitor comes from.
 *
 * All nullable, nothing renamed or dropped, so the code from before this migration keeps working on the new schema and
 * the migration can be rolled back. The old departure_* and place_of_origin columns stay and are simply not used while
 * the trip_location_v2 switch is on.
 *
 * visitor_origin_* is the visitor's HOME place (country, province, city). It is not the trip's starting point, which is
 * origin_lat / origin_lng / origin_label for historical reasons. These columns are cleared with the plan after 30 days
 * (AnonymousDataPurger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tourist_preferences', function (Blueprint $table) {
            $table->date('end_date')->nullable()->after('arrival_time');
            $table->time('end_time')->nullable()->after('end_date');
            $table->char('visitor_origin_country_code', 2)->nullable();
            $table->string('visitor_origin_region', 60)->nullable();
            $table->string('visitor_origin_province', 80)->nullable();
            $table->string('visitor_origin_city', 100)->nullable();
            $table->string('visitor_origin_source', 12)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tourist_preferences', function (Blueprint $table) {
            $table->dropColumn(['end_date', 'end_time', 'visitor_origin_country_code', 'visitor_origin_region', 'visitor_origin_province', 'visitor_origin_city', 'visitor_origin_source']);
        });
    }
};
