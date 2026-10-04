<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Not every DOT-accredited business is somewhere a traveller goes sightseeing.
 * The accreditation list includes a convention centre, golf and country clubs
 * and day spas, and because they are accredited the recommender treated them
 * like attractions: a "Cultural and heritage tour" slot went to SMX Convention
 * Center, and golf clubs filled "Nature and Adventure" days.
 *
 * itinerary_role says how a destination may be used in a generated plan:
 *   sightseeing  an attraction; ranked for everyone (the default)
 *   optional     only when the traveller picked the interest the type implies
 *                (a spa when "Relaxation & Wellness" is chosen)
 *   excluded     never offered as a stop: event venues and members' clubs
 * It does not change accreditation or visibility; the listing stays on the
 * public pages. A DOT Admin can correct any row.
 *
 * The defaults below are by type and are a starting point, not a verdict:
 * Events & Conventions and Sports & Recreation are excluded, Wellness & Spa is
 * optional, everything else stays sightseeing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->string('itinerary_role', 12)->default('sightseeing')->after('type');
        });

        DB::table('destinations')->whereIn('type', ['Events & Conventions', 'Sports & Recreation'])->update(['itinerary_role' => 'excluded']);
        DB::table('destinations')->where('type', 'Wellness & Spa')->update(['itinerary_role' => 'optional']);
    }

    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->dropColumn('itinerary_role');
        });
    }
};
