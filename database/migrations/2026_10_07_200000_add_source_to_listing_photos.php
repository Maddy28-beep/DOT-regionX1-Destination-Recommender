<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_photos', function (Blueprint $table) {
            $table->text('source_url')->nullable();
            $table->string('source_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('listing_photos', fn (Blueprint $table) => $table->dropColumn(['source_url', 'source_name']));
    }
};
