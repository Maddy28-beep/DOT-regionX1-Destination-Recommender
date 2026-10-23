<?php

namespace Database\Seeders;

use App\Support\ListingPhotoManifest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Copies the manifest's pictures onto listings, but only the ones whose verification_status is `approved`, and
 * only when the entry carries its evidence (see ListingPhotoManifest): who allowed it, on what basis, the credit to
 * show, and who checked it shows the right place. An entry that is not approved, or whose record is incomplete, is
 * left out and nothing about it is published. Safe to run repeatedly.
 */
class VerifiedListingPhotoSeeder extends Seeder
{
    /** Where manifest.json and the images are; null means database/data/listing-photos. Set by tests. */
    public ?string $directory = null;

    public function run(): void
    {
        $manifest = $this->directory ? new ListingPhotoManifest($this->directory) : ListingPhotoManifest::default();
        $directory = $manifest->directory();

        foreach ($manifest->approved() as $entry) {
            $problems = $manifest->problemsWith($entry);
            if ($problems !== []) {
                $this->command?->warn('Skipped, incomplete record: '.implode('; ', $problems));

                continue;
            }

            $class = 'App\\Models\\'.$entry['kind'];
            $listing = $class::find($entry['id']);
            // IDs alone are not sufficient on a different installation.
            if (! $listing || $listing->name !== $entry['name']) {
                continue;
            }

            $path = 'listings/verified/'.$entry['file'];
            // Keep existing establishment photographs and primary choices.
            if ($listing->photos()->where('category', '!=', 'Sample image')->where('path', 'not like', '%.svg')->where('path', '!=', $path)->exists()) {
                continue;
            }

            Storage::disk('public')->put($path, file_get_contents($directory.'/'.$entry['file']));
            $listing->photos()->updateOrCreate(['path' => $path], [
                'category' => 'General',
                'is_primary' => true,
                'sort_order' => -1,
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
        }
    }
}
