<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which trip plan an exit survey is reporting back on.
 *
 * The survey and the plan have always been separate islands: the survey asks
 * where somebody went and how it rated, the preference holds what they asked
 * for and what the recommender scored against. With no key between them,
 * nothing could ever answer the question that matters most for tuning the
 * recommender -- of the destinations it put in front of this traveller, which
 * did they actually visit?
 *
 * That gap is why the DRS weights in Equation 3 are hand-set: there was no
 * way to check them against an outcome. Every survey submitted from now on
 * becomes a labelled example instead.
 *
 * Nullable throughout: the survey is deliberately open to anyone (it is linked
 * from the itinerary page but reachable on its own), and a traveller who never
 * made a plan must still be able to complete one. nullOnDelete keeps that true
 * if the plan is later cleared -- the survey's own answers stay valid evidence
 * even once the plan behind it is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exit_surveys', function (Blueprint $table) {
            // tourist_preferences.id is a bigint auto-increment, not a UUID
            // (only Itinerary uses HasUuids) -- foreignUuid() here produced a
            // uuid column that Postgres refused to key against it.
            $table->foreignId('preference_id')->nullable()->after('id')
                ->constrained('tourist_preferences')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exit_surveys', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preference_id');
        });
    }
};
