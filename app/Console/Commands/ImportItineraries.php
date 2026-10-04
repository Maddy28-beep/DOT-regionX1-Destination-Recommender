<?php

namespace App\Console\Commands;

use App\Models\ExitSurvey;
use App\Services\Recommendation\ItineraryBasketImporter;
use Illuminate\Console\Command;

class ImportItineraries extends Command
{
    protected $signature = 'itineraries:import
                            {files* : One or more CSVs coded from published itineraries (one row per place per day)}
                            {--dry-run : Report what would be imported without saving anything}
                            {--reset : First delete every itinerary basket imported earlier, then import the given files (pass ALL your batch files together)}
                            {--skip=* : An itinerary id to leave out, e.g. --skip=I52 --skip=I53 (repeatable)}
                            {--max-items=10 : Skip any day with more matched places than this}';

    protected $description = 'Import published itineraries as Apriori baskets (data_source = itinerary)';

    public function handle(ItineraryBasketImporter $importer): int
    {
        $dry = (bool) $this->option('dry-run');
        $importer->ignoreExisting = (bool) $this->option('reset');
        $importer->skipItineraries = array_map('trim', (array) $this->option('skip'));

        if ($this->option('reset')) {
            $existing = ExitSurvey::withoutGlobalScopes()->where('data_source', ItineraryBasketImporter::SOURCE)->count();

            if ($dry) {
                $this->line("<comment>Dry run: would first delete {$existing} existing itinerary basket(s).</comment>");
            } elseif ($existing > 0 && ! $this->confirm("Delete {$existing} existing itinerary basket(s) and re-import from the given files?")) {
                $this->warn('Cancelled; nothing changed.');

                return self::FAILURE;
            } elseif ($existing > 0) {
                ExitSurvey::withoutGlobalScopes()->where('data_source', ItineraryBasketImporter::SOURCE)->delete();
                $this->info("Deleted {$existing} itinerary basket(s).");
            }
        }

        $total = null;
        $unmatched = [];
        $ambiguous = [];
        $large = [];

        foreach ((array) $this->argument('files') as $file) {
            try {
                $r = $importer->import($file, $dry, (int) $this->option('max-items'));
            } catch (\RuntimeException $e) {
                $this->error($file.': '.$e->getMessage());

                return self::FAILURE;
            }

            $this->line('<info>'.basename($file).'</info>');
            $total = $total === null ? $r : $this->add($total, $r);
            foreach ($r['unmatched'] as $place => $n) {
                $unmatched[$place] = ($unmatched[$place] ?? 0) + $n;
            }
            $ambiguous += $r['ambiguous'];
            $large = array_merge($large, $r['large_baskets']);
        }

        arsort($unmatched);

        $this->line($dry ? '<comment>Dry run: nothing was saved.</comment>' : '');
        $this->table(['Result', 'Count'], [
            ['Rows read', $total['rows']],
            ['Itineraries', $total['itineraries']],
            ['Itinerary days', $total['days']],
            ['Rows matched to a catalogue place', $total['rows_matched']],
            ['Rows skipped (not a place: airport, terminal, area name...)', $total['rows_not_a_place']],
            ['Rows with no catalogue match', $total['rows_unmatched']],
            ['Baskets created', $total['baskets_created']],
            ['Baskets skipped: fewer than two matched places', $total['baskets_too_small']],
            ['Baskets skipped: too many places (likely a whole page)', $total['baskets_too_large']],
            ['Baskets skipped: a copy of a fuller plan on the same page', $total['baskets_repeat']],
            ['Baskets skipped: already imported', $total['baskets_duplicate']],
        ]);

        if ($large) {
            $this->warn('Oversized days (check these against the source): '.implode('; ', $large));
        }

        if ($unmatched) {
            $this->line('Most-mentioned places with no catalogue match (candidates to add or alias):');
            $this->table(['Place as written', 'Rows'], collect($unmatched)->take(25)->map(fn ($n, $p) => [$p, $n])->values()->all());
        }

        if ($ambiguous) {
            $this->warn('Fit more than one catalogue row, so left unmatched: '.collect($ambiguous)->map(fn ($c, $p) => $p.' ['.implode(', ', $c).']')->implode('; '));
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b @return array<string, mixed> */
    private function add(array $a, array $b): array
    {
        foreach (['rows', 'itineraries', 'days', 'rows_matched', 'rows_not_a_place', 'rows_unmatched', 'baskets_created', 'baskets_too_small', 'baskets_too_large', 'baskets_repeat', 'baskets_duplicate'] as $key) {
            $a[$key] += $b[$key];
        }

        return $a;
    }
}
