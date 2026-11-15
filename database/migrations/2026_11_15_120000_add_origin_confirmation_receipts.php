<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('visitor_origin_daily', function (Blueprint $table) {
            $table->unsignedInteger('generated_visitors')->default(0);
        });
        Schema::create('visitor_origin_receipts', function (Blueprint $table) {
            $table->date('day');
            $table->string('browser_hash', 64);
            $table->text('answer')->nullable();
            $table->boolean('generated')->default(false);
            $table->primary(['day', 'browser_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_origin_receipts');
        Schema::table('visitor_origin_daily', fn (Blueprint $table) => $table->dropColumn('generated_visitors'));
    }
};
