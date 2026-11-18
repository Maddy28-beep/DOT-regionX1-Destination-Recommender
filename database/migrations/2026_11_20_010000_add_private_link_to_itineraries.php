<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plan the traveler keeps is reached by a private link, never by an account. Only the hash of each link's secret is stored,
 * so a copy of the database cannot open anyone's plan. The edit link continues the plan; the share link only shows it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->char('edit_token_hash', 64)->nullable()->unique();
            $table->char('share_token_hash', 64)->nullable()->unique();
            $table->timestamp('kept_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->dropUnique(['edit_token_hash']);
            $table->dropUnique(['share_token_hash']);
            $table->dropColumn(['edit_token_hash', 'share_token_hash', 'kept_at', 'last_opened_at']);
        });
    }
};
