<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_food_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('preference', 30);
            $table->string('status', 20)->default('not_confirmed');
            $table->text('source_url')->nullable();
            $table->string('source_name')->nullable();
            $table->text('evidence')->nullable();
            $table->date('checked_on')->nullable();
            $table->string('certifier')->nullable();
            $table->string('certificate_reference')->nullable();
            $table->text('coverage')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('expires_on')->nullable();
            $table->timestamps();
            $table->unique(['restaurant_id', 'preference']);
            $table->index(['preference', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_food_preferences');
    }
};
