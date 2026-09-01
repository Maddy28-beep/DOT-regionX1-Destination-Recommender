<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A rotation counter for breaking ties between equally-scored destinations.
 *
 * Most of the catalogue carries no ratings, tags or coordinates, so large
 * groups of destinations score identically and there is no honest basis for
 * preferring one over another. Resolving those ties by database id meant the
 * same place won every time and travellers saw the same handful of
 * destinations in every itinerary.
 *
 * Regenerating bumps this counter, which reseeds the tie-break. Destinations
 * that genuinely differ keep their order -- only equals are shuffled, and the
 * shuffle is deterministic for a given counter, so a plan stays reproducible
 * and testable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tourist_preferences', function (Blueprint $table) {
            $table->smallInteger('variation')->default(0)->after('origin_label');
        });
    }

    public function down(): void
    {
        Schema::table('tourist_preferences', function (Blueprint $table) {
            $table->dropColumn('variation');
        });
    }
};
