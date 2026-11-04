<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['destination_tags', 'listing_amenities'] as $table) {
            Schema::table($table, fn (Blueprint $schema) => $schema->string('display_name', 80)->nullable());
        }
    }

    public function down(): void
    {
        foreach (['destination_tags', 'listing_amenities'] as $table) {
            Schema::table($table, fn (Blueprint $schema) => $schema->dropColumn('display_name'));
        }
    }
};
