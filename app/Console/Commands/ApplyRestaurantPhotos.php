<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Support\ListingPhotoManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class ApplyRestaurantPhotos extends Command
{
    protected $signature = 'restaurants:apply-photos {--directory=database/data/listing-photos/restaurants-missing-2026-11-24} {--apply} {--allow-production : Apply on a live server (see the note in handle())}';
    protected $description = 'Add one reviewed photo per restaurant without real photos, preserving existing records.';

    public function handle(): int
    {
        // Local and testing runs need no flag. A live server needs --allow-production: the project owner asked on 24 November 2026
        // for these photographs to be published on the live site, knowing their publication rights are not established (each
        // keeps its source and credit). Nothing is written without --apply.
        if (! app()->environment(['local', 'testing']) && ! $this->option('allow-production')) {
            $this->error('This photo batch is applied locally unless you add --allow-production. Nothing changed.');
            return self::FAILURE;
        }
        $directory = realpath(base_path($this->option('directory')));
        if (! $directory || ! is_file($directory.'/manifest.json')) {
            $this->error('Photo manifest not found.');
            return self::FAILURE;
        }
        $manifest = new ListingPhotoManifest($directory);
        $entries = $manifest->entries();
        $seen = [];
        foreach ($entries as $entry) {
            if (($entry['kind'] ?? '') !== 'Restaurant' || blank($entry['slug'] ?? null)
                || isset($seen[$entry['slug']]) || basename($entry['file'] ?? '') !== ($entry['file'] ?? '')
                || str_starts_with($entry['name'] ?? '', 'Sample ')
                || ($entry['verification_status'] ?? '') !== 'approved'
                || ($entry['permission_basis'] ?? '') !== 'team_testing_decision') {
                $this->error('Invalid, duplicate or unapproved local-test entry. Nothing was changed.');
                return self::FAILURE;
            }
            $seen[$entry['slug']] = true;
        }
        if ($problems = $manifest->problems()) {
            foreach ($problems as $problem) $this->error($problem);
            return self::FAILURE;
        }
        // Validate bytes, not filenames: the same photograph must not be assigned twice.
        $hashes = [];
        foreach ($entries as $entry) {
            $hash = hash_file('sha256', $directory.'/'.$entry['file']);
            if (isset($hashes[$hash]) || ! @getimagesize($directory.'/'.$entry['file'])) {
                $this->error('Duplicate or unreadable photograph. Nothing changed.');
                return self::FAILURE;
            }
            $hashes[$hash] = true;
        }
        $existingHashes = [];
        foreach (\App\Models\ListingPhoto::withoutGlobalScopes()->get() as $photo) {
            if ($photo->isIllustration() || ! Storage::disk('public')->exists($photo->path)) continue;
            $existingHashes[hash('sha256', Storage::disk('public')->get($photo->path))] = true;
        }
        $changes = [];
        DB::transaction(function () use ($entries, $directory, $existingHashes, &$changes) {
            foreach ($entries as $entry) {
                $restaurant = Restaurant::where('slug', $entry['slug'])->lockForUpdate()->first();
                if (! $restaurant || $restaurant->name !== $entry['name'] || $restaurant->location !== $entry['location']
                    || $restaurant->archived_at || str_starts_with($restaurant->name, 'Sample ')
                    || $restaurant->photos()->get()->contains(fn ($photo) => ! $photo->isIllustration())) continue;

                if (isset($existingHashes[$entry['sha256']])) continue;
                $path = 'listings/restaurants-missing-local-2026-11-24/'.$entry['file'];
                $change = ['listing_id' => $restaurant->id, 'slug' => $restaurant->slug, 'path' => $path];
                if ($this->option('apply')) {
                    if (Storage::disk('public')->exists($path)
                        && hash('sha256', Storage::disk('public')->get($path)) !== $entry['sha256']) {
                        throw new \RuntimeException('Refusing to replace a different existing photo: '.$path);
                    }
                    if (! Storage::disk('public')->put($path, file_get_contents($directory.'/'.$entry['file']))) {
                        throw new \RuntimeException('Could not store '.$entry['file']);
                    }
                    $photo = $restaurant->photos()->create([
                        'path' => $path, 'category' => $entry['category'], 'sort_order' => -1, 'is_primary' => true,
                        ...array_intersect_key($entry, array_flip(['source_url', 'source_name', 'source_type', 'permission_basis',
                            'permission_ref', 'permission_date', 'licence', 'credit_text', 'show_credit', 'verification_status', 'verified_by', 'verified_at'])),
                    ]);
                    $change['photo_id'] = $photo->id;
                }
                $changes[] = $change;
            }
        });
        if ($this->option('apply') && $changes) {
            File::put(storage_path('app/restaurant-photo-import-'.now()->format('Ymd-His-u').'.json'), json_encode($changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        $this->info(count($changes).($this->option('apply') ? ' photos added.' : ' photos ready (dry run).'));
        return self::SUCCESS;
    }
}
