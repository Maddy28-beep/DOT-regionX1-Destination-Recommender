<?php

namespace App\Console\Commands;

use App\Models\Accommodation;
use App\Models\EstablishmentAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ApplyStayCoordinates extends Command
{
    protected $signature = 'stays:apply-coordinates {--file=database/data/stay-coordinates-2026-11-17.json} {--apply}';
    protected $description = 'Add researched property/complex pins to unpinned stays, preserving existing and partner-owned pins.';

    public function handle(): int
    {
        $path = base_path($this->option('file'));
        if (! is_file($path)) { $this->error('Coordinate manifest not found.'); return self::FAILURE; }
        $data = json_decode(file_get_contents($path), true);
        $rows = $data['updates'] ?? [];
        $seen = [];
        foreach ($rows as $row) {
            if (empty($row['slug']) || empty($row['name']) || isset($seen[$row['slug']])
                || ! is_numeric($row['latitude'] ?? null) || ! is_numeric($row['longitude'] ?? null)
                || $row['latitude'] < 5.3 || $row['latitude'] > 8.4 || $row['longitude'] < 124.7 || $row['longitude'] > 127
                || ! in_array($row['precision'] ?? '', ['property', 'complex'], true)
                || ! filter_var($row['source'] ?? '', FILTER_VALIDATE_URL) || ! str_starts_with($row['source'], 'https://')) {
                $this->error('Invalid or duplicate coordinate manifest entry. Nothing was changed.');
                return self::FAILURE;
            }
            $seen[$row['slug']] = true;
        }
        $changes = [];
        DB::transaction(function () use ($rows, $data, &$changes) {
            foreach ($rows as $row) {
                $stay = Accommodation::where('slug', $row['slug'])->lockForUpdate()->first();
                if (! $stay || $stay->name !== $row['name'] || $stay->archived_at
                    || $stay->latitude !== null || $stay->longitude !== null
                    || EstablishmentAccount::where('status', 'approved')->where('listing_kind', 'accommodation')->where('matched_listing_id', $stay->id)->exists()) {
                    continue;
                }
                $changes[] = ['id' => $stay->id, 'name' => $stay->name, 'before' => ['latitude' => null, 'longitude' => null], 'after' => $row];
                if ($this->option('apply')) {
                    $stay->forceFill([
                        'latitude' => $row['latitude'], 'longitude' => $row['longitude'],
                        'coordinate_provenance' => ['source' => $row['source'], 'precision' => $row['precision'], 'checked_on' => $data['checked_on'] ?? null, 'note' => $row['note'] ?? 'Mapped property point; confirm the entrance before travel.'],
                    ])->save();
                }
            }
        });
        foreach ($changes as $change) $this->line($change['name'].' · '.$change['after']['precision']);
        $this->info(count($changes).($this->option('apply') ? ' pins added.' : ' pins ready (dry run).'));
        if ($this->option('apply') && $changes) {
            File::ensureDirectoryExists(storage_path('app'));
            File::put(storage_path('app/stay-coordinate-changes-'.now()->format('Ymd-His-u').'.json'), json_encode($changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return self::SUCCESS;
    }
}
