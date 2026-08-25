<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exit_surveys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestamp('submitted_at')->useCurrent();
            $table->smallInteger('overall_rating')->nullable();
            $table->smallInteger('destination_relevant')->nullable();
            $table->smallInteger('itinerary_useful')->nullable();
            $table->smallInteger('attractions_quality')->nullable();
            $table->smallInteger('accommodation_rating')->nullable();
            $table->smallInteger('transport_rating')->nullable();
            $table->string('would_recommend', 3)->nullable();
            $table->string('comments', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exit_surveys');
    }
};
