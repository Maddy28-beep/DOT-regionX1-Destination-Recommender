<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Travelers no longer have an account. A plan worth keeping is kept by a private link instead.
 *
 * What the old accounts held is deleted with them: the aliases, their saved places, and the itineraries they had saved (with their
 * stops, and the trip answers behind them, health answers included, unless an exit survey still needs the trip).
 * Itineraries nobody saved are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $saved = DB::table('itineraries')->whereNotNull('tourist_account_id')->get(['id', 'preference_id']);
        $itineraryIds = $saved->pluck('id');
        $preferenceIds = $saved->pluck('preference_id')->filter()->unique()->values();

        foreach (['itinerary_items', 'itinerary_matches'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->whereIn('itinerary_id', $itineraryIds)->delete();
            }
        }
        DB::table('itineraries')->whereIn('id', $itineraryIds)->delete();

        $stillUsed = DB::table('itineraries')->whereIn('preference_id', $preferenceIds)->pluck('preference_id');
        $usedBySurvey = DB::table('exit_surveys')->whereIn('preference_id', $preferenceIds)->pluck('preference_id');
        $deletable = $preferenceIds->diff($stillUsed)->diff($usedBySurvey)->values();

        $profileIds = DB::table('tourist_health_profiles')->whereIn('preference_id', $deletable)->pluck('id');
        DB::table('tourist_health_conditions')->whereIn('health_profile_id', $profileIds)->delete();
        DB::table('tourist_health_profiles')->whereIn('id', $profileIds)->delete();
        foreach (['preference_activities', 'preference_amenities'] as $table) {
            DB::table($table)->whereIn('preference_id', $deletable)->delete();
        }
        DB::table('tourist_preferences')->whereIn('id', $deletable)->delete();

        Schema::dropIfExists('tourist_saved_destinations');

        Schema::table('itineraries', fn (Blueprint $table) => $table->dropForeign(['tourist_account_id']));
        Schema::table('itineraries', fn (Blueprint $table) => $table->dropColumn('tourist_account_id'));

        Schema::dropIfExists('tourist_accounts');
    }

    public function down(): void
    {
        throw new RuntimeException('Removing the traveler accounts cannot be undone here. Restore the database backup taken before migrating.');
    }
};
