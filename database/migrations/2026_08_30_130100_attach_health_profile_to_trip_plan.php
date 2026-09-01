<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Health and accessibility needs move from a stored account profile to the
 * trip plan they inform.
 *
 * The recommender weighs accessibility when ranking destinations, so the
 * information still has to be collected -- but it belongs to one anonymous
 * planning session rather than to a person. Keying it on the preference means
 * it lives exactly as long as the plan does and is never tied to an identity,
 * which matters because health details are sensitive personal information.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tourist_health_profiles', function (Blueprint $table) {
            $table->foreignId('preference_id')->nullable()->after('id')
                ->constrained('tourist_preferences')->cascadeOnDelete();
        });

        // Existing rows belong to accounts that are going away.
        DB::table('tourist_health_profiles')->delete();

        // The column carried a unique index as well as the foreign key, and
        // SQLite refuses to drop a column an index still names.
        Schema::table('tourist_health_profiles', function (Blueprint $table) {
            $table->dropUnique('tourist_health_profiles_tourist_id_unique');
        });

        Schema::table('tourist_health_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tourist_id');
        });

        Schema::table('tourist_health_profiles', function (Blueprint $table) {
            $table->unique('preference_id', 'health_profile_unique_per_plan');
        });
    }

    public function down(): void
    {
        Schema::table('tourist_health_profiles', function (Blueprint $table) {
            $table->dropUnique('health_profile_unique_per_plan');
            $table->dropConstrainedForeignId('preference_id');
        });

        DB::table('tourist_health_profiles')->delete();

        Schema::table('tourist_health_profiles', function (Blueprint $table) {
            $table->foreignUuid('tourist_id')->unique()->constrained('tourists')->cascadeOnDelete();
        });
    }
};
