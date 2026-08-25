<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\ListingPhoto;
use App\Models\Package;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ListingPhotoSeeder extends Seeder
{
    /**
     * Demo placeholder photos (gradient SVG + label) for a handful of listings,
     * so the gallery/carousel/lightbox UI has something real to render before
     * establishments upload their own photos.
     */
    public function run(): void
    {
        $this->seedFor(Destination::where('slug', 'samal-island')->first(), 'destination', [
            ['label' => 'Beachfront View', 'category' => 'Views', 'colors' => ['#0b6b4f', '#14876a']],
            ['label' => 'Island Hopping', 'category' => 'General', 'colors' => ['#1d6fa5', '#0b4d75']],
            ['label' => 'Sunset at Samal', 'category' => 'Views', 'colors' => ['#c9932f', '#916b19']],
            ['label' => 'Water Sports', 'category' => 'Amenities', 'colors' => ['#ff6b35', '#e2551f']],
        ]);

        $this->seedFor(Destination::where('slug', 'eden-nature-park')->first(), 'destination', [
            ['label' => 'Nature Trail', 'category' => 'General', 'colors' => ['#2f9e8f', '#1b6b60']],
            ['label' => 'Zipline Adventure', 'category' => 'Amenities', 'colors' => ['#7a4fc9', '#4f2f96']],
            ['label' => 'Cool Climate Gardens', 'category' => 'Views', 'colors' => ['#0b6b4f', '#14876a']],
        ]);

        $this->seedFor(Accommodation::where('slug', 'bluejaz-beach-resort')->first(), 'accommodation', [
            ['label' => 'Resort Exterior', 'category' => 'Exterior', 'colors' => ['#1d6fa5', '#0b4d75']],
            ['label' => 'Swimming Pool', 'category' => 'Amenities', 'colors' => ['#0b6b4f', '#14876a']],
            ['label' => 'Standard Room', 'category' => 'Interior', 'colors' => ['#c9932f', '#916b19']],
            ['label' => 'Beachfront', 'category' => 'Views', 'colors' => ['#7a4fc9', '#4f2f96']],
        ]);

        $this->seedFor(Package::where('slug', 'mount-apo-3-day-summit-trek')->first(), 'package', [
            ['label' => 'Summit View', 'category' => 'Views', 'colors' => ['#2f9e8f', '#1b6b60']],
            ['label' => 'Base Camp', 'category' => 'General', 'colors' => ['#c9932f', '#916b19']],
            ['label' => 'Trail Ascent', 'category' => 'General', 'colors' => ['#0b6b4f', '#14876a']],
        ]);
    }

    private function seedFor($listing, string $kind, array $photos): void
    {
        if (! $listing) {
            return;
        }

        foreach ($photos as $i => $p) {
            $filename = "listings/{$kind}/{$listing->id}/".Str::random(12).'.svg';
            Storage::disk('public')->put($filename, $this->svg($p['label'], $p['colors']));

            ListingPhoto::create([
                'listing_kind' => $kind,
                'listing_id' => $listing->id,
                'path' => $filename,
                'category' => $p['category'],
                'sort_order' => $i,
                'is_primary' => $i === 0,
            ]);
        }
    }

    private function svg(string $label, array $colors): string
    {
        [$from, $to] = $colors;
        $gradId = 'g'.Str::random(6);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600" viewBox="0 0 800 600">
    <defs>
        <linearGradient id="{$gradId}" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="{$from}"/>
            <stop offset="100%" stop-color="{$to}"/>
        </linearGradient>
    </defs>
    <rect width="800" height="600" fill="url(#{$gradId})"/>
    <text x="50%" y="50%" text-anchor="middle" dominant-baseline="middle"
          font-family="system-ui, sans-serif" font-size="34" font-weight="700"
          fill="rgba(255,255,255,0.92)">{$label}</text>
</svg>
SVG;
    }
}
