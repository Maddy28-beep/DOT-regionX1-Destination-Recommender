<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tourist_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tourist_id')->constrained('tourists')->cascadeOnDelete();
            $table->string('listing_kind', 30);
            $table->unsignedBigInteger('listing_id');
            $table->date('visit_date');
            $table->string('source', 50)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['listing_kind', 'listing_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tourist_visits');
    }
};
