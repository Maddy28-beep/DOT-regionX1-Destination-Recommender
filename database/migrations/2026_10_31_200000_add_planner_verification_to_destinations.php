<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->timestamp('planner_verified_at')->nullable();
            $table->text('planner_verification_source')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('destinations', fn (Blueprint $table) => $table->dropColumn(['planner_verified_at', 'planner_verification_source']));
    }
};
