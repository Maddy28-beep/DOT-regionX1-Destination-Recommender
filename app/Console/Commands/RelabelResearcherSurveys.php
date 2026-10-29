<?php

namespace App\Console\Commands;

use App\Models\ExitSurvey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-off: the team's own test entries were submitted as real surveys. This moves them to data_source = 'demo'
 * under a batch name, so they stop counting as tourist responses, and can move them back.
 *
 * Not 'test': test surveys are deleted automatically after 24 hours (see AnonymousDataPurger). A demo row with a
 * batch is kept, can be listed, and is reversed with --revert.
 *
 * Which rows: real surveys that name no place at all. A real tourist who answered the survey but visited nowhere
 * would match too, so --expect makes the run stop unless exactly that many rows match.
 *
 * Without --apply nothing is written.
 */
class RelabelResearcherSurveys extends Command
{
    protected $signature = 'surveys:relabel-researcher-tests
        {--batch=researcher-test-2026-10 : Batch name written on the relabelled rows}
        {--expect=5 : Stop unless exactly this many rows match}
        {--revert : Move the batch back to real instead}
        {--apply : Write the change (default is a report only)}';

    protected $description = 'Relabel the researchers\' test surveys from real to demo under a batch name (reversible).';

    public function handle(): int
    {
        if (! Schema::hasColumn('exit_surveys', 'batch')) {
            $this->error('exit_surveys.batch does not exist yet. Run the migrations first.');

            return self::FAILURE;
        }

        $batch = (string) $this->option('batch');
        $expect = (int) $this->option('expect');
        $revert = (bool) $this->option('revert');
        $apply = (bool) $this->option('apply');

        $rows = $revert
            ? ExitSurvey::withoutGlobalScopes()->where('data_source', 'demo')->where('batch', $batch)->get()
            : ExitSurvey::withoutGlobalScopes()->where('data_source', 'real')->whereDoesntHave('visits')->get();

        $this->line(($revert ? "Rows in batch '{$batch}' (demo -> real)" : 'Real surveys that name no place (real -> demo)').': '.$rows->count());
        $this->table(['id', 'submitted_at', 'overall_rating', 'places', 'data_source', 'batch'], $rows->map(fn ($s) => [
            $s->id, $s->submitted_at, $s->overall_rating ?? '-', $s->visits()->count(), $s->data_source, $s->batch ?? '-',
        ])->all());

        if ($rows->count() !== $expect) {
            $this->error("Expected exactly {$expect} rows but found {$rows->count()}. Nothing changed. Check the list above, then pass --expect={$rows->count()} if it is right.");

            return self::FAILURE;
        }

        if (! $apply) {
            $this->info('Dry run: nothing written. Add --apply to '.($revert ? 'move these back to real.' : "label these demo / '{$batch}'."));

            return self::SUCCESS;
        }

        $changed = DB::transaction(fn () => ExitSurvey::withoutGlobalScopes()->whereIn('id', $rows->pluck('id'))
            ->update($revert ? ['data_source' => 'real', 'batch' => null] : ['data_source' => 'demo', 'batch' => $batch]));

        $this->info("Updated {$changed} rows.".($revert ? '' : " To undo: php artisan surveys:relabel-researcher-tests --revert --batch={$batch} --expect={$changed} --apply"));

        return self::SUCCESS;
    }
}
