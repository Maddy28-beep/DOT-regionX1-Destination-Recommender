<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Old addresses of destinations whose slug was changed (the "-sample" ending was dropped), so a link, a bookmark or a search
 * result that still has the old address lands on the right page instead of a 404.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destination_slug_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('old_slug')->unique();
            $table->foreignId('destination_id')->constrained('destinations')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_slug_redirects');
    }
};
