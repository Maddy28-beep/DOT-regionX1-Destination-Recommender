<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes tourist accounts and the personal data they held (Data Privacy Act).
 *
 * The public side no longer registers or identifies travellers at all: trip
 * planning is anonymous and session-based, visits are counted by an opaque
 * browser token, saved places hang off that same token, and the exit survey
 * was always anonymous. Nothing left needs a name, an email, a nationality,
 * an age range, a contact number, or a password.
 *
 * Dropping the table is the point rather than a side effect -- data that is
 * never stored cannot be breached, mishandled, or subject to a retention
 * policy. The owning columns are removed first so the drop is not blocked by
 * a foreign key, and so surviving anonymous rows (guest itineraries and
 * preferences) are kept rather than cascaded away.
 *
 * Irreversible in practice: down() restores the shape, not the data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tourist_preferences', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tourist_id');
        });

        Schema::table('itineraries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tourist_id');
        });

        Schema::table('tourist_visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tourist_id');
        });

        Schema::table('chatbot_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tourist_id');
        });

        Schema::dropIfExists('tourists');
    }

    public function down(): void
    {
        Schema::create('tourists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('full_name', 100);
            $table->string('email', 100)->unique();
            $table->string('password_hash', 255);
            $table->string('nationality', 80);
            $table->string('age_range', 20);
            $table->string('gender', 20)->nullable();
            $table->string('contact_number', 20)->nullable();
            $table->boolean('privacy_consent');
            $table->timestamp('privacy_consent_at')->nullable();
            $table->string('preferred_language', 50)->default('English');
            $table->timestamp('created_at')->useCurrent();
        });

        foreach (['tourist_preferences', 'itineraries', 'tourist_visits'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignUuid('tourist_id')->nullable()->constrained('tourists')->cascadeOnDelete();
            });
        }

        Schema::table('chatbot_logs', function (Blueprint $table) {
            $table->foreignUuid('tourist_id')->nullable()->constrained('tourists')->nullOnDelete();
        });
    }
};
