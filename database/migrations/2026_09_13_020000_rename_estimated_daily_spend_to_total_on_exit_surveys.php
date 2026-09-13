<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A tourist filling this out after the trip reliably knows what the whole
 * trip cost, not a mental average-per-day figure they never actually
 * tracked while traveling -- so the raw input becomes the trip total, and
 * the "average daily spend" DOT wants is derived by dividing by the
 * reported days stayed instead of the other way around (see
 * AdminDashboardController::exitSurveys()).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE exit_surveys RENAME COLUMN estimated_daily_spend TO estimated_total_spend');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE exit_surveys RENAME COLUMN estimated_total_spend TO estimated_daily_spend');
    }
};
