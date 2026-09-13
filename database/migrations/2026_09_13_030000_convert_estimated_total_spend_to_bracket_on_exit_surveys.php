<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Total spend becomes a picked range (see ExitSurveyController::SPEND_BRACKETS)
 * rather than a typed exact figure, so the column now holds a bracket key
 * ("under_10000") instead of a decimal amount. doctrine/dbal isn't installed,
 * so the type change goes through raw SQL rather than Schema::table()->change().
 *
 * SQLite (used by the test suite) has no real column typing -- any column
 * accepts any value regardless of its declared type -- so there is nothing
 * to ALTER there; only Postgres (dev/production) needs the statement below.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE exit_surveys ALTER COLUMN estimated_total_spend TYPE VARCHAR(20) USING estimated_total_spend::text');
        }
    }

    public function down(): void
    {
        // Existing bracket-key strings ("under_10000") have no exact numeric
        // equivalent, so a rollback nulls the column rather than guessing --
        // acceptable here since this column has no production data yet.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE exit_surveys ALTER COLUMN estimated_total_spend TYPE DECIMAL(10,2) USING NULL');
        }
    }
};
