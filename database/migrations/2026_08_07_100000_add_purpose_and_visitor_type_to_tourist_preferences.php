<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tourist_preferences', function (Blueprint $table) {
            $table->string('travel_purpose', 30)->nullable()->after('travel_type');
            $table->string('visitor_type', 30)->nullable()->after('travel_purpose');
        });
    }

    public function down(): void
    {
        Schema::table('tourist_preferences', function (Blueprint $table) {
            $table->dropColumn(['travel_purpose', 'visitor_type']);
        });
    }
};
