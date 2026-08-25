<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tourist_health_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tourist_id')->unique()->constrained('tourists')->cascadeOnDelete();
            $table->boolean('consent')->default(false);
            $table->timestamp('consent_at')->nullable();
            $table->string('other_text', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tourist_health_profiles');
    }
};
