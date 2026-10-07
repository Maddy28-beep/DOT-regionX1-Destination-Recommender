<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Theme-based days: what the embedding vectors decided about each day of an
 * itinerary (a label such as "Wildlife day", how alike its stops are, which
 * stops), plus a summary of the regrouping (similarity and distance before and
 * after). Null when the vectors were not available and the plain route order was used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->json('day_themes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->dropColumn('day_themes');
        });
    }
};
