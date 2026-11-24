<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Some restaurants list two numbers or a mobile number as well, for example "(082) 305-9232 / 321-8316", which is
     * longer than the 20 characters the column allowed. PostgreSQL refuses such a value; SQLite does not, so only the
     * live server noticed.
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('contact_number', 80)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Left wide: narrowing it again could cut off numbers that are now stored.
    }
};
