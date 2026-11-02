<?php

namespace App\Console\Commands;

use App\Models\Accommodation;
use App\Models\AccommodationRoomType;
use App\Models\AccreditationRecord;
use App\Models\ListingAmenity;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Services\Amenities\AmenityStore;
use App\Support\Amenities;
use App\Support\SampleManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds clearly-labelled sample accommodations, restaurants and souvenir centres from a CSV, the
 * counterpart of sample:import-destinations for the listing kinds that command does not cover.
 *
 * Same contract as the destination importer: every sample is marked three ways (name ends in
 * " [SAMPLE]", accreditation number SAMPLE-####, record flagged is_placeholder so every page shows
 * "Sample data, not DOT-verified"); all or nothing (one bad row stops the whole import); without
 * --apply nothing is written; --remove deletes only what this command added (placeholder record,
 * SAMPLE-% number and name suffix must all match).
 *
 * CSV columns (header row required), common: name, location, region, latitude, longitude,
 * description, amenities (pipe-separated Amenities catalogue keys, at least 4, kind-legal).
 *   accommodation:  type, price_tier, price_per_night, check_in, check_out, room_types
 *                   ("Standard Room:1500-1900;Deluxe Room:2000-2600"), optional resort_settings
 *                   (pipe-separated, Accommodation::RESORT_SETTINGS).
 *   restaurant:     cuisine_type, price_tier, opening_hours
 *   souvenir_center: no extra columns (products, hours and price notes belong in description;
 *                   the table has no opening_hours or price_tier column).
 */
class ImportSampleListings extends Command
{
    protected $signature = 'sample:import-listings
        {file? : CSV file to read}
        {--kind= : accommodation, restaurant or souvenir_center}
        {--apply : Write the changes (default is a report only)}
        {--remove : Delete the sample listings of this kind added by this command instead}
        {--from-number= : With --remove, only remove records numbered from this SAMPLE number (targeted rollback)}
        {--to-number= : With --remove, only remove records numbered up to this SAMPLE number (targeted rollback)}
        {--manifest= : With --remove, require removal candidates to exactly match this rollback manifest file}';

    protected $description = 'Add (or remove) clearly labelled sample accommodations, restaurants or souvenir centres from a CSV.';

    public const SUFFIX = ' [SAMPLE]';

    private const KINDS = [
        'accommodation' => ['model' => Accommodation::class, 'label' => 'accommodation'],
        'restaurant' => ['model' => Restaurant::class, 'label' => 'restaurant'],
        'souvenir_center' => ['model' => SouvenirCenter::class, 'label' => 'souvenir centre'],
    ];

    private const COMMON = ['name', 'location', 'region', 'latitude', 'longitude', 'description', 'amenities'];

    private const EXTRA = [
        'accommodation' => ['type', 'price_tier', 'price_per_night', 'check_in', 'check_out', 'room_types'],
        'restaurant' => ['cuisine_type', 'price_tier', 'opening_hours'],
        'souvenir_center' => [],
    ];

    private const OPTIONAL = ['accommodation' => ['resort_settings'], 'restaurant' => [], 'souvenir_center' => []];

    private const TIERS = ['Free', 'Budget-Friendly', 'Mid-range', 'Premium'];

    private const ACCOMMODATION_TYPES = ['Apartment Hotel', 'Beach Resort', 'Condominium Unit', 'Homestay', 'Hotel', 'Resort'];

    private const CUISINES = ['Bar & Grill', 'Cafe', 'Cafe & Filipino', 'European & Deli', 'Farm-to-Table', 'Filipino', 'Filipino & International', 'Filipino Seafood', 'Japanese', 'Mongolian & Asian', 'Seafood', 'Steak & Seafood'];

    private const LAT = [5.5, 8.3];

    private const LNG = [125.0, 126.8];

    private const EXPIRES = '2030-12-31';

    public function handle(): int
    {
        $kind = (string) $this->option('kind');
        if (! isset(self::KINDS[$kind])) {
            $this->error('--kind must be one of: '.implode(', ', array_keys(self::KINDS)));

            return self::FAILURE;
        }

        return $this->option('remove') ? $this->remove($kind) : $this->import($kind);
    }

    private function import(string $kind): int
    {
        $file = (string) $this->argument('file');
        if ($file === '' || ! is_file($file)) {
            $this->error('Give the path of a CSV file that exists.');

            return self::FAILURE;
        }

        try {
            [$rows, $problems] = $this->read($file, $kind);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $model = self::KINDS[$kind]['model'];
        $regions = Region::pluck('id', 'name');
        $nextNumber = $this->nextNumber();
        $plan = [];
        $seen = [];

        foreach ($rows as $line => $row) {
            $errors = $problems[$line] ?? [];
            $name = trim($row['name']).self::SUFFIX;
            $slug = Str::slug($name);

            if (! isset($regions[$row['region']])) {
                $errors[] = "region '{$row['region']}' is not one of: ".$regions->keys()->implode(', ');
            }
            foreach (['latitude' => self::LAT, 'longitude' => self::LNG] as $key => [$min, $max]) {
                if (! is_numeric($row[$key]) || $row[$key] < $min || $row[$key] > $max) {
                    $errors[] = "{$key} '{$row[$key]}' is missing or outside the Davao Region ({$min} to {$max})";
                }
            }
            if (strlen($name) > 150) {
                $errors[] = 'name is too long';
            }
            if (strlen($row['description']) > 1000) {
                $errors[] = 'description is longer than 1000 characters';
            }
            $amenities = $this->splitList($row['amenities']);
            if (count($amenities) < 4) {
                $errors[] = 'at least 4 amenities are required, '.count($amenities).' given';
            }
            foreach ($amenities as $key) {
                if (! in_array($key, Amenities::FOR_KIND[$kind] ?? [], true)) {
                    $errors[] = "amenity '{$key}' is not a {$kind} amenity (allowed: ".implode(', ', Amenities::FOR_KIND[$kind] ?? []).')';
                }
            }
            $errors = array_merge($errors, $this->kindErrors($kind, $row));
            if (isset($seen[$slug]) || $model::where('slug', $slug)->exists()) {
                $errors[] = 'a listing with this name already exists';
            }
            $seen[$slug] = true;

            $plan[] = ['line' => $line, 'row' => $row, 'name' => $name, 'slug' => $slug, 'errors' => $errors,
                'number' => $errors ? '-' : sprintf('SAMPLE-%04d', $nextNumber++)];
        }

        $this->table(['line', 'name', 'region', 'lat, lng', 'number', 'result'], array_map(fn ($p) => [
            $p['line'], $p['name'], $p['row']['region'], $p['row']['latitude'].', '.$p['row']['longitude'], $p['number'],
            $p['errors'] ? 'ERROR: '.implode('; ', $p['errors']) : 'new',
        ], $plan));
        $bad = count(array_filter($plan, fn ($p) => $p['errors']));
        $this->line(count($plan).' rows read, '.(count($plan) - $bad)." ok, {$bad} with errors.");

        if ($bad > 0 || $plan === []) {
            $this->error('Nothing changed. Fix the rows marked ERROR (or the empty file) and run again.');

            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            $this->info('Dry run: nothing written. Add --apply to create these '.count($plan).' sample '.self::KINDS[$kind]['label'].'s.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan, $regions, $kind, $model) {
            $amenityStore = app(AmenityStore::class);
            foreach ($plan as $p) {
                $r = $p['row'];
                $listing = $model::create($this->attributes($kind, $p, $r, $regions[$r['region']]));
                if ($kind === 'accommodation') {
                    foreach ($this->roomTypes($r['room_types']) as $room) {
                        AccommodationRoomType::create(['accommodation_id' => $listing->id] + $room);
                    }
                }
                $amenityStore->sync($kind, $listing, $this->splitList($r['amenities']));
                AccreditationRecord::create([
                    'listing_kind' => $kind, 'listing_id' => $listing->id, 'accreditation_number' => $p['number'],
                    'status' => 'Active', 'issue_date' => now()->toDateString(), 'expiration_date' => self::EXPIRES, 'is_placeholder' => true,
                ]);
            }
        });
        $this->info('Created '.count($plan).' sample '.self::KINDS[$kind]['label'].'s. To undo: php artisan sample:import-listings --kind='.$kind.' --remove --apply');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function attributes(string $kind, array $p, array $r, int $regionId): array
    {
        $base = ['slug' => $p['slug'], 'name' => $p['name'], 'location' => $r['location'], 'region_id' => $regionId,
            'description' => $r['description'], 'latitude' => $r['latitude'], 'longitude' => $r['longitude']];

        return match ($kind) {
            'accommodation' => $base + ['type' => $r['type'], 'price_tier' => $r['price_tier'],
                'price_per_night' => $r['price_per_night'], 'check_in' => $r['check_in'], 'check_out' => $r['check_out'],
                'resort_settings' => Accommodation::encodeResortSettings($this->splitList($r['resort_settings'] ?? '')),
                'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'featured' => false],
            'restaurant' => $base + ['cuisine_type' => $r['cuisine_type'], 'price_tier' => $r['price_tier'],
                'opening_hours' => $r['opening_hours'], 'is_accredited' => true, 'rating' => 0, 'review_count' => 0],
            default => $base + ['is_accredited' => true, 'rating' => 0, 'review_count' => 0],
        };
    }

    /** @return array<int, string> */
    private function kindErrors(string $kind, array $row): array
    {
        $errors = [];
        if ($kind === 'accommodation') {
            if (! in_array($row['type'], self::ACCOMMODATION_TYPES, true)) {
                $errors[] = 'type must be one of: '.implode(', ', self::ACCOMMODATION_TYPES);
            }
            if (! in_array($row['price_tier'], self::TIERS, true)) {
                $errors[] = 'price_tier must be one of: '.implode(', ', self::TIERS);
            }
            if (! is_numeric($row['price_per_night']) || (float) $row['price_per_night'] <= 0) {
                $errors[] = 'price_per_night must be a positive number';
            }
            foreach (['check_in', 'check_out'] as $t) {
                if (! preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $row[$t])) {
                    $errors[] = "{$t} must be HH:MM";
                }
            }
            $rooms = $this->roomTypes($row['room_types']);
            if ($rooms === []) {
                $errors[] = 'room_types must list at least one "Name:min-max" entry';
            }
            foreach ($rooms as $room) {
                if ($room['price_min'] > $room['price_max']) {
                    $errors[] = "room type '{$room['name']}' has price_min above price_max";
                }
            }
            foreach ($this->splitList($row['resort_settings'] ?? '') as $s) {
                if (! in_array($s, Accommodation::RESORT_SETTINGS, true)) {
                    $errors[] = "resort setting '{$s}' is not one of: ".implode(', ', Accommodation::RESORT_SETTINGS);
                }
            }
        }
        if ($kind === 'restaurant') {
            if (! in_array($row['cuisine_type'], self::CUISINES, true)) {
                $errors[] = 'cuisine_type must be one of: '.implode(', ', self::CUISINES);
            }
            if (! in_array($row['price_tier'], self::TIERS, true)) {
                $errors[] = 'price_tier must be one of: '.implode(', ', self::TIERS);
            }
        }

        return $errors;
    }

    private function remove(string $kind): int
    {
        $model = self::KINDS[$kind]['model'];
        $records = AccreditationRecord::where('listing_kind', $kind)->where('is_placeholder', true)
            ->where('accreditation_number', 'like', 'SAMPLE-%')->get();

        // Targeted rollback: a number range removes only that batch; records outside it are protected.
        $from = SampleManifest::numberValue($this->option('from-number') ?: null);
        $to = SampleManifest::numberValue($this->option('to-number') ?: null);
        if ($this->option('from-number') && $from === null || $this->option('to-number') && $to === null) {
            $this->error('--from-number and --to-number must look like SAMPLE-0042.');

            return self::FAILURE;
        }
        if ($from !== null || $to !== null) {
            $inRange = $records->filter(function ($r) use ($from, $to) {
                $n = SampleManifest::numberValue($r->accreditation_number);

                return $n !== null && ($from === null || $n >= $from) && ($to === null || $n <= $to);
            });
            $this->warn('Targeted removal: '.($records->count() - $inRange->count()).' sample '.self::KINDS[$kind]['label'].'s are outside the given range and will be left untouched.');
            $records = $inRange;
        }
        $listings = $model::whereIn('id', $records->pluck('listing_id'))->where('name', 'like', '%'.self::SUFFIX)->get();

        // Manifest-enforced rollback: candidates must be exactly manifest ∩ range ∩ triple guard.
        if ($this->option('manifest') && ! $this->manifestGuard($kind, $listings->pluck('id')->all(), $from, $to)) {
            return self::FAILURE;
        }

        $this->table(['id', 'name', 'number'], $listings->map(fn ($l) => [$l->id, $l->name, $records->firstWhere('listing_id', $l->id)?->accreditation_number])->all());
        $this->line($listings->count().' sample '.self::KINDS[$kind]['label'].'s found.');

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing deleted. Add --apply to remove them.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($listings, $kind) {
            AccreditationRecord::where('listing_kind', $kind)->whereIn('listing_id', $listings->pluck('id'))->delete();
            // listing_amenities and accommodation_room_types cascade by listing deletion in their tables'
            // own conventions; delete explicitly so removal never leaves orphaned attributes behind.
            ListingAmenity::where('listing_kind', $kind)->whereIn('listing_id', $listings->pluck('id'))->delete();
            if ($kind === 'accommodation') {
                AccommodationRoomType::whereIn('accommodation_id', $listings->pluck('id'))->delete();
            }
            foreach ($listings as $listing) {
                $listing->delete();
            }
        });
        $this->info('Removed '.$listings->count().' sample '.self::KINDS[$kind]['label'].'s.');

        return self::SUCCESS;
    }

    /**
     * Removal candidates must exactly match the manifest entries for this kind and range.
     * Verifies manifest integrity and database agreement first; prints the reason and returns
     * false (abort) on any mismatch, before any deletion can happen.
     */
    private function manifestGuard(string $kind, array $candidateIds, ?int $from, ?int $to): bool
    {
        $manifest = app(SampleManifest::class);
        $path = (string) $this->option('manifest');
        $check = $manifest->verifyAgainstDatabase($path);
        if (! $check['ok']) {
            foreach ($check['errors'] as $error) {
                $this->error("Manifest check failed: {$error}");
            }
            $this->error('Removal aborted: the manifest does not verify against the database.');

            return false;
        }

        $entries = collect(json_decode((string) file_get_contents($path), true)['entries'] ?? [])
            ->where('kind', $kind)
            ->filter(function ($e) use ($from, $to) {
                $n = SampleManifest::numberValue($e['number'] ?? null);

                return $n !== null && ($from === null || $n >= $from) && ($to === null || $n <= $to);
            });
        $manifestIds = $entries->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $candidateIds = collect($candidateIds)->map(fn ($id) => (int) $id)->sort()->values()->all();

        if ($manifestIds !== $candidateIds) {
            $this->error('Removal aborted: candidates do not exactly match the manifest for this kind and range.');
            $this->error('Only in manifest: '.implode(', ', array_diff($manifestIds, $candidateIds)) ?: 'Only in manifest: -');
            $this->error('Only eligible in range: '.implode(', ', array_diff($candidateIds, $manifestIds)) ?: 'Only eligible in range: -');

            return false;
        }

        return true;
    }

    /** @return array{0: array<int, array<string, string>>, 1: array<int, array<int, string>>} */
    private function read(string $file, string $kind): array
    {
        $required = array_merge(self::COMMON, self::EXTRA[$kind]);
        $optional = self::OPTIONAL[$kind];
        $handle = fopen($file, 'r');
        $header = array_map(fn ($h) => Str::lower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);
        $missing = array_diff($required, $header);
        if ($missing) {
            fclose($handle);
            throw new \InvalidArgumentException('Missing column(s): '.implode(', ', $missing));
        }

        $rows = [];
        $problems = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if ($cells === [null] || count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach (array_merge($required, $optional) as $col) {
                $i = array_search($col, $header, true);
                $row[$col] = $i === false ? '' : trim((string) ($cells[$i] ?? ''));
            }
            foreach ($required as $col) {
                if ($row[$col] === '') {
                    $problems[$line][] = "{$col} is empty";
                }
            }
            $rows[$line] = $row;
        }
        fclose($handle);

        return [$rows, $problems];
    }

    /** @return array<int, string> */
    private function splitList(string $value): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode('|', $value)), fn ($v) => $v !== '')));
    }

    /** @return array<int, array{name: string, price_min: float, price_max: float}> */
    private function roomTypes(string $value): array
    {
        $rooms = [];
        foreach (array_filter(array_map('trim', explode(';', $value))) as $entry) {
            if (preg_match('/^(.+):(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)$/', $entry, $m)) {
                $rooms[] = ['name' => trim($m[1]), 'price_min' => (float) $m[2], 'price_max' => (float) $m[3]];
            }
        }

        return $rooms;
    }

    private function nextNumber(): int
    {
        $max = AccreditationRecord::where('accreditation_number', 'like', 'SAMPLE-%')->pluck('accreditation_number')
            ->map(fn ($n) => (int) substr($n, 7))->max();

        return ($max ?? 0) + 1;
    }
}
