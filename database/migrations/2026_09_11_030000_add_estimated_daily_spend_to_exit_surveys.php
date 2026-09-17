<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOT Region XI asked how much a tourist spends per day of their visit, to
 * gauge economic impact -- self-reported like the rest of the exit survey,
 * so it stays optional and anonymous rather than tied to any transaction
 * record (the app has none).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exit_surveys', function (Blueprint $table) {
            $table->decimal('estimated_daily_spend', 10, 2)->nullable()->after('actual_days_stayed');
        });
    }

    public function down(): void
    {
        Schema::table('exit_surveys', function (Blueprint $table) {
            $table->dropColumn('estimated_daily_spend');
        });
    }
};
