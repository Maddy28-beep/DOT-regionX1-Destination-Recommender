<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preference_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preference_id')->constrained('tourist_preferences')->cascadeOnDelete();
            $table->string('activity', 30);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preference_activities');
    }
};
