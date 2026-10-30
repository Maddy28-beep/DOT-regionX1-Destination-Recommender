<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Archives (or restores) listings named in a CSV: hidden from the public site and never offered in an itinerary, but
 * kept in the database, so nothing is lost and --restore puts them back.
 *
 * Archiving is the reversible way to take a listing out of use, for example one that has no map location and so cannot
 * be placed on a route. Deleting is a different, permanent act and is not done here.
 *
 * Safety: the id AND the name must match the row in this database, a dry run is the default, one bad row stops the
 * whole file, and every change is written to the application log with its reason.
 *
 * CSV columns (header row required): table, id, name, reason
 */
class ArchiveListings extends Command
{
    protected $signature = 'listings:archive
        {file : CSV file to read}
        {--apply : Write the changes (default is a report only)}
        {--restore : Put the listed listings back instead of archiving them}';

    protected $description = 'Archive (or restore) listings from a CSV, reversibly (dry run by default).';

    private const TABLES = ['destinations', 'accommodations', 'restaurants', 'souvenir_centers'];

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

        $restore = (bool) $this->option('restore');
        $plan = [];
        $bad = 0;

        foreach ($rows as $line => $row) {
            $errors = [];
            $record = in_array($row['table'], self::TABLES, true) ? DB::table($row['table'])->where('id', $row['id'])->first() : null;

            if (! in_array($row['table'], self::TABLES, true)) {
                $errors[] = "table '{$row['table']}' is not one of: ".implode(', ', self::TABLES);
            } elseif (! $record) {
                $errors[] = "no {$row['table']} row with id {$row['id']}";
            } elseif (mb_strtolower(trim($record->name)) !== mb_strtolower($row['name'])) {
                $errors[] = "id {$row['id']} is '{$record->name}', not '{$row['name']}'";
            }
            if (! $restore && strlen($row['reason']) < 8) {
                $errors[] = 'a reason is required to archive';
            }

            $archived = $record && $record->archived_at !== null;
            $bad += $errors ? 1 : 0;
            $plan[] = ['line' => $line, 'row' => $row, 'archived' => $archived, 'errors' => $errors];
        }

        $this->table(['line', 'table', 'id', 'name', 'now', 'result'], array_map(fn ($p) => [
            $p['line'], $p['row']['table'], $p['row']['id'], mb_substr($p['row']['name'], 0, 40), $p['archived'] ? 'archived' : 'live',
            $p['errors'] ? 'ERROR: '.implode('; ', $p['errors']) : ($p['archived'] === $restore ? ($restore ? 'will restore' : 'no change') : ($restore ? 'no change' : 'will archive')),
        ], $plan));

        $changes = count(array_filter($plan, fn ($p) => ! $p['errors'] && $p['archived'] !== ! $restore));
        $this->line(count($plan).' rows read, '.(count($plan) - $bad)." ok ({$changes} would change), {$bad} with errors.");

        if ($bad > 0 || $plan === []) {
            $this->error('Nothing changed. Fix the rows marked ERROR (or the empty file) and run again.');

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing written. Add --apply to make these changes.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan, $restore, $file) {
            foreach ($plan as $p) {
                if ($p['archived'] === ! $restore) {
                    continue;   // already in the state asked for
                }
                DB::table($p['row']['table'])->where('id', $p['row']['id'])->update(['archived_at' => $restore ? null : now()]);
                Log::info('listings:archive', [
                    'file' => basename($file), 'table' => $p['row']['table'], 'id' => $p['row']['id'], 'name' => $p['row']['name'],
                    'action' => $restore ? 'restored' : 'archived', 'reason' => $p['row']['reason'],
                ]);
            }
        });

        $this->info(($restore ? 'Restored ' : 'Archived ')."{$changes} listings. Each change is recorded in the application log.");

        return self::SUCCESS;
    }

    /** @return array<int, array<string, string>> rows keyed by CSV line */
    private function read(string $file): array
    {
        $handle = fopen($file, 'r');
        $header = array_map(fn ($h) => mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);
        $cols = ['table', 'id', 'name', 'reason'];
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
