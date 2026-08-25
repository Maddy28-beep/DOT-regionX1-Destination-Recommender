<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accreditation_records', function (Blueprint $table) {
            $table->id();
            $table->string('listing_kind', 30);
            $table->unsignedBigInteger('listing_id');
            $table->string('accreditation_number', 50)->unique();
            $table->string('status', 30);
            $table->date('issue_date')->nullable();
            $table->date('expiration_date')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['listing_kind', 'listing_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accreditation_records');
    }
};
