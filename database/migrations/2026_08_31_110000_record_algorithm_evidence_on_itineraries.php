<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the working that the recommendation algorithms already do.
 *
 * ContentBasedRecommendationService computes all five factors of the
 * Destination Recommendation Score -- Preference Match, Ratings, Popularity,
 * Distance, Amenities (Sec. 2.3.3, Equation 3) -- and until now only the
 * combined score survived. The itinerary could therefore state a result but
 * never show how it was reached, which is exactly the question an
 * establishment or a reviewer asks first.
 *
 * The same was true of Apriori: it chose the restaurants and the accommodation
 * through association rules and left no trace of which rule fired or how
 * strong it was. The support and confidence are cheap to keep and are the only
 * thing that turns "frequently visited together" from an assertion into
 * evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itinerary_matches', function (Blueprint $table) {
            // Each factor on the same 1-5 scale as match_score, so the
            // weighted sum can be checked by eye against Equation 3.
            $table->decimal('pm', 4, 2)->nullable()->after('match_score');
            $table->decimal('rs', 4, 2)->nullable()->after('pm');
            $table->decimal('ps', 4, 2)->nullable()->after('rs');
            $table->decimal('ds', 4, 2)->nullable()->after('ps');
            $table->decimal('as', 4, 2)->nullable()->after('ds');
        });

        Schema::table('itinerary_items', function (Blueprint $table) {
            // Which listing's rule produced this row, and how strong it was.
            $table->string('rule_basis', 120)->nullable()->after('note');
            $table->decimal('rule_support', 6, 4)->nullable()->after('rule_basis');
            $table->decimal('rule_confidence', 6, 4)->nullable()->after('rule_support');
            $table->smallInteger('rule_co_count')->nullable()->after('rule_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('itinerary_matches', function (Blueprint $table) {
            $table->dropColumn(['pm', 'rs', 'ps', 'ds', 'as']);
        });

        Schema::table('itinerary_items', function (Blueprint $table) {
            $table->dropColumn(['rule_basis', 'rule_support', 'rule_confidence', 'rule_co_count']);
        });
    }
};
