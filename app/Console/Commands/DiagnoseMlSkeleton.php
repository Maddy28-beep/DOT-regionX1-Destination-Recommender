<?php

namespace App\Console\Commands;

use App\Models\Destination;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItinerarySkeletonMlService;
use Illuminate\Console\Command;

/**
 * Real-inference accuracy testing for ItinerarySkeletonMlService.
 *
 * Every trial here makes a genuine HTTP call to the local Ollama server —
 * nothing is faked, mocked, or hardcoded. This is deliberately a separate
 * command from the phpunit suite: ItinerarySkeletonMlServiceTest verifies the
 * *code* is correct (validation, repair, fallback) against controlled fake
 * responses, which proves nothing about how often the real 3.8B model
 * actually gets a closed-set arrangement right. This command measures that,
 * and only that.
 *
 * Three distinct rates are reported, per the manuscript's own caution about
 * not overstating what a small local model can be shown to do:
 *
 *   Raw LLM validity rate    = trials where the model's own output already
 *                              satisfied every constraint, no repair needed.
 *   Post-repair validity rate = raw-valid OR successfully repaired.
 *   Final itinerary success rate = post-repair OR the existing nearest
 *                              -neighbor fallback -- this one is expected to
 *                              sit at 100%, because a real trip is still
 *                              generated either way; it is not a measure of
 *                              the model's accuracy at all.
 */
class DiagnoseMlSkeleton extends Command
{
    protected $signature = 'recommendation:diagnose-ml
        {--trials=30 : Number of real Ollama calls to make}
        {--min-candidates=3 : Smallest candidate set size per trial}
        {--max-candidates=6 : Largest candidate set size per trial}
        {--show-failures : Print the raw model output for every raw-invalid trial}';

    protected $description = 'Run real (non-mocked) inference trials against the local Ollama model and report raw/post-repair/final validity rates.';

    public function handle(ItinerarySkeletonMlService $skeletonMl): int
    {
        if (! $skeletonMl->isConfigured()) {
            $this->error('PHI4MINI_URL is not configured — nothing to test against.');

            return self::FAILURE;
        }

        $pool = Destination::where('is_accredited', true)
            ->whereNull('archived_at')
            ->whereNotNull('latitude')
            ->get();

        if ($pool->count() < (int) $this->option('max-candidates')) {
            $this->warn(sprintf(
                'Only %d mapped destinations available; falling back to the full accredited catalogue (coordinates optional).',
                $pool->count()
            ));
            $pool = Destination::where('is_accredited', true)->whereNull('archived_at')->get();
        }

        $trials = (int) $this->option('trials');
        $minCandidates = (int) $this->option('min-candidates');
        $maxCandidates = min((int) $this->option('max-candidates'), $pool->count());
        $showFailures = (bool) $this->option('show-failures');

        $this->info("Running {$trials} REAL inference trials against ".config('services.phi4mini.url').' ('.config('services.phi4mini.model').")...");
        $this->line('');

        $rows = [];
        $rawValidCount = 0;
        $repairedCount = 0;
        $fallbackCount = 0;
        $duplicateFailures = 0;
        $missingFailures = 0;
        $unknownFailures = 0;
        $times = [];
        $failureExamples = [];
        $repairSuccessExamples = [];

        for ($t = 1; $t <= $trials; $t++) {
            $count = random_int($minCandidates, $maxCandidates);
            $picked = $pool->shuffle()->take($count)->values();

            $sequence = [];
            foreach ($picked as $i => $destination) {
                $sequence[] = [
                    'row' => ['destination' => $destination, 'drs' => 3.5],
                    'distance_km' => $i === 0 ? 0.0 : (float) random_int(2, 15),
                ];
            }

            $totalDays = max(1, (int) ceil($count / 2));
            $dayCapacities = [];
            for ($d = 1; $d <= $totalDays; $d++) {
                $dayCapacities[$d] = ['Morning', 'Afternoon'];
            }

            $preference = new TouristPreference([
                'travel_purpose' => 'Leisure', 'budget' => 'Mid-range', 'distance_pref' => 'moderate',
            ]);

            $skeleton = $skeletonMl->proposeSkeleton($sequence, $dayCapacities, $preference, null);
            $diagnostics = $skeletonMl->lastDiagnostics;
            $elapsed = $skeletonMl->lastResponseSeconds ?? 0.0;
            $times[] = $elapsed;

            $rawValid = $diagnostics['raw_valid'] ?? false;
            $repaired = $diagnostics['repaired'] ?? false;
            $duplicateIds = $diagnostics['duplicate_ids'] ?? [];
            $missingIds = $diagnostics['missing_ids'] ?? [];
            $unknownValues = $diagnostics['unknown_values'] ?? [];
            $fellBack = $skeleton === null;

            $rawValidCount += $rawValid ? 1 : 0;
            $repairedCount += $repaired ? 1 : 0;
            $fallbackCount += $fellBack ? 1 : 0;
            $duplicateFailures += $duplicateIds !== [] ? 1 : 0;
            $missingFailures += ($missingIds !== [] && ! $repaired) ? 1 : 0;
            $unknownFailures += $unknownValues !== [] ? 1 : 0;

            $status = $rawValid ? 'RAW VALID' : ($repaired ? 'REPAIRED' : ($fellBack ? 'FALLBACK' : 'UNKNOWN'));

            $rows[] = [
                $t, $count, $totalDays, $status,
                $duplicateIds !== [] ? implode(',', $duplicateIds) : '-',
                $missingIds !== [] ? implode(',', $missingIds) : '-',
                $unknownValues !== [] ? implode(',', $unknownValues) : '-',
                number_format($elapsed, 2).'s',
            ];

            if (! $rawValid && count($failureExamples) < 5) {
                $failureExamples[] = [
                    'trial' => $t,
                    'candidates' => $picked->pluck('id')->all(),
                    'raw_output' => $skeletonMl->lastRawDecoded,
                    'status' => $status,
                    'final' => $skeleton,
                ];
            }

            if ($repaired && count($repairSuccessExamples) < 3) {
                $repairSuccessExamples[] = [
                    'trial' => $t,
                    'raw_output' => $skeletonMl->lastRawDecoded,
                    'repaired_into' => $skeleton,
                ];
            }

            $this->output->write('.');
        }

        $this->line('');
        $this->line('');
        $this->table(
            ['#', 'Candidates', 'Days', 'Status', 'Duplicate ids', 'Missing ids', 'Unknown values', 'Time'],
            $rows
        );

        $postRepairCount = $rawValidCount + $repairedCount;
        $finalSuccessCount = $trials - $fallbackCount;

        $this->line('');
        $this->info('=== Results (real Ollama inference, no mocking) ===');
        $this->line(sprintf('Raw LLM validity rate:        %d/%d (%.1f%%)', $rawValidCount, $trials, $rawValidCount / $trials * 100));
        $this->line(sprintf('  - trials with a duplicate id:  %d', $duplicateFailures));
        $this->line(sprintf('  - trials with a missing id:    %d', $missingFailures));
        $this->line(sprintf('  - trials with an unknown value: %d', $unknownFailures));
        $this->line(sprintf('Post-repair validity rate:    %d/%d (%.1f%%)  [%d raw-valid + %d repaired]', $postRepairCount, $trials, $postRepairCount / $trials * 100, $rawValidCount, $repairedCount));
        $this->line(sprintf('Final itinerary success rate: %d/%d (%.1f%%)  [always includes the nearest-neighbor fallback]', $finalSuccessCount, $trials, $finalSuccessCount / $trials * 100));
        $this->line(sprintf('Fallback rate (model output unusable even after repair): %d/%d (%.1f%%)', $fallbackCount, $trials, $fallbackCount / $trials * 100));
        $this->line(sprintf('Average response time: %.2fs (min %.2fs, max %.2fs)', array_sum($times) / count($times), min($times), max($times)));

        if ($showFailures && $failureExamples !== []) {
            $this->line('');
            $this->info('=== Raw-invalid examples (up to 5) ===');
            foreach ($failureExamples as $example) {
                $this->line("Trial #{$example['trial']} — candidates: ".implode(', ', $example['candidates']).' — '.$example['status']);
                $this->line('  raw model output: '.json_encode($example['raw_output']));
                $this->line('  final result:      '.($example['final'] === null ? 'null (fallback)' : json_encode($example['final'])));
                $this->line('');
            }
        }

        if ($repairSuccessExamples !== []) {
            $this->line('');
            $this->info('=== Repair-succeeded examples: previously-failing raw output that now yields a valid itinerary ===');
            foreach ($repairSuccessExamples as $example) {
                $this->line("Trial #{$example['trial']}:");
                $this->line('  raw (invalid) model output: '.json_encode($example['raw_output']));
                $this->line('  repaired into:               '.json_encode($example['repaired_into']));
                $this->line('');
            }
        }

        return self::SUCCESS;
    }
}
