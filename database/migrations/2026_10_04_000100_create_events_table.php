<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Festivals and other dated happenings, shown on the public Events calendar
 * and as the homepage's "Happening soon" strip.
 *
 * An event is not a listing: it has no accreditation, rating or photos, so it
 * gets its own small table rather than a column on destinations. starts_on and
 * ends_on are plain dates (a festival is a span of days, not a moment); a
 * single-day event leaves ends_on null. time_label is free text ("6:00 PM")
 * because the calendar only ever displays it, never sorts or filters on it.
 *
 * archived_at follows the same soft-hide convention as the listing tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->string('slug', 170)->unique();
            $table->string('category', 20);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('location', 150);
            $table->string('time_label', 60)->nullable();
            $table->text('description')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
