<?php

namespace App\Console\Commands;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use App\Support\SampleData;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Renames sample listings that carry the name of a real business, so a sample can never be read as that business.
 * Reads a CSV (header row required): table, id, name, new_name, reason.
 *
 * Safety rules, all checked before anything is written (all or nothing; without --apply nothing is written):
 *   - the id AND the current name must match the row in this database, so a file made on another copy cannot hit the wrong row;
 *   - the listing must be sample data (every accreditation record a placeholder). A listing with a genuine DOT record is never renamed;
 *   - the new name must not already belong to another listing of the same table;
 *   - the slug follows the new name (a slug that still spelled the real name would give it away in the address);
 *   - the old name inside the listing's own text (description, address line) is replaced by the new one.
 * The stored vectors for a renamed listing describe the old name, so run the embedding refresh afterwards.
 * --revert applies the file backwards (new_name back to name), for a rename that should not have been made.
 */
class AnonymiseSampleListings extends Command
{
    protected $signature = 'listings:anonymise-samples
        {file : CSV file to read}
        {--apply : Write the changes (default is a report only)}
        {--revert : Apply the file backwards: new_name back to name}';

    protected $description = 'Rename sample listings that use a real business name (dry run by default).';

    private const MODELS = [
        'destinations' => Destination::class,
        'accommodations' => Accommodation::class,
        'restaurants' => Restaurant::class,
        'souvenir_centers' => SouvenirCenter::class,
        'tour_operators' => TourOperator::class,
    ];

    /** Text columns where the old name is replaced by the new one, when the listing has them. */
    private const TEXT_COLUMNS = ['description', 'tagline', 'address', 'location'];

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error('Give the path of a CSV file that exists.');

            return self::FAILURE;
        }

        $revert = (bool) $this->option('revert');
        $rows = $this->read($file);
        if ($rows === null) {
            $this->error('The CSV needs the columns: table, id, name, new_name.');

            return self::FAILURE;
        }

        $sampleData = app(SampleData::class);
        $sampleData->flush();

        $plan = [];
        $errors = [];
        $takenNew = [];

        foreach ($rows as $line => $row) {
            $from = $revert ? $row['new_name'] : $row['name'];
            $to = $revert ? $row['name'] : $row['new_name'];
            $modelClass = self::MODELS[$row['table']] ?? null;

            if ($modelClass === null) {
                $errors[] = "Line {$line}: unknown table '{$row['table']}'.";

                continue;
            }

            /** @var Model|null $listing */
            $listing = $modelClass::query()->find((int) $row['id']);
            if ($listing === null) {
                $errors[] = "Line {$line}: {$row['table']} #{$row['id']} does not exist here.";

                continue;
            }
            if ($listing->name === $to) {
                $plan[] = ['skip' => true, 'line' => $line, 'table' => $row['table'], 'id' => $listing->id, 'from' => $from, 'to' => $to];

                continue;
            }
            if ($listing->name !== $from) {
                $errors[] = "Line {$line}: {$row['table']} #{$row['id']} is called '{$listing->name}' here, not '{$from}'. Nothing was written.";

                continue;
            }
            if ($sampleData->status($listing) !== SampleData::SAMPLE) {
                $errors[] = "Line {$line}: {$row['table']} #{$row['id']} '{$from}' is not sample data ({$sampleData->status($listing)}); a listing with a genuine record is never renamed.";

                continue;
            }
            if ($modelClass::query()->where('name', $to)->whereKeyNot($listing->getKey())->exists() || isset($takenNew[$row['table'].'|'.$to])) {
                $errors[] = "Line {$line}: another {$row['table']} listing is already called '{$to}'.";

                continue;
            }

            $takenNew[$row['table'].'|'.$to] = true;
            $plan[] = ['skip' => false, 'line' => $line, 'table' => $row['table'], 'id' => $listing->id, 'from' => $from, 'to' => $to, 'listing' => $listing];
        }

        $this->table(['Table', 'Id', 'From', 'To', 'Action'], array_map(
            fn ($p) => [$p['table'], $p['id'], $p['from'], $p['to'], $p['skip'] ? 'already done' : 'rename'],
            $plan,
        ));

        foreach ($errors as $e) {
            $this->error($e);
        }
        if ($errors !== []) {
            $this->warn('Nothing was written: fix the rows above first.');

            return self::FAILURE;
        }

        $todo = array_values(array_filter($plan, fn ($p) => ! $p['skip']));
        if (! $this->option('apply')) {
            $this->info(count($todo).' listing(s) would be renamed. Run again with --apply to write them. Then refresh the embeddings.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($todo) {
            foreach ($todo as $p) {
                /** @var Model $listing */
                $listing = $p['listing'];
                $oldBase = trim(str_ireplace('[SAMPLE]', '', $p['from']));
                $newBase = trim(str_ireplace('[SAMPLE]', '', $p['to']));

                $listing->name = $p['to'];
                $listing->slug = $this->freeSlug($listing, Str::slug($newBase));
                foreach (self::TEXT_COLUMNS as $column) {
                    if ($oldBase !== '' && filled($listing->{$column} ?? null) && str_contains((string) $listing->{$column}, $oldBase)) {
                        $listing->{$column} = str_replace($oldBase, $newBase, (string) $listing->{$column});
                    }
                }
                $listing->save();

                Log::info('listings:anonymise-samples', ['table' => $p['table'], 'id' => $listing->getKey(), 'from' => $p['from'], 'to' => $p['to']]);
            }
        });

        app(SampleData::class)->flush();
        $this->info(count($todo).' listing(s) renamed. Now refresh the embeddings for them so the recommender reads the new names.');

        return self::SUCCESS;
    }

    private function freeSlug(Model $listing, string $slug): string
    {
        $candidate = $slug;
        $n = 2;
        while ($listing::query()->where('slug', $candidate)->whereKeyNot($listing->getKey())->exists()) {
            $candidate = $slug.'-'.$n++;
        }

        return $candidate;
    }

    /** @return array<int, array{table: string, id: string, name: string, new_name: string}>|null  keyed by CSV line number */
    private function read(string $file): ?array
    {
        $handle = fopen($file, 'r');
        $header = fgetcsv($handle);
        $header = $header ? array_map(fn ($h) => trim(ltrim((string) $h, "\xEF\xBB\xBF")), $header) : [];
        foreach (['table', 'id', 'name', 'new_name'] as $required) {
            if (! in_array($required, $header, true)) {
                fclose($handle);

                return null;
            }
        }

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if ($cells === [null] || count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $rows[$line] = array_map(fn ($v) => trim((string) $v), array_combine($header, array_pad($cells, count($header), '')));
        }
        fclose($handle);

        return $rows;
    }
}
