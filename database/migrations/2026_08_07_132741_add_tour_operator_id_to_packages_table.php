<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedBigInteger('tour_operator_id')->nullable()->after('provider_name');
            $table->foreign('tour_operator_id')->references('id')->on('tour_operators')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropForeign(['tour_operator_id']);
            $table->dropColumn('tour_operator_id');
        });
    }
};
