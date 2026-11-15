<?php

namespace App\Console\Commands;

use App\Models\Accommodation;
use App\Models\ListingAmenity;
use App\Support\Amenities;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Adds sourced amenities (parking, what a stay looks out on) to accommodations from a CSV, one amenity per row, each with
 * the document it came from. It only ever adds: an amenity a stay already has is left as it is, and nothing is removed, so
 * what an owner ticked in the partner portal is never undone. A stay is found by its slug (the last part of its web address,
 * the same on every copy of the site) AND its name must match, so a file prepared on one copy cannot touch the wrong row on
 * another. All or nothing: one bad row stops the whole file. Without --apply nothing is written.
 *
 * CSV columns (header row required): slug, name, amenity, source. The amenity is one of the keys a stay is offered
 * (App\Support\Amenities::FOR_KIND['accommodation']), such as "parking" or "view_city".
 */
class ApplyStayAmenities extends Command
{
    protected $signature = 'accommodations:apply-amenities
        {file : CSV file to read}
        {--apply : Write the changes (default is a report only)}';

    protected $description = 'Add sourced amenities (parking, views) to accommodations from a CSV (dry run by default).';

    private const KIND = 'accommodation';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error('Give the path of a CSV file that exists.');

            return self::FAILURE;
        }

        try {
            $rows = $this->read($file);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $offered = Amenities::FOR_KIND[self::KIND];
        $plan = [];
        $bad = 0;
        $seen = [];

        foreach ($rows as $line => $row) {
            $errors = [];
            $stay = $row['slug'] !== '' ? Accommodation::where('slug', $row['slug'])->first() : null;

            if (! $stay) {
                $errors[] = "no stay with slug '{$row['slug']}'";
            } elseif ($this->plain($stay->name) !== $this->plain($row['name'])) {
                $errors[] = "slug '{$row['slug']}' is '{$stay->name}', not '{$row['name']}'";
            }
            if (! in_array($row['amenity'], $offered, true)) {
                $errors[] = "amenity '{$row['amenity']}' is not one a stay is offered";
            }
            if (strlen($row['source']) < 8) {
                $errors[] = 'a source (a link or a named document and date) is required';
            }

            $has = ! $errors
                && ListingAmenity::where('listing_kind', self::KIND)->where('listing_id', $stay->id)->where('amenity', $row['amenity'])->exists();
            $twice = isset($seen[$row['slug'].'|'.$row['amenity']]);
            $seen[$row['slug'].'|'.$row['amenity']] = true;

            $bad += $errors ? 1 : 0;
            $plan[] = ['line' => $line, 'stay' => $stay, 'row' => $row, 'has' => $has || $twice, 'errors' => $errors];
        }

        $this->table(['line', 'name', 'amenity', 'result'], array_map(fn ($p) => [
            $p['line'], mb_substr($p['row']['name'], 0, 40), $p['row']['amenity'],
            $p['errors'] ? 'ERROR: '.implode('; ', $p['errors']) : ($p['has'] ? 'already has it' : 'will add'),
        ], $plan));

        $adds = count(array_filter($plan, fn ($p) => ! $p['errors'] && ! $p['has']));
        $this->line(count($plan).' rows read, '.(count($plan) - $bad)." ok, {$bad} with errors, {$adds} to add.");

        if ($bad > 0 || $plan === []) {
            $this->error('Nothing changed. Fix the rows marked ERROR (or the empty file) and run again.');

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing written. Add --apply to make these changes.');

            return self::SUCCESS;
        }

        $added = 0;
        DB::transaction(function () use ($plan, &$added, $file) {
            foreach ($plan as $p) {
                $made = ListingAmenity::firstOrCreate(['listing_kind' => self::KIND, 'listing_id' => $p['stay']->id, 'amenity' => $p['row']['amenity']]);
                if (! $made->wasRecentlyCreated) {
                    continue;
                }
                $added++;
                Log::info('accommodations:apply-amenities', [
                    'file' => basename($file), 'accommodation_id' => $p['stay']->id, 'amenity' => $p['row']['amenity'], 'source' => $p['row']['source'],
                ]);
            }
        });

        $this->info("Added {$added} amenities. Each one is recorded in the application log with its source.");

        return self::SUCCESS;
    }

    private function plain(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s*\[SAMPLE\]\s*$/i', '', $name)));
    }

    /** @return array<int, array<string, string>> rows keyed by CSV line */
    private function read(string $file): array
    {
        $handle = fopen($file, 'r');
        $header = array_map(fn ($h) => mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);
        $missing = array_diff(['slug', 'name', 'amenity', 'source'], $header);
        if ($missing) {
            fclose($handle);
            throw new \InvalidArgumentException('Missing column(s): '.implode(', ', $missing));
        }

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach (['slug', 'name', 'amenity', 'source'] as $col) {
                $at = array_search($col, $header, true);
                $row[$col] = $at === false ? '' : trim((string) ($cells[$at] ?? ''));
            }
            $rows[$line] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
