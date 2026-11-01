<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->timestamp('planner_verified_at')->nullable();
            $table->text('planner_verification_source')->nullable();
            $table->foreignId('host_destination_id')->nullable()->constrained('destinations')->nullOnDelete();
            $table->foreignId('host_accommodation_id')->nullable()->constrained('accommodations')->nullOnDelete();
        });
        Schema::table('destinations', fn (Blueprint $table) => $table->text('planning_notes')->nullable());
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('host_destination_id');
            $table->dropConstrainedForeignId('host_accommodation_id');
            $table->dropColumn(['planner_verified_at', 'planner_verification_source']);
        });
        Schema::table('destinations', fn (Blueprint $table) => $table->dropColumn('planning_notes'));
    }
};
