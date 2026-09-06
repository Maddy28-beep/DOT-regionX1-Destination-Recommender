<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the one piece of evidence about how an itinerary was built that
 * wasn't already being kept: whether the geographic range filter had to
 * widen past what the traveller actually asked for.
 *
 * ContentBasedRecommendationService::candidatesWithinRange() already decides
 * this and exposes it as lastRangeTierUsed/lastRangeWidened -- but nothing
 * downstream ever read those properties, so a traveller who picked "Within
 * the City" for a baseline like Mati City (which genuinely doesn't have six
 * destinations inside 25km) got a plan with stops 60-70km away and no
 * indication that their preference had been silently loosened to fill the
 * trip. This just gives that already-computed fact somewhere to live past
 * the end of the request that computed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->string('range_tier_used', 20)->nullable()->after('est_party_size');
            $table->boolean('range_widened')->default(false)->after('range_tier_used');
        });
    }

    public function down(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->dropColumn(['range_tier_used', 'range_widened']);
        });
    }
};
