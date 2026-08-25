<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('name', 150);
            $table->string('location', 255)->nullable();
            $table->unsignedSmallInteger('region_id')->nullable();
            $table->string('duration_label', 50)->nullable();
            $table->smallInteger('duration_days')->nullable();
            $table->string('description', 1000)->nullable();
            $table->string('image_path', 255)->nullable();
            $table->boolean('is_accredited')->default(false);
            $table->decimal('price_per_pax', 10, 2)->nullable();
            $table->string('price_tier', 20)->nullable();
            $table->decimal('rating', 2, 1)->default(0);
            $table->integer('review_count')->default(0);
            $table->string('type', 80)->nullable();
            $table->boolean('featured')->default(false);
            $table->string('provider_name', 150)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('region_id')->references('id')->on('regions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
