<?php

namespace App\Console\Commands;

use App\Models\AccreditationRecord;
use App\Models\EstablishmentAccount;
use Illuminate\Console\Command;

/**
 * Brings existing listings in line with the DOT working dataset (database/data/dot-dataset-*.json).
 *
 * Deliberately conservative -- it only does what the dataset can prove:
 *
 *  - Coordinates: a listing with no pin takes the dataset's point, whatever its precision (an
 *    approximate pin is better than none). A pin already stored is replaced only by a row marked
 *    Verified/Corrected at establishment, building or manually-corrected precision, so an
 *    approximate point never overwrites a better one. A listing run by an approved partner
 *    account is skipped, because its pin may be the owner's own.
 *  - Expiry dates: only ever EXTENDED (a renewal). A day/month swap of the stored date is
 *    ignored -- the dataset's date cells were mis-read by Excel for days 1-12, so a swapped
 *    pair means the stored value (from the original DOT list) is the right one.
 *  - Accreditation numbers that appear twice in the dataset are skipped as ambiguous.
 *
 * Without --apply nothing is written.
 */
class SyncDotDataset extends Command
{
    protected $signature = 'dot:sync-dataset
        {--file=database/data/dot-dataset-2026-09-23.json : Dataset to read}
        {--add-missing : Also add establishments that are on the DOT list but not on the site yet}
        {--apply : Write the changes (default is a report only)}';

    protected $description = 'Update listing coordinates and renewed accreditation dates from the DOT working dataset.';

    private const STRONG = [
        ['Verified', 'Establishment (by name)'],
        ['Verified', 'Exact building'],
        ['Corrected', 'Manually corrected'],
        ['Matched building', 'Villa Oro building'],
    ];

    public function handle(): int
    {
        $path = base_path($this->option('file'));
        if (! is_file($path)) {
            $this->error("Dataset not found: {$path}");

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $rows = json_decode(file_get_contents($path), true)['rows'] ?? [];

        $counts = array_count_values(array_column($rows, 'accno'));
        $partnerOwned = EstablishmentAccount::where('status', 'approved')->whereNotNull('matched_listing_id')
            ->get()->map(fn ($a) => $a->listing_kind.':'.$a->matched_listing_id)->flip();

        $stat = ['coords_set' => 0, 'coords_moved' => 0, 'renewed' => 0, 'not_in_db' => 0, 'ambiguous' => 0, 'partner_skipped' => 0, 'weak_skipped' => 0];
        $log = [];

        foreach ($rows as $row) {
            if ($counts[$row['accno']] > 1) {
                $stat['ambiguous']++;

                continue;
            }

            $record = AccreditationRecord::where('accreditation_number', $row['accno'])->first();
            $listing = $record?->listing;
            if (! $record || ! $listing) {
                $stat['not_in_db']++;

                continue;
            }

            $changes = [];

            // Expiry: renewals only, never a day/month swap.
            if ($row['expiry'] && $record->expiration_date) {
                $db = $record->expiration_date->toDateString();
                $sheet = $row['expiry'];
                if ($sheet > $db && ! $this->isDaySwap($db, $sheet)) {
                    $stat['renewed']++;
                    $log[] = "renew   {$row['accno']} {$row['name']}: {$db} -> {$sheet}";
                    if ($apply) {
                        $record->update(['expiration_date' => $sheet]);
                    }
                }
            }

            // Coordinates.
            if ($row['lat'] === null || $row['lng'] === null) {
                continue;
            }
            if (isset($partnerOwned[$record->listing_kind.':'.$record->listing_id])) {
                $stat['partner_skipped']++;

                continue;
            }

            // The dataset gives every establishment a point, so a listing with NO pin takes it whatever
            // its precision (an approximate pin is better than none -- the planner can then route to it).
            // A pin already stored is only replaced by a Verified/Corrected one.
            $has = $listing->latitude !== null && $listing->longitude !== null;
            $strong = in_array([$row['check'], $row['precision']], self::STRONG, true);
            if ($has && ! $strong) {
                $stat['weak_skipped']++;

                continue;
            }

            $km = $has ? $this->km((float) $listing->latitude, (float) $listing->longitude, $row['lat'], $row['lng']) : null;
            if ($has && $km < 0.05) {
                continue;
            }

            $stat[$has ? 'coords_moved' : 'coords_set']++;
            $log[] = ($has ? 'move    ' : 'pin     ')."{$row['accno']} {$row['name']}".($has ? sprintf(' (%.2f km)', $km) : '');
            if ($apply) {
                $listing->forceFill(['latitude' => $row['lat'], 'longitude' => $row['lng']])->save();
            }
        }

        foreach ($log as $line) {
            $this->line($line);
        }
        $this->newLine();
        $this->info(($apply ? 'APPLIED' : 'DRY RUN (nothing written)').' -- from '.count($rows).' dataset rows');
        $this->table(['What', 'Count'], collect($stat)->map(fn ($v, $k) => [str_replace('_', ' ', $k), $v])->values()->all());

        if ($this->option('add-missing')) {
            $this->addMissing($apply);
        }

        if ($apply) {
            $this->call('accreditation:sync-status');
        }

        return self::SUCCESS;
    }

    private function addMissing(bool $apply): void
    {
        $path = database_path('data/dot-accredited-additions-2026-09-23.json');
        $data = json_decode(file_get_contents($path), true)['listings'] ?? [];
        $rows = collect($data)->flatten(1);
        $new = $rows->reject(fn ($r) => AccreditationRecord::where('accreditation_number', $r['accno'])->exists());

        $this->newLine();
        $this->info(($apply ? 'Adding ' : 'Would add ').$new->count().' of '.$rows->count().' new establishments ('.($rows->count() - $new->count()).' already present):');
        foreach ($new as $r) {
            $this->line("add     {$r['accno']} {$r['name']}".($r['latitude'] ? ' [pinned]' : ' [no pin yet]'));
        }

        if ($apply) {
            (new \Database\Seeders\RealAccreditedEstablishmentSeeder())->seedFile($path);
        }
    }

    /** True when $b is $a with day and month exchanged (e.g. 2027-06-01 vs 2027-01-06). */
    private function isDaySwap(string $a, string $b): bool
    {
        [$ya, $ma, $da] = explode('-', $a);
        [$yb, $mb, $db] = explode('-', $b);

        return $ya === $yb && $ma === $db && $da === $mb;
    }

    private function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p = M_PI / 180;
        $x = sin(($lat2 - $lat1) * $p / 2) ** 2 + cos($lat1 * $p) * cos($lat2 * $p) * sin(($lng2 - $lng1) * $p / 2) ** 2;

        return 12742 * asin(sqrt($x));
    }
}
