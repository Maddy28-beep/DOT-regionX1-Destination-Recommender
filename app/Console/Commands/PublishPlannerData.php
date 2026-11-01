<?php

namespace App\Console\Commands;

use App\Services\PlannerDataPatch;
use Illuminate\Console\Command;

class PublishPlannerData extends Command
{
    protected $signature = 'planner:publish-data {manifest : Path to the reviewed JSON patch} {--apply : Apply atomically; otherwise preview only} {--report= : Optional JSON report path}';

    protected $description = 'Preview or apply a sourced planner data patch while preserving unrelated live records and later edits';

    public function handle(PlannerDataPatch $patch): int
    {
        try {
            $manifest = json_decode(file_get_contents($this->argument('manifest')), true, 512, JSON_THROW_ON_ERROR);
            $result = $patch->run($manifest, (bool) $this->option('apply'));
            $text = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if ($this->option('report')) {
                file_put_contents($this->option('report'), $text);
            }
            $this->line($text);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
