<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who allowed a photograph to be shown, and what is shown beside it.
 *
 * source_url / source_name already say where a picture came from. These columns say on what basis it may be
 * shown (permission_basis, permission_ref, permission_date, licence), who checked that it shows the right place
 * (verification_status, verified_by, verified_at), and the credit line shown with it (credit_text, show_credit).
 * Nothing is hidden or removed by adding them: existing rows keep showing exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_photos', function (Blueprint $table) {
            $table->string('source_type', 30)->nullable();
            $table->string('permission_basis', 40)->nullable();
            $table->string('permission_ref', 160)->nullable();
            $table->date('permission_date')->nullable();
            $table->string('licence', 120)->nullable();
            $table->string('credit_text', 255)->nullable();
            $table->boolean('show_credit')->default(true);
            $table->string('verification_status', 20)->default('unverified');
            $table->string('verified_by', 100)->nullable();
            $table->timestamp('verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('listing_photos', function (Blueprint $table) {
            $table->dropColumn([
                'source_type', 'permission_basis', 'permission_ref', 'permission_date', 'licence',
                'credit_text', 'show_credit', 'verification_status', 'verified_by', 'verified_at',
            ]);
        });
    }
};
