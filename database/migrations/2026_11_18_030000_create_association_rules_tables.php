<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Precomputed "people who visit A also visit B" pairs (see AssociationRuleBuilder). Counting them from every recorded
 * trip on each lookup gets slower as the trips pile up; reading them from here does not. Nothing reads these tables
 * until the association_rules_table switch is turned on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('association_rules', function (Blueprint $table) {
            $table->id();
            // Which mix of trips was counted: all, real, itinerary or demo (the same choices as the admin page).
            $table->string('source_set', 12);
            $table->string('a_kind', 30);
            $table->unsignedBigInteger('a_id');
            $table->string('b_kind', 30);
            $table->unsignedBigInteger('b_id');
            $table->unsignedInteger('co_count');   // trips that contain both A and B
            $table->unsignedInteger('ant_count');  // trips that contain A

            $table->unique(['source_set', 'a_kind', 'a_id', 'b_kind', 'b_id'], 'association_rules_pair_unique');
        });

        // How many recorded visits each listing has (every source except trial ones). The planner weighs a destination
        // by its share of the most visited candidate, and counting that from every visit grows with the data.
        Schema::create('association_visit_counts', function (Blueprint $table) {
            $table->string('listing_kind', 30);
            $table->unsignedBigInteger('listing_id');
            $table->unsignedInteger('visits');

            $table->primary(['listing_kind', 'listing_id']);
        });

        Schema::create('association_rule_builds', function (Blueprint $table) {
            $table->string('source_set', 12)->primary();
            $table->unsignedInteger('total_transactions');  // trips counted; support is measured against this
            $table->unsignedInteger('rule_count');
            $table->timestamp('built_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('association_rule_builds');
        Schema::dropIfExists('association_visit_counts');
        Schema::dropIfExists('association_rules');
    }
};
