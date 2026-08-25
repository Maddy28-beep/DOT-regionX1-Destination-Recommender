<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tourist_visits', function (Blueprint $table) {
            $table->unique(
                ['tourist_id', 'listing_kind', 'listing_id', 'visit_date'],
                'tourist_visits_unique_daily'
            );
        });
    }

    public function down(): void
    {
        Schema::table('tourist_visits', function (Blueprint $table) {
            $table->dropUnique('tourist_visits_unique_daily');
        });
    }
};
