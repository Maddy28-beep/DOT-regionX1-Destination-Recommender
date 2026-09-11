<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks an itinerary as adopted from a tour operator's ready-made package
 * rather than generated from the recommender -- "Plan with this Package"
 * copies the package's day-by-day breakdown into ordinary itinerary_items so
 * the rest of the planning UI (My Itinerary, the exit survey) keeps working
 * unchanged, and this column is what the page reads to show the package's
 * provenance instead of the algorithm panel, and to refuse regenerating a
 * fixed schedule as if it were a generated one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->foreignId('package_id')->nullable()->after('preference_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_id');
        });
    }
};
