<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tour operators add their own packages, and DOT approves a new one before travellers can see it.
 *
 * A package now has a status: draft, in_review, changes_requested, live or paused. Everything that decides what
 * travellers see keeps reading is_accredited, so a package is public exactly when its status is live; the status
 * just says why a hidden one is hidden. Packages that exist already keep their present behaviour: the accredited
 * ones are live, the rest are drafts.
 *
 * package_events is the package's own history (sent, approved, changes asked for, paused), kept for the note DOT
 * wrote and for who did what and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->string('status', 20)->default('live')->index()->after('is_accredited');
            $table->string('meeting_point', 150)->nullable()->after('location');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
        });

        DB::table('packages')->where('is_accredited', false)->update(['status' => 'draft']);

        Schema::create('package_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->string('actor_type', 20);          // operator | admin
            $table->string('actor_name', 150)->nullable();
            $table->string('action', 30);              // submitted | approved | changes_requested | withdrawn | paused | resumed
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_events');

        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['status', 'meeting_point', 'submitted_at', 'reviewed_at', 'review_note']);
        });
    }
};
