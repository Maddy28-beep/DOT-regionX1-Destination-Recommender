<?php

namespace App\Console\Commands;

use App\Services\Embeddings\EmbeddingDiagnostics;
use Illuminate\Console\Command;

class EmbeddingsDiagnose extends Command
{
    protected $signature = 'embeddings:diagnose {--json : Print the full report as JSON}';

    protected $description = 'Read-only health check of the stored destination embeddings that theme-based days depend on (never writes, never calls the embedding model)';

    public function handle(EmbeddingDiagnostics $diagnostics): int
    {
        $report = $diagnostics->run();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $report['healthy'] ? self::SUCCESS : self::FAILURE;
        }

        $plannable = $report['plannable'];
        $this->line("Model configured: {$report['configured_model']}; expected dimensions: ".($report['expected_dimensions'] ?? 'n/a'));
        $this->line("Destinations: {$report['destinations']}; embedding rows: {$report['embedding_rows']}");
        $this->line("Plannable destinations (accredited, not archived, not role=excluded): {$plannable['count']}");
        $this->line("  with a usable vector: {$plannable['with_usable_vector']} ({$plannable['coverage_percent']}%)");
        foreach ($plannable['excluded_from_theme_days'] as $d) {
            $this->error("  LEFT OUT of theme-based days: #{$d['id']} {$d['slug']}");
        }
        $this->line("Not plannable: {$report['not_plannable']['not_accredited_or_archived']} not accredited/archived, {$report['not_plannable']['itinerary_role_excluded']} role=excluded (no vector needed)");

        foreach ($report['problems'] as $code => $list) {
            $this->warn("{$code}: ".count($list));
            foreach (array_slice($list, 0, 15) as $d) {
                $this->line("    #{$d['id']} {$d['slug']}");
            }
        }
        foreach ($report['near_identical_pairs'] as $pair) {
            $this->warn("near-identical vectors: {$pair['a']['slug']} / {$pair['b']['slug']} ({$pair['similarity']})");
        }
        $this->line($report['healthy'] ? 'Healthy: every plannable destination can take part in theme-based days.' : 'NOT healthy: see above.');

        return $report['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
