<?php

namespace App\Console\Commands;

use App\Models\Accommodation;
use App\Models\Restaurant;
use App\Models\Region;
use App\Support\ListingPhotoManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportSixteenRealLocalRestaurants extends Command
{
    protected $signature = 'restaurants:import-sixteen-real-local {--apply : Add the reviewed catalogue batch} {--allow-production : Apply on a live server (see the note in handle())}';

    protected $description = 'Add the reviewed batch of 16 real restaurants, with no claimed DOT accreditation (dry run by default).';

    public const DIRECTORY = 'database/data/listing-photos/real-restaurants-16-2026-11-25';

    private function identity(string $name): string
    {
        $name = preg_replace('/\s*\[SAMPLE\]\s*$/i', '', $name);

        return preg_replace('/[^a-z0-9]/', '', Str::ascii(mb_strtolower($name)));
    }

    public function handle(): int
    {
        // Local and testing environments need no flag. A live server needs --allow-production: the project owner asked on 25 November 2026
        // for these places and photographs to be published on the live site, knowing the publication rights of the photographs are not
        // established (each photograph keeps its source and credit). Nothing is written without --apply, and the dry run shows the plan.
        if (! app()->environment(['local', 'testing']) && ! $this->option('allow-production')) {
            $this->error('This batch is applied locally unless you add --allow-production. Nothing changed.');

            return self::FAILURE;
        }

        $directory = base_path(self::DIRECTORY);
        $manifest = new ListingPhotoManifest($directory);
        $entries = $manifest->entries();
        $problems = $manifest->problems();
        if (count($entries) !== 16 || $problems) {
            foreach ($problems ?: ['The reviewed batch must contain 16 entries.'] as $problem) $this->error($problem);

            return self::FAILURE;
        }

        $plan = [];
        $seen = [];
        $existing = Restaurant::with('photos')->get();
        $stays = Accommodation::all();
        foreach ($entries as $entry) {
            $keys = array_unique(array_map($this->identity(...), [$entry['name'], ...($entry['aliases'] ?? [])]));
            $region = Region::where('name', $entry['region'])->first();
            $matches = $existing->filter(fn ($d) => in_array($this->identity($d->name), $keys, true) || $d->slug === $entry['slug']);
            $restaurant = $matches->first();
            $stayMatch = $stays->contains(fn ($stay) => in_array($this->identity($stay->name), $keys, true));
            $image = @getimagesize($directory.'/'.$entry['file']);
            if (! $region || array_intersect($keys, array_keys($seen)) || $matches->count() > 1 || $stayMatch
                || basename($entry['file']) !== $entry['file'] || ! $image || min($image[0], $image[1]) < 150
                || ! is_numeric($entry['latitude'] ?? null) || ! is_numeric($entry['longitude'] ?? null)
                || $entry['latitude'] < 6.9 || $entry['latitude'] > 7.7
                || $entry['longitude'] < 125.1 || $entry['longitude'] > 125.8
                || blank($entry['coordinate_source'] ?? null)
                || $entry['permission_basis'] !== 'team_testing_decision' || $entry['verification_status'] !== 'approved'
                || ($restaurant && ($restaurant->region_id !== $region->id || $restaurant->isArchived()
                    || str_starts_with($restaurant->name, 'Sample ')
                    || ! in_array($this->identity($restaurant->name), $keys, true)))) {
                $this->error('Ambiguous restaurant, existing stay or invalid local entry: '.$entry['name'].'. Nothing changed.');

                return self::FAILURE;
            }
            foreach ($keys as $key) $seen[$key] = true;
            $needsPhoto = ! $restaurant?->photos->contains(fn ($photo) => ! $photo->isIllustration());
            $plan[] = compact('entry', 'region', 'restaurant', 'needsPhoto');
        }

        $new = count(array_filter($plan, fn ($p) => ! $p['restaurant']));
        $photos = count(array_filter($plan, fn ($p) => $p['needsPhoto']));
        $this->info("{$new} restaurants and {$photos} photos ready.");
        if (! $this->option('apply')) {
            $this->line('Dry run: nothing changed.');

            return self::SUCCESS;
        }

        $changes = [];
        DB::transaction(function () use ($plan, $directory, &$changes) {
            foreach ($plan as $p) {
                ['entry' => $entry, 'region' => $region, 'restaurant' => $restaurant, 'needsPhoto' => $needsPhoto] = $p;
                if (! $restaurant) {
                    $restaurant = Restaurant::create([
                        ...array_intersect_key($entry, array_flip(['name', 'slug', 'location', 'cuisine_type', 'description', 'opening_hours', 'contact_number', 'latitude', 'longitude', 'website_url', 'facebook_url', 'instagram_url'])),
                        'region_id' => $region->id,
                        // Legacy public-eligibility flag; no accreditation provenance is created.
                        'is_accredited' => true,
                        // Sourced map data is user-authorized; planner selection and scheduling code is unchanged.
                        'price_tier' => null,
                    ]);
                    $changes[] = ['restaurant_id' => $restaurant->id, 'slug' => $restaurant->slug, 'created' => true];
                    // Only the new rows receive source-supported catalogue amenities.
                    if ($entry['amenity_keys'] ?? []) {
                        app(\App\Services\Amenities\AmenityStore::class)->sync('restaurant', $restaurant, $entry['amenity_keys']);
                    }
                }
                if ($needsPhoto) {
                    $path = 'listings/real-restaurants-16-local-2026-11-25/'.$entry['file'];
                    if (Storage::disk('public')->exists($path)
                        && hash('sha256', Storage::disk('public')->get($path)) !== $entry['sha256']) {
                        throw new \RuntimeException('Refusing to replace an existing different photo: '.$path);
                    }
                    if (! Storage::disk('public')->put($path, File::get($directory.'/'.$entry['file']))) {
                        throw new \RuntimeException('Could not store '.$entry['file']);
                    }
                    $photo = $restaurant->photos()->create([
                        'path' => $path, 'category' => 'General', 'sort_order' => -1, 'is_primary' => true,
                        ...array_intersect_key($entry, array_flip(['source_url', 'source_name', 'source_type', 'permission_basis',
                            'permission_ref', 'permission_date', 'licence', 'credit_text', 'show_credit', 'verification_status', 'verified_by', 'verified_at'])),
                    ]);
                    $changes[] = ['restaurant_id' => $restaurant->id, 'photo_id' => $photo->id, 'path' => $path];
                }
            }
            if ($changes) File::put(storage_path('app/real-restaurants-16-local-'.now()->format('Ymd-His-u').'.json'),
                json_encode($changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        });

        $this->info("{$new} restaurants and {$photos} photos added. Accreditation remains unconfirmed; existing records unchanged.");

        return self::SUCCESS;
    }
}
