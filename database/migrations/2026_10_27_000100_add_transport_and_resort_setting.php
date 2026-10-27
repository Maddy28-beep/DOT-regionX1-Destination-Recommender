<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two new things a traveller can say about the trip, and the one thing a listing needs to answer the second.
 *
 * transport_pref: how they will get between stops (see TransportMode). It changes the speed the journey-time
 * estimate assumes, so a jeepney trip is planned at jeepney pace instead of at the pace of a private car.
 * Nullable on purpose: every preference saved before this existed has no answer, and a missing answer is
 * planned exactly as it always was.
 *
 * resort_setting: the kind of resort they would like to stay at (Mountain, Island, Lake...). Optional, and
 * only consulted when the accommodation preference is a resort.
 *
 * accommodations.resort_settings: what each resort actually is. A set, not a single value -- a lakeside
 * resort can also be all-inclusive -- stored as pipe-delimited text ("|Island|All-Inclusive|") so that a
 * plain LIKE matches one setting regardless of the database driver. Filled in by ResortSettingSeeder; null
 * means "not classified yet", never "has no setting".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tourist_preferences', function (Blueprint $table) {
            $table->string('transport_pref', 20)->nullable()->after('distance_pref');
            $table->string('resort_setting', 20)->nullable()->after('accommodation_pref');
        });

        Schema::table('accommodations', function (Blueprint $table) {
            $table->string('resort_settings', 120)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('tourist_preferences', function (Blueprint $table) {
            $table->dropColumn(['transport_pref', 'resort_setting']);
        });

        Schema::table('accommodations', function (Blueprint $table) {
            $table->dropColumn('resort_settings');
        });
    }
};
