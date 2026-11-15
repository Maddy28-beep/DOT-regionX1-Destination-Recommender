<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('exit_survey_stays', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('exit_survey_id')->constrained('exit_surveys')->cascadeOnDelete();
            $table->foreignId('accommodation_id')->nullable()->constrained('accommodations')->nullOnDelete();
            $table->string('reported_kind', 30);
            $table->string('place_name')->nullable();
            $table->text('locality')->nullable();
            $table->string('directory_status', 30);
            $table->index(['reported_kind', 'directory_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exit_survey_stays');
    }
};
