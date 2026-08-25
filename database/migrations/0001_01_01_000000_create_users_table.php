<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ExploreDVO has no generic `users` table: authentication is split across
        // three actor-specific tables (tourists, admin_users, establishment_accounts),
        // each behind its own auth guard. See 2.2.1.1 / 2.3.2 in the project spec.
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            // Laravel's DatabaseSessionHandler always writes the authenticated
            // id here as "user_id" regardless of guard; not a real FK since the
            // id may belong to tourists, admin_users, or establishment_accounts.
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
