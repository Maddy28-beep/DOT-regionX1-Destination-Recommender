<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'destinations',
        'accommodations',
        'restaurants',
        'souvenir_centers',
        'packages',
        'tour_operators',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('website_url')->nullable();
                $blueprint->string('facebook_url')->nullable();
                $blueprint->string('instagram_url')->nullable();
                $blueprint->string('tiktok_url')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn(['website_url', 'facebook_url', 'instagram_url', 'tiktok_url']);
            });
        }
    }
};
