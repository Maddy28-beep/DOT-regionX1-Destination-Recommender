<?php

namespace App\Services\Embeddings;

use App\Models\Destination;
use App\Models\DestinationEmbedding;

/**
 * A read-only health check of the stored destination embeddings, from the point of view of what the planner needs:
 * ThemeDayGrouper can only regroup a trip whose every stop has a usable vector, so one missing or broken vector for a
 * destination the planner can offer quietly turns theme-based days off for every trip that includes it.
 *
 * It reads; it never writes, builds, imports or calls the embedding model. Safe to run against production.
 *
 * "Plannable" = what the planner may offer: accredited, not archived, and not marked itinerary_role = excluded. Those are
 * the destinations whose coverage matters; the rest are reported separately so their absence is never mistaken for a gap.
 */
class EmbeddingDiagnostics
{
    /** A stored vector is unit length up to rounding (the shipped file keeps 6 decimals); further off than this is reported. */
    private const NORM_TOLERANCE = 0.01;

    /** Two different places with vectors this close to identical are reported as probable duplicates. */
    private const DUPLICATE_SIMILARITY = 0.9999;

    /** Problems that stop a destination from taking part in theme-based days. */
    private const BLOCKING = ['missing', 'empty', 'not_numeric', 'zero_length', 'wrong_dimensions'];

    public function __construct(private readonly DestinationEmbeddingService $embeddings) {}

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $destinations = Destination::with('tags')->orderBy('id')->get();
        $rows = DestinationEmbedding::all()->keyBy('destination_id');
        $configuredModel = $this->embeddings->model();

        $plannable = $destinations->filter(fn (Destination $d) => $this->isPlannable($d));
        $label = fn (Destination $d): array => ['id' => $d->id, 'slug' => $d->slug, 'name' => $d->name];

        // The dimension most vectors share is the expected one.
        $dimensionCounts = $rows->map(fn (DestinationEmbedding $e) => is_array($e->vector) ? count($e->vector) : 0)->countBy()->sortDesc();
        $expectedDimensions = $dimensionCounts->isEmpty() ? null : (int) $dimensionCounts->keys()->first();

        $problems = [];   // destination id => list of problem codes
        $unitVectors = [];
        foreach ($destinations as $d) {
            $row = $rows->get($d->id);
            if ($row === null) {
                $problems[$d->id][] = 'missing';

                continue;
            }

            $vectorProblem = DestinationEmbeddingService::vectorProblem($row->vector, $expectedDimensions);
            if ($vectorProblem !== null) {
                $problems[$d->id][] = $vectorProblem;
            } else {
                $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $row->vector)));
                if (abs($norm - 1.0) > self::NORM_TOLERANCE) {
                    $problems[$d->id][] = 'not_unit_length';
                }
                $unitVectors[$d->id] = $row->vector;
            }

            if ((int) $row->dimensions !== (is_array($row->vector) ? count($row->vector) : 0)) {
                $problems[$d->id][] = 'dimensions_field_disagrees_with_vector';
            }
            if ($row->model !== $configuredModel) {
                $problems[$d->id][] = 'other_model';
            }
            if ($row->text_hash !== sha1($configuredModel.'|'.$this->embeddings->textFor($d))) {
                $problems[$d->id][] = 'stale_text_hash';
            }
        }

        $duplicates = $this->duplicatePairs($unitVectors, $destinations->keyBy('id'));
        foreach ($duplicates as $pair) {
            $problems[$pair['a']['id']][] = 'near_identical_to_another_destination';
            $problems[$pair['b']['id']][] = 'near_identical_to_another_destination';
        }

        $blockedPlannable = $plannable->filter(fn (Destination $d) => array_intersect($problems[$d->id] ?? [], self::BLOCKING) !== []);
        $usable = $plannable->count() - $blockedPlannable->count();

        $byProblem = [];
        foreach ($problems as $id => $codes) {
            foreach (array_unique($codes) as $code) {
                $byProblem[$code][] = $id;
            }
        }
        ksort($byProblem);

        return [
            'configured_model' => $configuredModel,
            'expected_dimensions' => $expectedDimensions,
            'destinations' => $destinations->count(),
            'embedding_rows' => $rows->count(),
            'plannable' => [
                'count' => $plannable->count(),
                'with_usable_vector' => $usable,
                'coverage_percent' => $plannable->count() > 0 ? round($usable / $plannable->count() * 100, 1) : null,
                'excluded_from_theme_days' => $blockedPlannable->map($label)->values()->all(),
            ],
            'not_plannable' => [
                'not_accredited_or_archived' => $destinations->filter(fn (Destination $d) => ! $d->is_accredited || $d->archived_at !== null)->count(),
                'itinerary_role_excluded' => $destinations->filter(fn (Destination $d) => $d->itinerary_role === 'excluded')->count(),
            ],
            'models' => $rows->countBy('model')->all(),
            'dimensions' => $dimensionCounts->all(),
            'problems' => collect($byProblem)
                ->map(fn (array $ids) => $destinations->filter(fn (Destination $d) => in_array($d->id, $ids, true))->map($label)->values()->all())
                ->all(),
            'near_identical_pairs' => $duplicates,
            'embeddings_for_unknown_destinations' => $rows->keys()->diff($destinations->pluck('id'))->values()->all(),
            'shipped_file' => $this->againstShippedFile($destinations, $rows),
            'healthy' => $blockedPlannable->isEmpty()
                && array_intersect_key($byProblem, array_flip(['other_model', 'not_unit_length', 'dimensions_field_disagrees_with_vector'])) === [],
        ];
    }

    public function isPlannable(Destination $d): bool
    {
        return (bool) $d->is_accredited && $d->archived_at === null && $d->itinerary_role !== 'excluded';
    }

    /**
     * Pairs of different destinations whose vectors are all but the same. Not an error: two listings of one kind of place
     * can read alike. It is reported because it is a common sign that two rows were copied or generated from the same text.
     *
     * @param  array<int, array<int, float>>  $vectors
     * @return list<array{a: array, b: array, similarity: float}>
     */
    private function duplicatePairs(array $vectors, $destinations): array
    {
        $ids = array_keys($vectors);
        $pairs = [];
        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                $sim = DestinationEmbeddingService::similarity($vectors[$ids[$i]], $vectors[$ids[$j]]);
                if ($sim >= self::DUPLICATE_SIMILARITY) {
                    $a = $destinations[$ids[$i]];
                    $b = $destinations[$ids[$j]];
                    $pairs[] = [
                        'a' => ['id' => $a->id, 'slug' => $a->slug],
                        'b' => ['id' => $b->id, 'slug' => $b->slug],
                        'similarity' => round($sim, 6),
                    ];
                }
            }
        }

        return $pairs;
    }

    /**
     * How the database compares with database/data/destination-embeddings.json, the file the live server loads them from.
     *
     * @return array<string, mixed>
     */
    private function againstShippedFile($destinations, $rows): array
    {
        $path = base_path(DestinationEmbeddingService::EXPORT_PATH);
        if (! is_file($path)) {
            return ['present' => false];
        }

        $data = json_decode((string) file_get_contents($path), true);
        $entries = is_array($data['embeddings'] ?? null) ? $data['embeddings'] : [];

        $notInFile = [];
        $hashDiffers = [];
        foreach ($destinations as $d) {
            if ($rows->get($d->id) === null) {
                continue;
            }
            if (! isset($entries[$d->slug])) {
                $notInFile[] = $d->slug;
            } elseif (($entries[$d->slug]['text_hash'] ?? null) !== $rows->get($d->id)->text_hash) {
                $hashDiffers[] = $d->slug;
            }
        }

        return [
            'present' => true,
            'generated_at' => $data['generated_at'] ?? null,
            'entries' => count($entries),
            'in_database_but_not_in_file' => $notInFile,
            'text_hash_differs_from_database' => $hashDiffers,
            'in_file_but_no_destination' => array_values(array_diff(array_keys($entries), $destinations->pluck('slug')->all())),
        ];
    }
}
