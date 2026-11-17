<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Replace only the bundled island placeholder; preserve admin uploads and all CTA settings.
        DB::table('postcard_slides')->where('image_path', 'images/postcards/wildlife.webp')->where('title', 'Wildlife')->update([
            'image_path' => 'images/postcards/philippine-eagle-shankar.jpg',
            'thumb_path' => 'images/postcards/philippine-eagle-shankar.jpg',
            'image_srcset' => null,
            'alt_text' => 'Philippine eagle photographed in Davao by shankar s. (CC BY 2.0)',
            'focal_x' => 35, 'focal_y' => 40,
        ]);
    }

    public function down(): void
    {
        DB::table('postcard_slides')->where('image_path', 'images/postcards/philippine-eagle-shankar.jpg')->where('title', 'Wildlife')->update([
            'image_path' => 'images/postcards/wildlife.webp',
            'thumb_path' => 'images/postcards/wildlife-thumb.webp',
            'alt_text' => 'Aerial view of a forested bay and coastline in Davao Region',
            'focal_x' => 50, 'focal_y' => 50,
        ]);
    }
};
