<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tourists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('full_name', 100);
            $table->string('email', 100)->unique();
            $table->string('password_hash', 255);
            $table->string('nationality', 80);
            $table->string('age_range', 20);
            $table->string('gender', 20)->nullable();
            $table->string('contact_number', 20)->nullable();
            $table->boolean('privacy_consent');
            $table->timestamp('privacy_consent_at')->nullable();
            $table->string('preferred_language', 50)->default('English');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tourists');
    }
};
