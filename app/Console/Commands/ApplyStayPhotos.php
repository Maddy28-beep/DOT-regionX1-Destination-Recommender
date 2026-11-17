<?php

namespace App\Console\Commands;

use App\Models\Accommodation;
use App\Support\ListingPhotoManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class ApplyStayPhotos extends Command
{
    protected $signature = 'stays:apply-photos {--directory=database/data/listing-photos/stays-research-2026-11-17} {--apply} {--allow-production : Apply on a live server}';
    protected $description = 'Add one reviewed local-test cover to stays without real photos; preserve all existing photos.';

    public function handle(): int
    {
        // Live servers need an explicit opt-in: the project owner approved publishing this batch on 2026-11-17.
        if (! app()->environment(['local', 'testing']) && ! $this->option('allow-production')) {
            $this->error('This photo batch is only applied locally unless you add --allow-production.');
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
            if (($entry['kind'] ?? '') !== 'Accommodation' || blank($entry['slug'] ?? null)
                || isset($seen[$entry['slug']]) || basename($entry['file'] ?? '') !== ($entry['file'] ?? '')
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
        $changes = [];
        DB::transaction(function () use ($entries, $directory, &$changes) {
            foreach ($entries as $entry) {
                $stay = Accommodation::where('slug', $entry['slug'])->lockForUpdate()->first();
                if (! $stay || $stay->name !== $entry['name'] || $stay->location !== $entry['location'] || $stay->archived_at
                    || $stay->photos()->get()->contains(fn ($photo) => ! $photo->isIllustration())) continue;

                $path = 'listings/stays-local-2026-11-17/'.$entry['file'];
                $change = ['listing_id' => $stay->id, 'slug' => $stay->slug, 'path' => $path];
                if ($this->option('apply')) {
                    if (! Storage::disk('public')->put($path, file_get_contents($directory.'/'.$entry['file']))) {
                        throw new \RuntimeException('Could not store '.$entry['file']);
                    }
                    $photo = $stay->photos()->create([
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
            File::put(storage_path('app/stay-photo-import-'.now()->format('Ymd-His-u').'.json'), json_encode($changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        $this->info(count($changes).($this->option('apply') ? ' photos added.' : ' photos ready (dry run).'));
        return self::SUCCESS;
    }
}
