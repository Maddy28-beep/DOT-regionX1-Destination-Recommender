<?php

namespace App\Console\Commands;

use App\Models\Accommodation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CorrectResortSettings extends Command
{
    protected $signature = 'planner:correct-resort-settings {--apply : Apply the dated, conditional corrections; otherwise preview only}';

    protected $description = 'Preview or apply sourced resort-setting corrections without overwriting different saved classifications';

    public function handle(): int
    {
        $manifest = json_decode(file_get_contents(database_path('data/resort-setting-corrections-2026-11-01.json')), true, 512, JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($manifest) {
            foreach ($manifest['corrections'] as $row) {
                $stay = Accommodation::where('slug', $row['slug'])->where('name', $row['name'])->first();
                if (! $stay) {
                    $this->line('Not found: '.$row['slug']);
                    continue;
                }
                $expected = Accommodation::encodeResortSettings($row['expected_settings']);
                $corrected = Accommodation::encodeResortSettings($row['settings']);
                if ($stay->resort_settings === $corrected) {
                    $this->line('Already corrected: '.$row['slug']);
                    continue;
                }
                if ($stay->resort_settings !== $expected) {
                    $this->line('Preserved different saved value: '.$row['slug']);
                    continue;
                }
                if (! $this->option('apply')) {
                    $this->line('Would correct: '.$row['slug'].' '.$expected.' -> '.$corrected);
                    continue;
                }
                $updated = Accommodation::whereKey($stay->id)->where('resort_settings', $expected)
                    ->update(['resort_settings' => $corrected]);
                $this->line(($updated ? 'Corrected: ' : 'Preserved concurrent edit: ').$row['slug']);
            }
        });

        return self::SUCCESS;
    }
}
