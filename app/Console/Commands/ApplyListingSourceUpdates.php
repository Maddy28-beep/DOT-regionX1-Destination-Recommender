<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies the reviewed listings workbook to a database, for these fields only:
 *
 *   destinations    opening hours, minimum and maximum entry fee, visit duration, best time, price tier
 *   restaurants     opening hours, price tier
 *   accommodations  check-in, check-out, price per night, price tier
 *
 * Nothing else can be written (not coordinates, contact details, links or accreditation). The workbook
 * calls many of these values planning estimates, not verified facts: its "Verification review" sheet,
 * kept alongside the data file, says which rows have a published source.
 *
 * Each row is matched on BOTH id and exact name: the workbook ids belong to the live database, and a
 * row whose name differs is skipped rather than guessed at. A blank cell never overwrites a value.
 * The default is a dry run that changes nothing; --apply writes, inside one transaction.
 */
class ApplyListingSourceUpdates extends Command
{
    protected $signature = 'listings:apply-source-updates
        {--apply : Write the changes. Without it this is a dry run and nothing is changed}
        {--file=database/data/listing-source-updates.json : The data file}
        {--all-rows : List every value in the report, not only the first 40}';

    protected $description = 'Apply the reviewed workbook\'s hours, fees, durations, price tiers and hotel times to listings (dry run unless --apply)';

    /** The only columns this command may write, per table. */
    private const WRITABLE = [
        'destinations' => ['hours', 'entry_fee_min', 'entry_fee_max', 'visit_duration', 'best_time', 'price_tier'],
        'accommodations' => ['check_in', 'check_out', 'price_per_night', 'price_tier'],
        'restaurants' => ['opening_hours', 'price_tier'],
    ];

    private const TIERS = ['Free', 'Budget-Friendly', 'Mid-range', 'Premium'];

    private const TIME_COLUMNS = ['check_in', 'check_out'];

    private const NUMBER_COLUMNS = ['entry_fee_min', 'entry_fee_max', 'price_per_night'];

    public function handle(): int
    {
        $path = (string) $this->option('file');
        $path = is_file($path) ? $path : base_path($path);

        if (! is_file($path)) {
            $this->error("Data file not found: {$path}");

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! is_array($data['entries'] ?? null)) {
            $this->error('The data file is not in the expected format.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'APPLYING changes.' : 'DRY RUN: nothing will be changed. Add --apply to write.');

        $rows = [];
        $updates = [];
        $byField = [];
        $counts = ['changed' => 0, 'same' => 0, 'missing' => 0, 'name_differs' => 0, 'not_allowed' => 0];

        foreach ($data['entries'] as $entry) {
            $table = (string) ($entry['table'] ?? '');
            $id = (int) ($entry['id'] ?? 0);
            $name = (string) ($entry['name'] ?? '');

            if (! isset(self::WRITABLE[$table])) {
                $counts['not_allowed']++;
                $rows[] = [$table, $id, $name, '-', '-', '-', 'skipped: table not allowed'];

                continue;
            }

            $record = DB::table($table)->where('id', $id)->first();
            if (! $record) {
                $counts['missing']++;
                $rows[] = [$table, $id, $name, '-', '-', '-', 'skipped: no row with this id'];

                continue;
            }

            if ($this->normName($record->name) !== $this->normName($name)) {
                $counts['name_differs']++;
                $rows[] = [$table, $id, $name, '-', '-', '-', 'skipped: this id is "'.mb_strimwidth((string) $record->name, 0, 28, '…').'"'];

                continue;
            }

            $changes = [];
            foreach ((array) ($entry['fields'] ?? []) as $column => $value) {
                if (! in_array($column, self::WRITABLE[$table], true) || $value === null || $value === ''
                    || ($column === 'price_tier' && ! in_array($value, self::TIERS, true))) {
                    $counts['not_allowed']++;

                    continue;
                }

                $current = $record->{$column} ?? null;
                if ($this->same($column, $current, $value)) {
                    $counts['same']++;

                    continue;
                }

                $changes[$column] = $value;
                $counts['changed']++;
                $byField[$table.'.'.$column] = ($byField[$table.'.'.$column] ?? 0) + 1;
                $rows[] = [$table, $id, mb_strimwidth($name, 0, 30, '…'), $column, $this->show($current), $this->show($value), $apply ? 'updated' : 'would update'];
            }

            if ($changes !== []) {
                $updates[] = [$table, $id, $changes];
            }
        }

        $skippedRows = array_values(array_filter($rows, fn (array $r) => str_starts_with($r[6], 'skipped')));
        $valueRows = array_values(array_filter($rows, fn (array $r) => ! str_starts_with($r[6], 'skipped')));

        if ($skippedRows !== []) {
            $this->warn('Skipped rows (not touched):');
            $this->table(['table', 'id', 'listing', '', '', '', 'why'], array_slice($skippedRows, 0, 60));
        }

        if ($valueRows !== []) {
            $shown = $this->option('all-rows') ? $valueRows : array_slice($valueRows, 0, 40);
            $this->table(['table', 'id', 'listing', 'field', 'now', 'new', 'result'], $shown);
            if (count($shown) < count($valueRows)) {
                $this->line('... and '.(count($valueRows) - count($shown)).' more values (use --all-rows to list them all).');
            }

            ksort($byField);
            $this->table(['field', 'values to change'], array_map(fn ($k, $v) => [$k, $v], array_keys($byField), $byField));
        }

        $this->line(sprintf(
            'Values to change: %d | already correct: %d | skipped, no such id: %d | skipped, name differs: %d | not allowed: %d',
            $counts['changed'], $counts['same'], $counts['missing'], $counts['name_differs'], $counts['not_allowed']
        ));

        if ($apply && $updates !== []) {
            DB::transaction(function () use ($updates) {
                foreach ($updates as [$table, $id, $changes]) {
                    DB::table($table)->where('id', $id)->update($changes);
                }
            });

            Log::info('listings:apply-source-updates changed '.count($updates).' listing(s)', ['values' => $counts['changed']]);
            $this->info('Done: '.count($updates).' listing(s) updated.');
        } elseif ($apply) {
            $this->info('Nothing needed changing.');
        } elseif ($updates !== []) {
            $this->warn('Dry run only. Back up the database, then run again with --apply.');
        }

        return self::SUCCESS;
    }

    private function normName(?string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $name)));
    }

    private function same(string $column, mixed $current, mixed $new): bool
    {
        if ($current === null || $current === '') {
            return false;
        }

        if (in_array($column, self::TIME_COLUMNS, true)) {
            return substr((string) $current, 0, 5) === substr((string) $new, 0, 5);
        }

        if (in_array($column, self::NUMBER_COLUMNS, true)) {
            return abs((float) $current - (float) $new) < 0.005;
        }

        return trim((string) $current) === trim((string) $new);
    }

    private function show(mixed $value): string
    {
        return $value === null || $value === '' ? '(empty)' : mb_strimwidth((string) $value, 0, 26, '…');
    }
}
