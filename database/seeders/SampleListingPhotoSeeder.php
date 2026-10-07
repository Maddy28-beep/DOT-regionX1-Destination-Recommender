<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class SampleListingPhotoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Destination', 'Accommodation', 'Restaurant', 'Package', 'SouvenirCenter', 'TourOperator'] as $kind) {
            $class = 'App\\Models\\'.$kind;
            foreach ($class::with('photos')->get() as $listing) {
                if ($listing->photos->contains(fn ($photo) => ! str_ends_with(strtolower($photo->path), '.svg'))) {
                    continue;
                }
                $asset = match ($kind) {
                    'Accommodation' => 'accommodations',
                    'Restaurant' => 'restaurants',
                    'SouvenirCenter' => 'souvenirs',
                    'TourOperator' => 'tour-operators',
                    'Package' => 'packages',
                    default => $this->destinationAsset($listing),
                };
                $path = 'listings/samples/'.$asset.'.webp';
                if (! Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->put($path, file_get_contents(public_path('images/samples/'.$asset.'.webp')));
                }
                $listing->photos()->firstOrCreate(['path' => $path], [
                    'category' => 'Sample image', 'sort_order' => 0, 'is_primary' => false,
                ]);
            }
        }
    }

    private function destinationAsset($listing): string
    {
        $category = strtolower($listing->type.' '.$listing->name);
        return match (true) {
            str_contains($category, 'eagle') => 'wildlife',
            (bool) preg_match('/beach|island|surf|marine|cove|clam/', $category) => 'packages',
            (bool) preg_match('/cultur|heritage|museum|church|histor/', $category) => 'culture',
            (bool) preg_match('/spa|wellness|convention|golf|club/', $category) => 'accommodations',
            default => 'landscape',
        };
    }
}
