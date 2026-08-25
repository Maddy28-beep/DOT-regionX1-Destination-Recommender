<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exit_surveys', function (Blueprint $table) {
            $table->string('travel_purpose', 30)->nullable()->after('origin');
            $table->smallInteger('actual_days_stayed')->nullable()->after('travel_purpose');
        });
    }

    public function down(): void
    {
        Schema::table('exit_surveys', function (Blueprint $table) {
            $table->dropColumn(['travel_purpose', 'actual_days_stayed']);
        });
    }
};
