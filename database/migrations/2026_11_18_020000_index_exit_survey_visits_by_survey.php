<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The association-rule queries join exit_survey_visits to itself on exit_survey_id ("which places did the same
 * trip visit?"). Postgres does not index a foreign-key column by itself, so that join had no index to use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exit_survey_visits', function (Blueprint $table) {
            $table->index('exit_survey_id', 'exit_survey_visits_survey_idx');
        });
    }

    public function down(): void
    {
        Schema::table('exit_survey_visits', function (Blueprint $table) {
            $table->dropIndex('exit_survey_visits_survey_idx');
        });
    }
};
