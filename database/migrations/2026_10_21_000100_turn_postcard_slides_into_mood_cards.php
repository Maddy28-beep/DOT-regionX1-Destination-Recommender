<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The landing page's "Popular Right Now" carousel becomes "What Davao is known for": five tall mood cards (Islands &
 * Beaches, Highlands & Nature, Wildlife, Culture & Heritage, Food & Dining), each linking to the catalogue that fits
 * it and showing how many places are in it.
 *
 * The cards are still the postcard_slides records, so the DOT admin keeps editing them in the same place. This
 * migration moves the four slides that came with the site onto the new moods, and adds the Food & Dining card.
 *
 * A slide is only changed while it still holds the title it was seeded with, so anything a DOT admin has already
 * edited is left exactly as they wrote it.
 */
return new class extends Migration
{
    /** image_path => [seeded title, new values] */
    private function moods(): array
    {
        return [
            'images/postcards/island-beach.webp' => ['Island beaches', [
                'kicker' => 'sun-warmed shores', 'title' => 'Islands & Beaches', 'cta_label' => 'Explore', 'sort_order' => 1,
            ]],
            'images/postcards/mountain-peak.webp' => ['Mount Apo Natural Park', [
                'kicker' => 'misty and green', 'title' => 'Highlands & Nature', 'cta_label' => 'Explore',
                'cta_url' => '/destinations?type=Nature+%26+Adventure', 'sort_order' => 2,
            ]],
            'images/postcards/wildlife.webp' => ['Wildlife', [
                'kicker' => 'wild and protected', 'title' => 'Wildlife', 'cta_label' => 'Explore', 'sort_order' => 3,
            ]],
            'images/postcards/cultural-heritage.webp' => ['Cultural heritage', [
                'kicker' => 'stories of the south', 'title' => 'Culture & Heritage', 'cta_label' => 'Explore', 'sort_order' => 4,
            ]],
        ];
    }

    public function up(): void
    {
        foreach ($this->moods() as $image => [$seededTitle, $values]) {
            DB::table('postcard_slides')
                ->where('image_path', $image)->where('title', $seededTitle)
                ->update($values + ['updated_at' => now()]);
        }

        if (! DB::table('postcard_slides')->where('cta_url', '/restaurants')->exists()) {
            DB::table('postcard_slides')->insert([
                'kicker' => 'a taste of Davao',
                'title' => 'Food & Dining',
                'location' => 'Davao Region',
                'cta_label' => 'Explore',
                'cta_url' => '/restaurants',
                'image_path' => 'images/postcards/food-dining.webp',
                'thumb_path' => 'images/postcards/food-dining.webp',
                'alt_text' => 'Grilled fish, prawns and rice on a wooden table at a hillside restaurant at sunset',
                'focal_x' => 50,
                'focal_y' => 50,
                'sort_order' => 5,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('postcard_slides')->where('image_path', 'images/postcards/food-dining.webp')->delete();

        $original = [
            'images/postcards/cultural-heritage.webp' => ['Culture & Heritage', ['kicker' => 'living traditions', 'title' => 'Cultural heritage', 'cta_label' => 'Explore cultural heritage →', 'sort_order' => 1]],
            'images/postcards/mountain-peak.webp' => ['Highlands & Nature', ['kicker' => 'the roof of the philippines', 'title' => 'Mount Apo Natural Park', 'cta_label' => 'View destination →', 'cta_url' => '/destinations/mount-apo-natural-park', 'sort_order' => 2]],
            'images/postcards/wildlife.webp' => ['Wildlife', ['kicker' => 'wild and protected', 'title' => 'Wildlife', 'cta_label' => 'Explore wildlife →', 'sort_order' => 3]],
            'images/postcards/island-beach.webp' => ['Islands & Beaches', ['kicker' => 'sun and sea', 'title' => 'Island beaches', 'cta_label' => 'Explore beaches →', 'sort_order' => 4]],
        ];

        foreach ($original as $image => [$currentTitle, $values]) {
            DB::table('postcard_slides')
                ->where('image_path', $image)->where('title', $currentTitle)
                ->update($values + ['updated_at' => now()]);
        }
    }
};
