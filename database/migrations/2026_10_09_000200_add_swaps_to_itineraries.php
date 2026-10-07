<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers which stops the traveller swapped for a similar place
 * (original destination id => replacement destination id), so a saved or
 * reloaded itinerary still shows the places they chose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->json('swaps')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->dropColumn('swaps');
        });
    }
};
