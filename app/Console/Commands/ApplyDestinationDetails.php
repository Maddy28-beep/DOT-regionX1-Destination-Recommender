<?php

namespace App\Console\Commands;

use App\Models\Destination;
use App\Models\DestinationTag;
use App\Support\OpeningHours;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies researched details (coordinates, hours, fees, durations, category tags) to destinations from a CSV, one
 * change per row, each with the source it came from. A category_tag row adds that tag (the words the planner matches
 * against a traveller's interests, such as "Nature" or "Museum") if the place does not have it already.
 *
 * Meant for filling gaps in the catalogue safely: a change is only accepted with a source, only for a place whose id
 * AND name both match (so a file prepared against one copy of the data cannot rewrite the wrong row on another),
 * and only for the fields below. All or nothing: one bad row stops the whole file. Without --apply nothing is
 * written. Unknown stays blank: a row with an empty value is rejected rather than treated as zero.
 *
 * CSV columns (header row required): id, name, field, value, source
 */
class ApplyDestinationDetails extends Command
{
    protected $signature = 'destinations:apply-details
        {file : CSV file to read}
        {--apply : Write the changes (default is a report only)}';

    protected $description = 'Apply sourced coordinate, hours, fee and duration details to destinations from a CSV (dry run by default).';

    private const FIELDS = ['latitude', 'longitude', 'hours', 'visit_duration', 'best_time', 'price_tier', 'entry_fee_min', 'entry_fee_max', 'category_tag'];

    private const TIERS = ['Free', 'Budget-Friendly', 'Mid-range', 'Premium'];

    /** Roughly the Davao Region (Region XI), so a swapped or mistyped coordinate is caught. */
    private const LAT = [5.5, 8.3];

    private const LNG = [125.0, 126.8];

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
            $destination = Destination::find($row['id']);

            if (! $destination) {
                $errors[] = "no destination with id {$row['id']}";
            } elseif ($this->plain($destination->name) !== $this->plain($row['name'])) {
                $errors[] = "id {$row['id']} is '{$destination->name}', not '{$row['name']}'";
            }
            if (! in_array($row['field'], self::FIELDS, true)) {
                $errors[] = "field '{$row['field']}' is not one of: ".implode(', ', self::FIELDS);
            }
            if (strlen($row['source']) < 8) {
                $errors[] = 'a source (a link or a named document and date) is required';
            }
            if ($row['value'] === '') {
                $errors[] = 'value is empty; unknown stays blank, so leave the row out';
            }
            if (! $errors) {
                $errors = $this->checkValue($row['field'], $row['value'], $destination);
            }

            $current = $row['field'] === 'category_tag'
                ? $this->tagIfPresent($destination, $row['value'])
                : ($destination?->{$row['field']} ?? null);
            $bad += $errors ? 1 : 0;
            $plan[] = ['line' => $line, 'destination' => $destination, 'row' => $row, 'current' => $current, 'errors' => $errors];
        }

        $this->table(['line', 'id', 'name', 'field', 'now', 'new', 'result'], array_map(fn ($p) => [
            $p['line'], $p['row']['id'], mb_substr($p['row']['name'], 0, 34), $p['row']['field'],
            $p['current'] === null || $p['current'] === '' ? '(blank)' : mb_substr((string) $p['current'], 0, 22), mb_substr($p['row']['value'], 0, 22),
            $p['errors'] ? 'ERROR: '.implode('; ', $p['errors']) : ($this->same($p['current'], $p['row']['value']) ? 'no change' : 'will update'),
        ], $plan));

        $this->line(count($plan).' rows read, '.(count($plan) - $bad)." ok, {$bad} with errors.");

        if ($bad > 0 || $plan === []) {
            $this->error('Nothing changed. Fix the rows marked ERROR (or the empty file) and run again.');

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing written. Add --apply to make these changes.');

            return self::SUCCESS;
        }

        $changed = 0;
        DB::transaction(function () use ($plan, &$changed, $file) {
            foreach ($plan as $p) {
                if ($this->same($p['current'], $p['row']['value'])) {
                    continue;
                }
                $destination = $p['destination'];
                if ($p['row']['field'] === 'category_tag') {
                    // a file may list the same tag twice; the second is already there by now
                    if ($this->tagIfPresent($destination, $p['row']['value']) !== null) {
                        continue;
                    }
                    $before = null;
                    DestinationTag::create(['destination_id' => $destination->id, 'kind' => 'category', 'value' => $p['row']['value']]);
                } else {
                    $before = $destination->{$p['row']['field']};
                    $destination->{$p['row']['field']} = $p['row']['value'];
                    $destination->save();
                }
                $changed++;

                Log::info('destinations:apply-details', [
                    'file' => basename($file), 'destination_id' => $destination->id, 'field' => $p['row']['field'],
                    'from' => $before, 'to' => $p['row']['value'], 'source' => $p['row']['source'],
                ]);
            }
        });

        $this->info("Updated {$changed} values. Each change is recorded in the application log with its source.");

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function checkValue(string $field, string $value, Destination $destination): array
    {
        switch ($field) {
            case 'latitude':
            case 'longitude':
                [$min, $max] = $field === 'latitude' ? self::LAT : self::LNG;

                return (! is_numeric($value) || $value < $min || $value > $max)
                    ? ["{$field} '{$value}' is not a number inside the Davao Region ({$min} to {$max})"] : [];
            case 'hours':
                return OpeningHours::windows($value) === null
                    ? ["hours '{$value}' is not a plain daily schedule like '9:00 AM–5:00 PM', so the planner could not read it"] : [];
            case 'price_tier':
                return in_array($value, self::TIERS, true) ? [] : ['price_tier must be one of: '.implode(', ', self::TIERS)];
            case 'entry_fee_min':
            case 'entry_fee_max':
                return (! is_numeric($value) || $value < 0) ? ["{$field} must be a number of pesos, 0 or more"] : [];
            case 'category_tag':
                return preg_match('/^[A-Za-z][A-Za-z &\-]{1,39}$/', $value) ? [] : ['category_tag must be a short word or phrase, such as Nature or Cultural Heritage'];
            default:
                return strlen($value) > 60 ? ["{$field} is longer than 60 characters"] : [];
        }
    }

    /** The tag as the place already has it (any letter case), or null. */
    private function tagIfPresent(?Destination $destination, string $value): ?string
    {
        if (! $destination) {
            return null;
        }

        $found = DestinationTag::where('destination_id', $destination->id)->where('kind', 'category')
            ->get()->first(fn ($tag) => mb_strtolower($tag->value) === mb_strtolower($value));

        return $found ? $value : null;
    }

    /** 7.0282000 (as stored) and 7.0282 (as written in the file) are the same value. */
    private function same(mixed $current, string $value): bool
    {
        if (is_numeric($current) && is_numeric($value)) {
            return abs((float) $current - (float) $value) < 1e-9;
        }

        return (string) $current === $value;
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
        $missing = array_diff(['id', 'name', 'field', 'value', 'source'], $header);
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
            foreach (['id', 'name', 'field', 'value', 'source'] as $col) {
                $row[$col] = trim((string) ($cells[array_search($col, $header, true)] ?? ''));
            }
            $rows[$line] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
