<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns itinerary_items from a list of stops into an hour-by-hour schedule.
 *
 * The old shape held one row per place with a coarse "Morning"/"Afternoon"
 * label, which cannot express the plan a traveller actually needs: when to
 * leave, how long the journey takes, where lunch happens, when they are back at
 * the hotel. Those are rows in their own right now, so a day reads as a
 * timetable rather than a list of names.
 *
 * A row is one line of that timetable. `kind` says what sort of line it is,
 * which is what lets the schedule render a real distance for a travel leg and
 * "On-site Activity" or "Within Accommodation" for the rest.
 *
 * `slot` is kept and still written -- it remains the honest time-of-day band
 * for a row, and existing queries and the recommendation write-up refer to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itinerary_items', function (Blueprint $table) {
            // Rows within a day are now ordered by the clock, not by insertion.
            $table->smallInteger('sort_order')->default(0)->after('day_number');

            // baseline | travel | activity | meal | checkin | overnight | departure
            $table->string('kind', 20)->default('activity')->after('slot');
            $table->string('title', 150)->nullable()->after('kind');

            $table->time('starts_at')->nullable()->after('title');
            $table->time('ends_at')->nullable()->after('starts_at');

            // Travel legs only. The minute range is a range because road speed
            // through Davao varies enough that a single number would be a
            // false promise.
            $table->decimal('distance_km', 6, 1)->nullable()->after('ends_at');
            $table->smallInteger('travel_min_minutes')->nullable()->after('distance_km');
            $table->smallInteger('travel_max_minutes')->nullable()->after('travel_min_minutes');

            // Meals are taken at restaurants, which the table could not
            // reference before -- it only knew destinations and accommodations.
            $table->foreignId('restaurant_id')->nullable()->after('accommodation_id')
                ->constrained('restaurants')->nullOnDelete();
            $table->foreignId('souvenir_center_id')->nullable()->after('restaurant_id')
                ->constrained('souvenir_centers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('itinerary_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('restaurant_id');
            $table->dropConstrainedForeignId('souvenir_center_id');
            $table->dropColumn([
                'sort_order', 'kind', 'title', 'starts_at', 'ends_at',
                'distance_km', 'travel_min_minutes', 'travel_max_minutes',
            ]);
        });
    }
};
