<?php

namespace App\Console\Commands;

use App\Models\Destination;
use App\Support\OpeningHours;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies prepared text and detail updates to listings across the catalogue tables from a CSV, one change per row,
 * each with its source. It exists to move edits made on a copy of the data onto the live database without restoring
 * that copy over it, which would erase everything visitors and partners have done since.
 *
 * Safety rules, all enforced before anything is written (all or nothing; without --apply nothing is written):
 *   - the id AND the name must match the row in this database, so a file made on another copy cannot hit the wrong row;
 *   - a description only replaces an empty one or the generated "DOT Region XI-accredited ... (accreditation no. ...)"
 *     sentence. Text a person wrote is kept unless --replace-custom is given;
 *   - only the fields below are accepted, and a blank value is refused (unknown stays blank).
 *
 * CSV columns (header row required): table, id, name, field, value, source
 */
class ApplyListingUpdates extends Command
{
    protected $signature = 'listings:apply-updates
        {file : CSV file to read}
        {--apply : Write the changes (default is a report only)}
        {--replace-custom : Let a description replace text a person wrote, not only generated text}';

    protected $description = 'Apply prepared descriptions and details to listings from a CSV (dry run by default).';

    private const TABLES = ['destinations', 'accommodations', 'restaurants', 'souvenir_centers', 'tour_operators'];

    private const DESTINATION_FIELDS = ['itinerary_role', 'price_tier', 'entry_fee_min', 'entry_fee_max', 'visit_duration', 'best_time', 'hours'];

    private const TIERS = ['Free', 'Budget-Friendly', 'Mid-range', 'Premium'];

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

        $plan = [];
        $bad = 0;
        foreach ($rows as $line => $row) {
            $errors = [];
            $current = null;
            $record = in_array($row['table'], self::TABLES, true) ? DB::table($row['table'])->where('id', $row['id'])->first() : null;

            if (! in_array($row['table'], self::TABLES, true)) {
                $errors[] = "table '{$row['table']}' is not one of: ".implode(', ', self::TABLES);
            } elseif (! $record) {
                $errors[] = "no {$row['table']} row with id {$row['id']}";
            } elseif ($this->plain($record->name) !== $this->plain($row['name'])) {
                $errors[] = "id {$row['id']} is '{$record->name}', not '{$row['name']}'";
            }
            if (! $errors && ! $this->allowed($row['table'], $row['field'])) {
                $errors[] = "field '{$row['field']}' cannot be set on {$row['table']}";
            }
            if (strlen($row['source']) < 8) {
                $errors[] = 'a source is required';
            }
            if ($row['value'] === '') {
                $errors[] = 'value is empty; unknown stays blank, so leave the row out';
            }
            if (! $errors) {
                $current = $record->{$row['field']} ?? null;
                $errors = $this->check($row, (string) $current);
            }

            $bad += $errors ? 1 : 0;
            $plan[] = ['line' => $line, 'row' => $row, 'current' => $current, 'errors' => $errors];
        }

        $shown = array_slice($plan, 0, 60);
        $this->table(['line', 'table', 'id', 'field', 'now', 'new', 'result'], array_map(fn ($p) => [
            $p['line'], $p['row']['table'], $p['row']['id'], $p['row']['field'],
            $p['current'] === null || $p['current'] === '' ? '(blank)' : mb_substr((string) $p['current'], 0, 24),
            mb_substr($p['row']['value'], 0, 24),
            $p['errors'] ? 'ERROR: '.implode('; ', $p['errors']) : ($this->same($p['current'], $p['row']['value']) ? 'no change' : 'will update'),
        ], $shown));
        if (count($plan) > count($shown)) {
            $this->line('... and '.(count($plan) - count($shown)).' more rows (only the first '.count($shown).' are listed).');
        }

        $changes = count(array_filter($plan, fn ($p) => ! $p['errors'] && ! $this->same($p['current'], $p['row']['value'])));
        $this->line(count($plan).' rows read, '.(count($plan) - $bad)." ok ({$changes} would change), {$bad} with errors.");

        if ($bad > 0 || $plan === []) {
            $this->error('Nothing changed. Fix the rows marked ERROR (or the empty file) and run again.');

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing written. Add --apply to make these changes.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan, $file) {
            foreach ($plan as $p) {
                if ($this->same($p['current'], $p['row']['value'])) {
                    continue;
                }
                DB::table($p['row']['table'])->where('id', $p['row']['id'])->update([$p['row']['field'] => $p['row']['value']]);
                Log::info('listings:apply-updates', [
                    'file' => basename($file), 'table' => $p['row']['table'], 'id' => $p['row']['id'], 'field' => $p['row']['field'],
                    'from' => mb_substr((string) $p['current'], 0, 200), 'to' => mb_substr($p['row']['value'], 0, 200), 'source' => $p['row']['source'],
                ]);
            }
        });

        $this->info("Updated {$changes} values. Each change is recorded in the application log with its source.");

        return self::SUCCESS;
    }

    private function allowed(string $table, string $field): bool
    {
        return $field === 'description' || ($table === 'destinations' && in_array($field, self::DESTINATION_FIELDS, true));
    }

    /** @return array<int, string> */
    private function check(array $row, string $current): array
    {
        $v = $row['value'];

        switch ($row['field']) {
            case 'description':
                if (strlen($v) > 1000) {
                    return ['description is longer than 1000 characters'];
                }
                if (! $this->option('replace-custom') && trim($current) !== '' && ! $this->generated($current) && trim($current) !== trim($v)) {
                    return ['the current description was written by a person; it is kept (use --replace-custom to replace it)'];
                }

                return [];
            case 'itinerary_role':
                return isset(Destination::ITINERARY_ROLES[$v]) ? [] : ['itinerary_role must be one of: '.implode(', ', array_keys(Destination::ITINERARY_ROLES))];
            case 'price_tier':
                return in_array($v, self::TIERS, true) ? [] : ['price_tier must be one of: '.implode(', ', self::TIERS)];
            case 'entry_fee_min':
            case 'entry_fee_max':
                return (! is_numeric($v) || $v < 0) ? ["{$row['field']} must be a number of pesos, 0 or more"] : [];
            case 'hours':
                return OpeningHours::windows($v) === null ? ["hours '{$v}' is not a plain daily schedule the planner can read"] : [];
            default:
                return strlen($v) > 60 ? ["{$row['field']} is longer than 60 characters"] : [];
        }
    }

    /** The generated sentence the accreditation import wrote, not text a person wrote. */
    private function generated(string $text): bool
    {
        return (bool) preg_match('/DOT Region XI-accredited|\(accreditation no\./i', $text);
    }

    private function same(mixed $current, string $value): bool
    {
        if (is_numeric($current) && is_numeric($value)) {
            return abs((float) $current - (float) $value) < 1e-9;
        }

        return trim((string) $current) === trim($value);
    }

    private function plain(?string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s*\[SAMPLE\]\s*$/i', '', (string) $name)));
    }

    /** @return array<int, array<string, string>> rows keyed by CSV line */
    private function read(string $file): array
    {
        $handle = fopen($file, 'r');
        $header = array_map(fn ($h) => mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);
        $cols = ['table', 'id', 'name', 'field', 'value', 'source'];
        $missing = array_diff($cols, $header);
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
            foreach ($cols as $col) {
                $row[$col] = trim((string) ($cells[array_search($col, $header, true)] ?? ''));
            }
            $rows[$line] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
