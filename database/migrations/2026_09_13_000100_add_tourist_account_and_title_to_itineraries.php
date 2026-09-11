<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a tourist "save" an itinerary permanently by claiming an existing row
 * rather than duplicating the itinerary/itinerary_items tables into a
 * separate Saved* schema. An itinerary with tourist_account_id still null is
 * unaffected -- it behaves exactly as it does today, reachable only through
 * the anonymous session pointers in TripPlannerController.
 *
 * `updated_at` is added (and the model's UPDATED_AT override removed) so the
 * "last touched" a tourist dashboard shows has something real to read --
 * claiming an itinerary is itself a save() call, so it naturally gets a
 * timestamp for free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->foreignUuid('tourist_account_id')->nullable()->after('package_id')
                ->constrained('tourist_accounts')->nullOnDelete();
            $table->string('title', 150)->nullable()->after('tourist_account_id');
            $table->timestamp('updated_at')->nullable()->after('generated_at');
        });
    }

    public function down(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tourist_account_id');
            $table->dropColumn(['title', 'updated_at']);
        });
    }
};
