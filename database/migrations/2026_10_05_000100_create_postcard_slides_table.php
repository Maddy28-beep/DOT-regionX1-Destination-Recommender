<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The homepage "Popular Right Now" carousel used to be four slides typed into
 * welcome.blade.php, each with a category label and nothing else: no place, no
 * link, no way to keep a subject in frame when the crop changed.
 *
 * A slide is now a record: caption (kicker, name, location), a call to action,
 * the photo with its generated variants, required alt text, and a focal point
 * (focal_x / focal_y, 0-100) that drives both object-position and the zoom's
 * transform-origin so faces and instruments are not cropped out.
 *
 * image_path is the full-size WebP; image_srcset holds the smaller widths as
 * [{"w": 640, "path": "..."}]; thumb_path is the ~192px strip thumbnail. All
 * three are web-root-relative paths, so slides shipped in public/images and
 * slides uploaded through the admin (public/storage) resolve the same way.
 *
 * The four slides that were hardcoded are carried over so the homepage is not
 * empty after migrating. Where a photo shows a category rather than a named
 * place, the slide links to that category's filtered listing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postcard_slides', function (Blueprint $table) {
            $table->id();
            $table->string('kicker', 60);
            $table->string('title', 100);
            $table->string('location', 100)->nullable();
            $table->string('cta_label', 60);
            $table->string('cta_url', 255);
            $table->string('image_path', 255);
            $table->json('image_srcset')->nullable();
            $table->string('thumb_path', 255);
            $table->string('alt_text', 255);
            $table->unsignedTinyInteger('focal_x')->default(50);
            $table->unsignedTinyInteger('focal_y')->default(50);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        $now = now();

        $slides = [
            [
                'kicker' => 'living traditions',
                'title' => 'Cultural heritage',
                'location' => 'Davao Region',
                'cta_label' => 'Explore cultural heritage →',
                'cta_url' => '/destinations?type=Cultural+Heritage',
                'image_path' => 'images/postcards/cultural-heritage.webp',
                'thumb_path' => 'images/postcards/cultural-heritage-thumb.webp',
                'alt_text' => "T'boli performer in traditional dress playing a kudyapi in front of a native hut",
                // The performer sits left of centre.
                'focal_x' => 30,
                'focal_y' => 45,
            ],
            [
                'kicker' => 'the roof of the philippines',
                'title' => 'Mount Apo Natural Park',
                'location' => 'Davao del Sur',
                'cta_label' => 'View destination →',
                'cta_url' => '/destinations/mount-apo-natural-park',
                'image_path' => 'images/postcards/mountain-peak.webp',
                'thumb_path' => 'images/postcards/mountain-peak-thumb.webp',
                'alt_text' => 'Mount Apo summit rising above the Davao Region foothills',
                'focal_x' => 50,
                'focal_y' => 40,
            ],
            [
                'kicker' => 'wild and protected',
                'title' => 'Wildlife',
                'location' => 'Davao Region',
                'cta_label' => 'Explore wildlife →',
                'cta_url' => '/destinations?type=Wildlife',
                'image_path' => 'images/postcards/wildlife.webp',
                'thumb_path' => 'images/postcards/wildlife-thumb.webp',
                'alt_text' => 'Aerial view of a forested bay and coastline in Davao Region',
                'focal_x' => 50,
                'focal_y' => 50,
            ],
            [
                'kicker' => 'sun and sea',
                'title' => 'Island beaches',
                'location' => 'Davao Region',
                'cta_label' => 'Explore beaches →',
                'cta_url' => '/destinations?type=Beach+%26+Leisure',
                'image_path' => 'images/postcards/island-beach.webp',
                'thumb_path' => 'images/postcards/island-beach-thumb.webp',
                'alt_text' => 'Aerial view of a turquoise island coastline in Davao Region',
                'focal_x' => 50,
                'focal_y' => 50,
            ],
        ];

        foreach ($slides as $i => $slide) {
            DB::table('postcard_slides')->insert($slide + [
                'sort_order' => $i + 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('postcard_slides');
    }
};
