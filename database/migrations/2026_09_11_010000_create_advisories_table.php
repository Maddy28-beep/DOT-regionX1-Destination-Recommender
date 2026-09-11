<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOT Region XI asked for a manual way to post an advisory against a specific
 * place ("Mt. Apo is closed this season") or the whole platform (a general
 * notice not tied to any one listing), separate from is_accredited/archived_at
 * -- an advisory is a temporary, admin-authored notice about conditions, not a
 * change to whether the place is DOT-accredited or archived.
 *
 * listing_kind/listing_id are nullable and use the same polymorphic pattern
 * as reviews and accreditation_records; both null means a general advisory
 * shown platform-wide rather than on one listing's page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisories', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->string('message', 1000);
            $table->string('severity', 20)->default('warning');
            $table->string('listing_kind', 30)->nullable();
            $table->unsignedBigInteger('listing_id')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->foreignUuid('admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['listing_kind', 'listing_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisories');
    }
};
