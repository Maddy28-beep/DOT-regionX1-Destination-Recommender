<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What visitors type into the search boxes, counted, so the landing page's "Popular searches" can show what is
 * really searched for.
 *
 * Anonymous by design: one row per (term, catalogue) with a running count and the last time it was searched. No
 * IP address, account, session or per-search timestamp is stored, and App\Support\SearchLog drops anything that
 * looks personal (an email address, a phone number) and any search that found nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('searched_terms', function (Blueprint $table) {
            $table->id();
            $table->string('term', 60);          // lower-cased, whitespace collapsed: what rows are counted by
            $table->string('label', 60);         // the first spelling seen, shown on the chip
            $table->string('purpose', 24);       // which catalogue it was searched in (destinations, restaurants, ...)
            $table->unsignedInteger('searches')->default(0);
            $table->timestamp('last_searched_at')->nullable();
            $table->unique(['term', 'purpose']);
            $table->index(['searches', 'last_searched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('searched_terms');
    }
};
