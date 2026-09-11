<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOT Region XI asked that establishments be able to show their own discount
 * codes/promos on their public listing page -- self-service, not admin-run,
 * so this is owned by the establishment portal rather than mirroring
 * advisories. listing_kind/listing_id follow the same polymorphic pattern as
 * reviews and listing_photos, scoped by EstablishmentPromotionController to
 * the caller's own matched listing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('listing_kind', 30);
            $table->unsignedBigInteger('listing_id');
            $table->string('title', 150);
            $table->string('code', 30)->nullable();
            $table->string('description', 500)->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->timestamps();

            $table->index(['listing_kind', 'listing_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
