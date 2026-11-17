<?php

namespace Database\Seeders;

use App\Models\ListingPhoto;
use App\Models\Restaurant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Publishes explicit, per-listing illustrations without replacing genuine photographs. */
class DiningIllustrationSeeder extends Seeder
{
    public const DIRECTORY = 'images/dining-illustrations-v1';
    public const STORAGE_PREFIX = 'listings/illustrations/dining-20261117/';
    public const SOURCE = 'ExploreDVO AI-generated dining illustration, 2026-11-17';

    public ?string $directory = null;

    public function run(): void
    {
        $directory = $this->directory ?? public_path(self::DIRECTORY);
        $entries = json_decode(file_get_contents($directory.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $seenIds = $seenHashes = [];

        // Validate every asset before writing any metadata or files.
        foreach ($entries as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            if ($id < 1 || ($entry['file'] ?? '') !== 'restaurant-'.$id.'.webp'
                || isset($seenIds[$id]) || empty($entry['name']) || empty($entry['slug'])) {
                throw new RuntimeException('Invalid or duplicate dining illustration identity.');
            }
            $file = $directory.'/'.$entry['file'];
            $hash = is_file($file) ? hash_file('sha256', $file) : null;
            if (!$hash || $hash !== ($entry['sha256'] ?? null) || isset($seenHashes[$hash])) {
                throw new RuntimeException('Missing, changed or duplicate dining artwork: '.$id);
            }
            $size = getimagesize($file);
            if (!$size || $size[0] < 640 || $size[1] < 400 || $size['mime'] !== 'image/webp') {
                throw new RuntimeException('Invalid dining image format or size: '.$id);
            }
            $seenIds[$id] = $seenHashes[$hash] = true;
        }

        DB::transaction(function () use ($entries, $directory) {
            foreach ($entries as $entry) {
                $restaurant = Restaurant::whereKey($entry['id'])->lockForUpdate()->first();
                if (!$restaurant || $restaurant->name !== $entry['name'] || $restaurant->slug !== $entry['slug']) {
                    throw new RuntimeException('Dining listing identity changed: '.$entry['id']);
                }
                $cover = $restaurant->coverPhoto();
                if ($cover && !$cover->isIllustration()) {
                    $this->command?->line('Kept genuine photo: '.$restaurant->id);
                    continue;
                }

                $path = self::STORAGE_PREFIX.$entry['file'];
                $disk = Storage::disk('public');
                $bytes = file_get_contents($directory.'/'.$entry['file']);
                if ($disk->exists($path)) {
                    if (hash('sha256', $disk->get($path)) !== $entry['sha256']) {
                        throw new RuntimeException('Existing artwork differs; refusing overwrite: '.$path);
                    }
                } elseif (!$disk->put($path, $bytes)) {
                    throw new RuntimeException('Could not store artwork: '.$path);
                }

                $restaurant->photos()->firstOrCreate(['path' => $path], [
                    'category' => ListingPhoto::SAMPLE_CATEGORY,
                    'amenity' => null,
                    'sort_order' => -100,
                    'is_primary' => false,
                    'source_name' => self::SOURCE,
                    'source_type' => 'ai_generated',
                    'permission_basis' => 'generated_illustration',
                    'show_credit' => false,
                    'verification_status' => 'unverified',
                ]);
            }
        });
    }
}
