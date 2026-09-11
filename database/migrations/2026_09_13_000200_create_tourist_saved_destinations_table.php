<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An account-scoped favorites list, deliberately separate from the anonymous
 * `saved_listings` table (visitor_token-keyed, no account at all). The two
 * are never merged: a tourist's anonymous browser saves and their account
 * saves are just different data, matching the same "optional account never
 * interferes with the anonymous path" rule the rest of this feature follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tourist_saved_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tourist_account_id')->constrained()->cascadeOnDelete();
            $table->string('listing_kind', 30);
            $table->unsignedBigInteger('listing_id');
            $table->timestamp('saved_at')->useCurrent();

            $table->unique(['tourist_account_id', 'listing_kind', 'listing_id'], 'tourist_saved_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tourist_saved_destinations');
    }
};
