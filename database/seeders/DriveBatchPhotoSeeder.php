<?php

namespace Database\Seeders;

use App\Support\ListingPhotoManifest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Imports the 2026-11 team photo batch (database/data/listing-photos/drive-2026-11) for beta testing.
 *
 * Listings are found by EXACT NAME across destinations, accommodations, restaurants, souvenir centers, tour operators
 * and packages, because ids differ between installations. A name that matches no listing, or more than one, is skipped
 * and reported, so a photo is never attached to a guess. The first real photo of a listing becomes its primary picture;
 * later ones are added behind it, and an existing real photo always stays primary. Safe to run repeatedly (a picture is
 * keyed by its stored path). Every row carries permission_basis `team_testing_decision`, so the batch can be found and
 * re-reviewed later.
 */
class DriveBatchPhotoSeeder extends Seeder
{
    private const KINDS = ['Destination', 'Accommodation', 'Restaurant', 'SouvenirCenter', 'TourOperator', 'Package'];

    /** Where manifest.json and the images are; null means the 2026-11 batch folder. Set by tests. */
    public ?string $directory = null;

    public function run(): void
    {
        $manifest = new ListingPhotoManifest($this->directory ?? database_path('data/listing-photos/drive-2026-11'));
        $directory = $manifest->directory();
        $done = 0;
        $skipped = 0;

        foreach ($manifest->approved() as $entry) {
            $problems = $manifest->problemsWith($entry);
            if ($problems !== []) {
                $this->command?->warn('Skipped, incomplete record: '.implode('; ', $problems));
                $skipped++;

                continue;
            }

            $matches = [];
            foreach (self::KINDS as $kind) {
                $class = 'App\\Models\\'.$kind;
                foreach ($class::where('name', $entry['name'])->get() as $listing) {
                    $matches[] = $listing;
                }
            }
            if (count($matches) !== 1) {
                $this->command?->warn((count($matches) === 0 ? 'No listing named' : 'More than one listing named').' "'.$entry['name'].'"; '.$entry['file'].' skipped.');
                $skipped++;

                continue;
            }
            $listing = $matches[0];

            $path = 'listings/batch-2026-11/'.$entry['file'];
            Storage::disk('public')->put($path, file_get_contents($directory.'/'.$entry['file']));

            $others = $listing->photos()->where('path', '!=', $path);
            $hasRealPhoto = (clone $others)->where('category', '!=', 'Sample image')->where('path', 'not like', '%.svg')->exists();
            $order = (int) (clone $others)->max('sort_order');

            $listing->photos()->updateOrCreate(['path' => $path], [
                'category' => 'General',
                'is_primary' => ! $hasRealPhoto,
                'sort_order' => $hasRealPhoto ? $order + 1 : -1,
                'source_url' => $entry['source_url'] ?? null,
                'source_name' => $entry['source_name'] ?? null,
                'source_type' => $entry['source_type'],
                'permission_basis' => $entry['permission_basis'],
                'permission_ref' => $entry['permission_ref'],
                'permission_date' => $entry['permission_date'],
                'licence' => $entry['licence'] ?? null,
                'credit_text' => $entry['credit_text'],
                'show_credit' => $entry['show_credit'] ?? true,
                'verification_status' => 'approved',
                'verified_by' => $entry['verified_by'],
                'verified_at' => $entry['verified_at'],
            ]);
            $done++;
        }

        $this->command?->info("Photo batch: {$done} imported, {$skipped} skipped.");
    }
}
