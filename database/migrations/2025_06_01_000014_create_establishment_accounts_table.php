<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('establishment_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_name', 150);
            $table->string('listing_kind', 30);
            $table->string('claimed_accreditation_number', 50)->nullable();
            $table->string('certificate_file_name', 255)->nullable();
            $table->unsignedBigInteger('matched_listing_id')->nullable();
            $table->string('portal_key', 120)->unique();
            $table->string('email', 100)->unique();
            $table->string('password_hash', 255);
            $table->string('contact_person', 100);
            $table->string('contact_number', 20);
            $table->string('status', 20)->default('pending');
            $table->timestamp('submitted_at')->useCurrent();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establishment_accounts');
    }
};
