<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The permanent, anonymous visitor-origin statistic (trip_location_v2).
 *
 * One row per (day, kind of answer, country, province, city) with a COUNT. Nothing else: no visitor token, no plan or trip
 * id, no IP address, no time of day, no pick-up. Empty text, never NULL, stands for "not given" so that the unique key
 * really is unique (a NULL is never equal to another NULL in PostgreSQL).
 *
 * Additive only. The 30-day purge of the trip rows is untouched, and there is NO backfill: answers given before this table
 * existed were promised deletion after 30 days, so they are not carried over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_origin_daily', function (Blueprint $table) {
            $table->id();
            $table->date('day');                                    // the Philippine date the visitor first planned
            $table->string('answer', 12);                           // 'chosen' or 'unspecified' ("Prefer not to say")
            $table->string('country_code', 2)->default('');
            $table->string('province', 80)->default('');
            $table->string('city', 60)->default('');
            $table->unsignedInteger('visitors')->default(0);
            $table->unique(['day', 'answer', 'country_code', 'province', 'city'], 'visitor_origin_daily_key');
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_origin_daily');
    }
};
