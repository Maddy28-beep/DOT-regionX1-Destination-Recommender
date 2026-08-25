<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itinerary_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('itinerary_id')->constrained('itineraries')->cascadeOnDelete();
            $table->smallInteger('day_number');
            $table->string('slot', 20);
            $table->foreignId('destination_id')->nullable()->constrained('destinations')->nullOnDelete();
            $table->foreignId('accommodation_id')->nullable()->constrained('accommodations')->nullOnDelete();
            $table->string('note', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itinerary_items');
    }
};
