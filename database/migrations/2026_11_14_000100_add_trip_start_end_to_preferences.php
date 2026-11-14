<?php

use App\Support\TripWindow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The trip's real start and end (Philippine wall-clock time) and its derived length in days.
 *
 * Only adds nullable columns and fills them for trips that already have an arrival date, so the code from before this
 * migration keeps working on the new schema. arrival_date / arrival_time / travel_days / finish-by (start_date,
 * arrival_time, travel_days, end_time) are NOT dropped here: while the trip_location_v2 switch can be turned off, the old
 * form needs them, and the planner is still fed from them (derived from start_at and end_at when the switch is on). They
 * are dropped in a later migration, once the switch is permanent.
 *
 * Old trips are mapped as: start = arrival date + arrival time (09:00 when there was none); end = the last day (arrival
 * date + days - 1) at the finish-by time, or at 23:59 when there was none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tourist_preferences', function (Blueprint $table) {
            $table->dateTime('start_at')->nullable();
            $table->dateTime('end_at')->nullable();
            $table->unsignedSmallInteger('duration_days')->nullable();
        });

        DB::table('tourist_preferences')->whereNotNull('start_date')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $window = TripWindow::fromLegacy($row->start_date, $row->arrival_time, (int) $row->travel_days, $row->end_time);
                if ($window === null) {
                    continue;
                }
                DB::table('tourist_preferences')->where('id', $row->id)->update([
                    'start_at' => TripWindow::toStorage($window[0]),
                    'end_at' => TripWindow::toStorage($window[1]),
                    'duration_days' => TripWindow::durationDays($window[0], $window[1]),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tourist_preferences', function (Blueprint $table) {
            $table->dropColumn(['start_at', 'end_at', 'duration_days']);
        });
    }
};
