<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One shared list of amenities and accessibility features (App\Support\Amenities), ticked on every kind of listing.
 *
 * Destinations keep theirs where they always were (destination_tags, kind "amenity"), because the recommender and the
 * embeddings read them there. Every other kind uses listing_amenities. A package is a trip, not a place, so its
 * "amenities" are who it suits and what is on the trip, plus a difficulty and a note for travellers with health needs.
 *
 * A photo can say which amenity it shows (listing_photos.amenity). The system never reads the picture: the uploader
 * says what it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_amenities', function (Blueprint $table) {
            $table->id();
            $table->string('listing_kind', 30);
            $table->unsignedBigInteger('listing_id');
            $table->string('amenity', 40);
            $table->unique(['listing_kind', 'listing_id', 'amenity']);
        });

        Schema::table('listing_photos', function (Blueprint $table) {
            $table->string('amenity', 40)->nullable()->after('category');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->string('difficulty', 12)->nullable();
            $table->string('accessibility_note', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('packages', fn (Blueprint $table) => $table->dropColumn(['difficulty', 'accessibility_note']));
        Schema::table('listing_photos', fn (Blueprint $table) => $table->dropColumn('amenity'));
        Schema::dropIfExists('listing_amenities');
    }
};
