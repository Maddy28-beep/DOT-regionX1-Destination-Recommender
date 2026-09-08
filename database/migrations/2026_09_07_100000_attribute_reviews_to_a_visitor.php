<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties a review to the anonymous browser that left it.
 *
 * Until now nothing in the application could create a review at all -- the
 * only review routes let an establishment read and reply to them -- so the
 * table held nothing but seeded placeholders. Accepting real ones needs two
 * things this column provides: a way to check the reviewer actually checked in
 * at the place (tourist_visits is keyed on the same token), and a way to stop
 * one browser rating the same listing over and over.
 *
 * The token is the same opaque random UUID EnsureVisitorToken already issues.
 * It is not a name, an account or a device fingerprint, and it is what lets a
 * review be verified without asking a traveller who they are.
 *
 * Nullable because the seeded rows predate it, and because a unique index
 * treats NULLs as distinct -- so those rows neither collide with each other
 * nor block a genuine review of the same listing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('visitor_token', 36)->nullable()->after('listing_id');

            // One review per browser per listing, enforced by the database
            // rather than only by the controller.
            $table->unique(['listing_kind', 'listing_id', 'visitor_token'], 'reviews_one_per_visitor');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique('reviews_one_per_visitor');
            $table->dropColumn('visitor_token');
        });
    }
};
