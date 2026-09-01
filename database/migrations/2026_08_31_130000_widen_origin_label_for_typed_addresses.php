<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The starting point can now be a typed or geocoded address rather than just
 * the words "your shared location", and 60 characters does not hold one.
 * A full Nominatim display name runs well past that, and silently truncating
 * somebody's address is worse than storing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tourist_preferences', function (Blueprint $table) {
            $table->string('origin_label', 200)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tourist_preferences', function (Blueprint $table) {
            $table->string('origin_label', 60)->nullable()->change();
        });
    }
};
