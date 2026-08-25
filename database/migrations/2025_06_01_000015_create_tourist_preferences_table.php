<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tourist_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tourist_id')->constrained('tourists')->cascadeOnDelete();
            $table->smallInteger('travel_days');
            $table->string('travel_type', 20);
            $table->string('budget', 20);
            $table->string('accommodation_pref', 20);
            $table->enum('distance_pref', ['near', 'moderate', 'far']);
            $table->text('accessibility_notes')->nullable();
            $table->date('start_date')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tourist_preferences');
    }
};
