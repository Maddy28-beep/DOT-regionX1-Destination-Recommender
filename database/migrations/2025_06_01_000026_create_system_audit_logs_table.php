<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('admin_id')->constrained('admin_users')->cascadeOnDelete();
            $table->string('action', 100);
            $table->string('affected_table', 80);
            $table->string('affected_record_id', 50)->nullable();
            $table->string('description', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_audit_logs');
    }
};
