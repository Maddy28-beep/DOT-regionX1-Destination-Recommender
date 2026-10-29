<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two labels that keep sample material from being mistaken for the real thing.
 *
 * accreditation_records.is_placeholder: the number on this record is ours (DOT-XI-2025-00xx, SAMPLE-0001...), not an
 * official DOT number. The listing it belongs to is shown as sample data and never as DOT-verified.
 *
 * exit_surveys.batch: which generator run wrote a simulated survey, so one run can be listed or removed without
 * touching the others. Null for every survey that exists today.
 *
 * Nothing is hidden or removed by this migration: planning, recommendations and Apriori read the same rows as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accreditation_records', function (Blueprint $table) {
            $table->boolean('is_placeholder')->default(false);
        });

        Schema::table('exit_surveys', function (Blueprint $table) {
            $table->string('batch', 60)->nullable()->index();
        });

        $this->backfill();
    }

    /** The placeholder numbers the accreditation seeder has always written. Safe to run twice. */
    public function backfill(): int
    {
        return DB::table('accreditation_records')
            ->where('accreditation_number', 'like', 'DOT-XI-%')
            ->update(['is_placeholder' => true]);
    }

    public function down(): void
    {
        Schema::table('exit_surveys', function (Blueprint $table) {
            $table->dropIndex(['batch']);
            $table->dropColumn('batch');
        });

        Schema::table('accreditation_records', function (Blueprint $table) {
            $table->dropColumn('is_placeholder');
        });
    }
};
