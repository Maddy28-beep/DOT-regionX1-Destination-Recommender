<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate 1-5 ratings for an establishment's service and its amenities,
 * beside the existing overall rating (thesis objective 1.3.2.6).
 *
 * Both columns are NULLABLE and optional: every existing review keeps NULL
 * (it was never asked), and a visitor may still leave only the overall star
 * rating. Nothing is back-filled and no existing value is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->smallInteger('service_rating')->nullable()->after('rating');
            $table->smallInteger('amenities_rating')->nullable()->after('service_rating');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['service_rating', 'amenities_rating']);
        });
    }
};
