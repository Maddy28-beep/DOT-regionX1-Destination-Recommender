<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOT approves what an establishment changes on its listing before travelers see it. The changes pile up in a draft
 * (a submission), the establishment sends the draft to DOT, and an admin approves it or returns it with a note.
 * A photo or an offer added in a draft exists from the start but stays hidden (review_status) until it is approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('establishment_account_id')->constrained('establishment_accounts')->cascadeOnDelete();
            $table->string('listing_kind', 30);
            $table->unsignedBigInteger('listing_id');
            $table->string('status', 12)->default('draft')->index(); // draft, pending, returned, approved
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewed_by', 120)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['listing_kind', 'listing_id']);
        });

        Schema::create('listing_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_submission_id')->constrained('listing_submissions')->cascadeOnDelete();
            $table->string('kind', 12); // details, hours, position, amenities, photo, promotion
            $table->json('payload');
            $table->timestamps();
        });

        Schema::table('listing_photos', function (Blueprint $table) {
            $table->string('review_status', 12)->nullable()->index(); // null = public; draft, pending, returned = hidden
        });
        Schema::table('promotions', function (Blueprint $table) {
            $table->string('review_status', 12)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('promotions', fn (Blueprint $table) => $table->dropColumn('review_status'));
        Schema::table('listing_photos', fn (Blueprint $table) => $table->dropColumn('review_status'));
        Schema::dropIfExists('listing_changes');
        Schema::dropIfExists('listing_submissions');
    }
};
