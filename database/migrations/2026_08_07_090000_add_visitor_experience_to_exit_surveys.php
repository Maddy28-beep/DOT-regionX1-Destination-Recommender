<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exit_surveys', function (Blueprint $table) {
            $table->string('residency_type', 30)->nullable()->after('submitted_at');
            $table->string('visitor_type', 30)->nullable()->after('residency_type');
            $table->string('origin', 150)->nullable()->after('visitor_type');
        });

        Schema::create('exit_survey_visits', function (Blueprint $table) {
            $table->id();
            $table->uuid('exit_survey_id');
            $table->string('listing_kind', 30);
            $table->unsignedBigInteger('listing_id');

            $table->foreign('exit_survey_id')->references('id')->on('exit_surveys')->cascadeOnDelete();
            $table->index(['listing_kind', 'listing_id']);
        });

        Schema::create('exit_survey_activities', function (Blueprint $table) {
            $table->id();
            $table->uuid('exit_survey_id');
            $table->string('activity', 50);

            $table->foreign('exit_survey_id')->references('id')->on('exit_surveys')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exit_survey_activities');
        Schema::dropIfExists('exit_survey_visits');

        Schema::table('exit_surveys', function (Blueprint $table) {
            $table->dropColumn(['residency_type', 'visitor_type', 'origin']);
        });
    }
};
