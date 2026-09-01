<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Re-key visits and saved places from a tourist account to an anonymous
 * browser token, so neither feature needs personal data (Data Privacy Act).
 *
 * Visits: a scan is still counted once per browser, per establishment, per
 * day -- the unique index just moves from tourist_id to visitor_token, which
 * preserves the anti-double-counting rule DOT asked for without knowing who
 * the visitor is.
 *
 * Saves: saved_destinations only ever held destinations. It is replaced by a
 * polymorphic saved_listings so a visitor can also save accommodations,
 * restaurants and souvenir centres from the same heart control.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tourist_visits', function (Blueprint $table) {
            $table->string('visitor_token', 64)->nullable()->after('id')->index();
        });

        /*
         * Give each existing visitor a token of their own so historical visit
         * counts survive the change instead of collapsing. Mapping one
         * tourist_id to one random token preserves the per-person dedupe the
         * old unique index enforced while severing the link to the account --
         * the token is drawn at random here and is not derived from anything
         * about the person.
         */
        foreach (DB::table('tourist_visits')->distinct()->pluck('tourist_id') as $touristId) {
            DB::table('tourist_visits')
                ->where('tourist_id', $touristId)
                ->update(['visitor_token' => (string) Str::uuid()]);
        }

        // The old index is keyed on a column that is about to disappear.
        Schema::table('tourist_visits', function (Blueprint $table) {
            $table->dropUnique('tourist_visits_unique_daily');
        });

        Schema::table('tourist_visits', function (Blueprint $table) {
            $table->unique(
                ['visitor_token', 'listing_kind', 'listing_id', 'visit_date'],
                'visits_unique_daily_per_browser'
            );
        });

        Schema::create('saved_listings', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_token', 64)->index();
            $table->string('listing_kind', 30);
            $table->unsignedBigInteger('listing_id');
            $table->timestamp('saved_at')->useCurrent();

            $table->unique(['visitor_token', 'listing_kind', 'listing_id'], 'saved_unique_per_browser');
            $table->index(['listing_kind', 'listing_id']);
        });

        Schema::dropIfExists('saved_destinations');
    }

    public function down(): void
    {
        Schema::create('saved_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tourist_id')->constrained('tourists')->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained('destinations')->cascadeOnDelete();
            $table->timestamp('saved_at')->useCurrent();

            $table->unique(['tourist_id', 'destination_id']);
        });

        Schema::dropIfExists('saved_listings');

        Schema::table('tourist_visits', function (Blueprint $table) {
            $table->dropUnique('visits_unique_daily_per_browser');
            $table->dropColumn('visitor_token');
        });

        Schema::table('tourist_visits', function (Blueprint $table) {
            $table->unique(
                ['tourist_id', 'listing_kind', 'listing_id', 'visit_date'],
                'tourist_visits_unique_daily'
            );
        });
    }
};
