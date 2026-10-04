<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apriori mines exit surveys as transactions, and until now nothing said which
 * of them were seeded for development and which were filled in by a real
 * tourist -- so the admin Association Rules page presented simulated
 * co-visitation as observed behaviour.
 *
 * data_source is 'real' by default (what the public survey form writes) and
 * 'demo' for rows DemoExitSurveySeeder generates. The seeder replaces its own
 * demo rows on every run and never touches real ones.
 *
 * Existing rows are left as 'real' on purpose: this migration cannot tell a
 * seeded row from a genuine one in an environment it has not seen. On a
 * database known to hold only seeded surveys, run
 * `php artisan db:seed --class=DemoExitSurveySeeder` after
 * `UPDATE exit_surveys SET data_source = 'demo'`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exit_surveys', function (Blueprint $table) {
            $table->string('data_source', 10)->default('real')->after('comments');
            $table->index('data_source');
        });
    }

    public function down(): void
    {
        Schema::table('exit_surveys', function (Blueprint $table) {
            $table->dropIndex(['data_source']);
            $table->dropColumn('data_source');
        });
    }
};
