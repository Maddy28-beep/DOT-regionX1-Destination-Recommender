<?php

namespace App\Console\Commands;

use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\DestinationTag;
use App\Models\Region;
use App\Support\Amenities;
use App\Support\SampleManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds clearly-labelled sample destinations from a CSV, so the planner, swaps and Apriori have more places to work
 * with while the real catalogue is still thin.
 *
 * Every sample is marked three ways: the name ends in " [SAMPLE]", its accreditation number is SAMPLE-0001 onwards,
 * and its record is flagged is_placeholder, which makes every page show "Sample data, not DOT-verified".
 *
 * All or nothing: one bad row stops the whole import, so a half-loaded file can never go live. Without --apply
 * nothing is written. --remove deletes the samples this command added (and only those).
 *
 * CSV columns (header row required): name, type, location, region, latitude, longitude are required;
 * description, price_tier, entry_fee_min, entry_fee_max, hours, visit_duration, best_time, itinerary_role are optional.
 * category_tags and amenity_tags are optional pipe-separated lists ("Beach|Island Hopping"); amenity_tags takes
 * Amenities catalogue keys (parking, restroom, ...) and is stored as the exact catalogue labels the recommender reads.
 */
class ImportSampleDestinations extends Command
{
    protected $signature = 'sample:import-destinations
        {file? : CSV file to read}
        {--apply : Write the changes (default is a report only)}
        {--remove : Delete the sample destinations added by this command instead}
        {--from-number= : With --remove, only remove records numbered from this SAMPLE number (targeted rollback)}
        {--to-number= : With --remove, only remove records numbered up to this SAMPLE number (targeted rollback)}
        {--manifest= : With --remove, require removal candidates to exactly match this rollback manifest file}';

    protected $description = 'Add (or remove) clearly labelled sample destinations from a CSV.';

    public const SUFFIX = ' [SAMPLE]';

    private const REQUIRED = ['name', 'type', 'location', 'region', 'latitude', 'longitude'];

    private const OPTIONAL = ['description', 'price_tier', 'entry_fee_min', 'entry_fee_max', 'hours', 'visit_duration', 'best_time', 'itinerary_role', 'category_tags', 'amenity_tags'];

    /** Roughly the Davao Region (Region XI), so a swapped or mistyped coordinate is caught. */
    private const LAT = [5.5, 8.3];

    private const LNG = [125.0, 126.8];

    /** Far-future expiry: a sample must not drop out of the site because a made-up date passed. */
    private const EXPIRES = '2030-12-31';

    public function handle(): int
    {
        return $this->option('remove') ? $this->remove() : $this->import();
    }

    private function import(): int
    {
        $file = (string) $this->argument('file');
        if ($file === '' || ! is_file($file)) {
            $this->error('Give the path of a CSV file that exists.');

            return self::FAILURE;
        }

        try {
            [$rows, $problems] = $this->read($file);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
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
            if (($row['description'] ?? '') !== '' && strlen($row['description']) > 1000) {
                $errors[] = 'description is longer than 1000 characters';
            }
            if (($row['itinerary_role'] ?? '') !== '' && ! isset(Destination::ITINERARY_ROLES[$row['itinerary_role']])) {
                $errors[] = 'itinerary_role must be one of: '.implode(', ', array_keys(Destination::ITINERARY_ROLES));
            }
            foreach ($this->splitList($row['category_tags'] ?? '') as $tag) {
                if (strlen($tag) > 80) {
                    $errors[] = "category tag '{$tag}' is longer than 80 characters";
                }
            }
            foreach ($this->splitList($row['amenity_tags'] ?? '') as $key) {
                if (! in_array($key, Amenities::FOR_KIND['destination'], true)) {
                    $errors[] = "amenity '{$key}' is not a destination amenity (allowed: ".implode(', ', Amenities::FOR_KIND['destination']).')';
                }
            }
            if (isset($seen[$slug]) || Destination::where('slug', $slug)->exists()) {
                $errors[] = 'a destination with this name already exists';
            }
            $seen[$slug] = true;

            $plan[] = ['line' => $line, 'row' => $row, 'name' => $name, 'slug' => $slug, 'errors' => $errors,
                'number' => $errors ? '-' : sprintf('SAMPLE-%04d', $nextNumber++)];
        }

        $this->table(['line', 'name', 'type', 'region', 'lat, lng', 'number', 'result'], array_map(fn ($p) => [
            $p['line'], $p['name'], $p['row']['type'], $p['row']['region'], $p['row']['latitude'].', '.$p['row']['longitude'], $p['number'],
            $p['errors'] ? 'ERROR: '.implode('; ', $p['errors']) : 'new',
        ], $plan));

        $bad = count(array_filter($plan, fn ($p) => $p['errors']));
        $this->line(count($plan).' rows read, '.(count($plan) - $bad)." ok, {$bad} with errors.");

        if ($bad > 0 || $plan === []) {
            $this->error('Nothing changed. Fix the rows marked ERROR (or the empty file) and run again.');

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing written. Add --apply to create these '.count($plan).' sample destinations.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan, $regions) {
            foreach ($plan as $p) {
                $r = $p['row'];
                $destination = Destination::create(array_filter([
                    'slug' => $p['slug'], 'name' => $p['name'], 'location' => $r['location'], 'region_id' => $regions[$r['region']],
                    'type' => $r['type'], 'description' => $r['description'] ?? null, 'price_tier' => $r['price_tier'] ?? null,
                    'entry_fee_min' => $r['entry_fee_min'] ?? null, 'entry_fee_max' => $r['entry_fee_max'] ?? null,
                    'hours' => $r['hours'] ?? null, 'visit_duration' => $r['visit_duration'] ?? null, 'best_time' => $r['best_time'] ?? null,
                    'itinerary_role' => $r['itinerary_role'] ?? null, 'latitude' => $r['latitude'], 'longitude' => $r['longitude'],
                ], fn ($v) => $v !== null && $v !== '') + ['is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'featured' => false]);

                foreach ($this->splitList($r['category_tags'] ?? '') as $tag) {
                    DestinationTag::create(['destination_id' => $destination->id, 'kind' => 'category', 'value' => $tag]);
                }
                foreach ($this->splitList($r['amenity_tags'] ?? '') as $key) {
                    DestinationTag::create(['destination_id' => $destination->id, 'kind' => 'amenity', 'value' => Amenities::label($key)]);
                }

                AccreditationRecord::create([
                    'listing_kind' => 'destination', 'listing_id' => $destination->id, 'accreditation_number' => $p['number'],
                    'status' => 'Active', 'issue_date' => now()->toDateString(), 'expiration_date' => self::EXPIRES, 'is_placeholder' => true,
                ]);
            }
        });

        $this->info('Created '.count($plan).' sample destinations. Embeddings are built separately (Step 3). To undo: php artisan sample:import-destinations --remove --apply');

        return self::SUCCESS;
    }

    private function remove(): int
    {
        $records = AccreditationRecord::where('listing_kind', 'destination')->where('is_placeholder', true)
            ->where('accreditation_number', 'like', 'SAMPLE-%')->get();

        // Targeted rollback: a number range removes only that batch. Pre-existing samples below the
        // range (e.g. SAMPLE-0001..SAMPLE-0018 in production) are reported as protected, never selected.
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
            $this->warn('Targeted removal: '.($records->count() - $inRange->count()).' sample destinations are outside the given range and will be left untouched.');
            $records = $inRange;
        }

        $destinations = Destination::whereIn('id', $records->pluck('listing_id'))->where('name', 'like', '%'.self::SUFFIX)->get();

        // Manifest-enforced rollback: candidates must be exactly manifest ∩ range ∩ triple guard.
        // Any missing, modified or out-of-manifest record aborts before anything can be deleted.
        if ($this->option('manifest') && ! $this->manifestGuard('destination', $destinations->pluck('id')->all(), $from, $to)) {
            return self::FAILURE;
        }

        $this->table(['id', 'name', 'number'], $destinations->map(fn ($d) => [$d->id, $d->name, $records->firstWhere('listing_id', $d->id)?->accreditation_number])->all());
        $this->line($destinations->count().' sample destinations found.');

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing deleted. Add --apply to remove them.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($destinations) {
            AccreditationRecord::where('listing_kind', 'destination')->whereIn('listing_id', $destinations->pluck('id'))->delete();
            Destination::whereIn('id', $destinations->pluck('id'))->delete();
        });
        $this->info('Removed '.$destinations->count().' sample destinations.');

        return self::SUCCESS;
    }

    /** @return array{0: array<int, array<string, string>>, 1: array<int, array<int, string>>} rows and per-line problems, keyed by CSV line */
    private function read(string $file): array
    {
        $handle = fopen($file, 'r');
        $header = array_map(fn ($h) => Str::lower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);
        $missing = array_diff(self::REQUIRED, $header);
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
            foreach (array_merge(self::REQUIRED, self::OPTIONAL) as $col) {
                $i = array_search($col, $header, true);
                $row[$col] = $i === false ? '' : trim((string) ($cells[$i] ?? ''));
            }
            foreach (self::REQUIRED as $col) {
                if ($row[$col] === '') {
                    $problems[$line][] = "{$col} is empty";
                }
            }
            $rows[$line] = $row;
        }
        fclose($handle);

        return [$rows, $problems];
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

    /** @return array<int, string> */
    private function splitList(string $value): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode('|', $value)), fn ($v) => $v !== '')));
    }

    private function nextNumber(): int
    {
        $max = AccreditationRecord::where('accreditation_number', 'like', 'SAMPLE-%')->pluck('accreditation_number')
            ->map(fn ($n) => (int) substr($n, 7))->max();

        return ($max ?? 0) + 1;
    }
}
