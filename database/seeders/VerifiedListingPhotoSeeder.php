<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class VerifiedListingPhotoSeeder extends Seeder
{
    public function run(): void
    {
        $directory = database_path('data/listing-photos');
        $entries = json_decode(file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach ($entries as $entry) {
            // The image files are kept out of git (see database/data/listing-photos/README.md).
            if (! is_file($directory.'/'.$entry['file'])) {
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
                'source_url' => $entry['source_url'],
                'source_name' => $entry['source_name'],
            ]);
        }
    }
}
