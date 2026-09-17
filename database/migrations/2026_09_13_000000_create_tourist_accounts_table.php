<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A deliberately minimal, OPTIONAL account so a tourist can save an
 * itinerary/favorites across devices -- distinct in both name and shape from
 * the `tourists` table dropped in 2026_08_30_130200_drop_tourist_accounts.php
 * for RA 10173 compliance. That table held real identity (full name, email,
 * nationality, age range, contact number); this one holds only an alias and
 * a password hash, exactly like the "portal_key" spirit of establishment
 * accounts but with no business/personal data at all. Trip planning itself
 * still needs none of this -- it exists purely so someone who WANTS to keep
 * a plan has somewhere optional to put it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tourist_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('alias', 30)->unique();
            $table->string('password_hash');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tourist_accounts');
    }
};
