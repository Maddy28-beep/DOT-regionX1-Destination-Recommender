<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a place is actually operating. Archiving hides a listing from the
 * public for good and an advisory only warns; neither tells the planner that a
 * place is closed for a stretch of time. This does:
 *
 *   open               the default; recommended as usual
 *   temporarily_closed not recommended for trips that overlap the closure;
 *                      reopens_on is the first day it is open again (null = no date yet)
 *   closed             closed for good; never recommended, still shown with a notice
 *
 * Added to the four listing types an itinerary can contain.
 */
return new class extends Migration
{
    private const TABLES = ['destinations', 'accommodations', 'restaurants', 'souvenir_centers'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('operating_status', 20)->default('open');
                $t->string('closure_reason', 255)->nullable();
                $t->date('reopens_on')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['operating_status', 'closure_reason', 'reopens_on']);
            });
        }
    }
};
