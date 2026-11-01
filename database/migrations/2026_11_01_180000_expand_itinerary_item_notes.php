<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Verified access/transport conditions can exceed the old 300-character limit.
        Schema::table('itinerary_items', fn (Blueprint $table) => $table->text('note')->nullable()->change());
    }

    public function down(): void
    {
        foreach (DB::table('itinerary_items')->select('id', 'note')->orderBy('id')->cursor() as $item) {
            if (mb_strlen($item->note ?? '') > 300) {
                throw new RuntimeException('Cannot shorten itinerary notes without losing existing information.');
            }
        }
        Schema::table('itinerary_items', fn (Blueprint $table) => $table->string('note', 300)->nullable()->change());
    }
};
