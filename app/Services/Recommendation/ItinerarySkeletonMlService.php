<?php

namespace App\Services\Recommendation;

use App\Models\TouristPreference;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pretrained ML inference step (manuscript Sec. 2.3.4, "Pretrained ML Model").
 *
 * Takes the destinations Content-Based Recommendation ranked and the
 * Haversine + Nearest Neighbor sequence the heuristic already computed, and
 * asks a local Phi-4-mini-instruct model — served by Ollama
 * (https://ollama.com), inference-only, no fine-tuning or training involved —
 * to group them into a skeletal day/slot arrangement. It decides ONLY which
 * day (and rough slot) each already-chosen stop belongs to;
 * ItineraryScheduleBuilder still owns every timing, meal, accommodation and
 * souvenir decision exactly as it did before this class existed, and does
 * not even read the "slot" label this class assigns — only which day a
 * destination_id landed on and its order within that day.
 *
 * Three layers keep this safe even though the model is small (3.8B) and,
 * empirically, gets the closed-set constraint wrong a meaningful fraction of
 * the time:
 *
 *   1. STRUCTURED OUTPUT narrows what the model can even say. The wire
 *      format is not a free-form "days: [...]" array but a schema built
 *      fresh per request: one "day_N" object per day that still has an
 *      unfilled slot, each holding only the slot properties that day
 *      actually needs (see buildDaySlotPlan() — a short catalogue can leave
 *      fewer candidates than declared day capacity, so "capacity" is a
 *      ceiling, never a mandatory fill), each property constrained to the
 *      closed candidate id set via a JSON Schema enum. This makes "invented
 *      id" and "wrong slot count" difficults to produce at all, and reduces
 *      "missing id" to only ever mean a request property the model left out.
 *
 *   2. DETERMINISTIC REPAIR handles the one failure mode structured output
 *      cannot rule out by itself: the model reusing one valid id in two
 *      slots. Because the schema fixes the total slot count to exactly the
 *      candidate count, a duplicate anywhere always leaves exactly one
 *      candidate unplaced elsewhere — resolve() finds every such gap
 *      (unfilled, duplicate, or unknown-value slot) and fills it with a
 *      leftover candidate in the model's original nearest-neighbor order,
 *      never introducing an id that was not already in the candidate list.
 *
 *   3. The existing FALLBACK guarantee is unchanged: if resolve() still
 *      cannot produce a fully valid grouping (structurally impossible input,
 *      a network failure, an unconfigured model), proposeSkeleton() returns
 *      null and the caller uses the original nearest-neighbor order exactly
 *      as it always has. This class can only ever refine a plan that already
 *      works, never break one.
 *
 * lastDiagnostics exposes what happened on the most recent call — whether
 * the raw model output was already valid, whether repair had to run, and
 * exactly which ids were duplicated/missing/unknown — for the real-inference
 * accuracy testing this class is deliberately built to be measured by
 * (see routes/console.php's `recommendation:diagnose-ml` command).
 */
class ItinerarySkeletonMlService
{
    /**
     * Diagnostics from the most recent proposeSkeleton() call, or null before
     * any call. Not part of the return value itself (which every existing
     * caller already treats as a plain nullable array) — a side channel in
     * the same spirit as ContentBasedRecommendationService::$lastRangeTierUsed.
     *
     * @var array{raw_valid: bool, repaired: bool, duplicate_ids: array<int, int>, unknown_values: array<int, mixed>, missing_ids: array<int, int>, failure_stage: ?string}|null
     */
    public ?array $lastDiagnostics = null;

    /** The raw decoded model response from the most recent call, for diagnostics/inspection — not part of the return contract. */
    public ?array $lastRawDecoded = null;

    /** Wall-clock seconds the most recent HTTP round trip to Ollama took, or null if no request was sent. */
    public ?float $lastResponseSeconds = null;

    public function isConfigured(): bool
    {
        return filled(config('services.phi4mini.url'));
    }

    /**
     * @param  array<int, array{row: array{destination: \App\Models\Destination, drs: float}, distance_km: float|null}>  $sequence  Haversine/heuristic-ordered stops (ItineraryGenerationService::sequenceByNearestNeighbor)
     * @param  array<int, array<int, string>>  $dayCapacities  day number => slot names it can hold (a ceiling, not a mandatory fill)
     * @param  array{name: string, apriori_confidence: float}|null  $accommodationHint
     * @return array<int, array<int, array{destination_id: int, slot: string}>>|null  day number => ordered stops, or null on any failure/unrepairable output
     */
    public function proposeSkeleton(
        array $sequence,
        array $dayCapacities,
        TouristPreference $preference,
        ?array $accommodationHint,
    ): ?array {
        $this->lastDiagnostics = null;
        $this->lastRawDecoded = null;
        $this->lastResponseSeconds = null;

        if (! $this->isConfigured() || empty($sequence)) {
            return null;
        }

        $candidates = $this->buildCandidates($sequence);
        $plan = $this->buildDaySlotPlan($dayCapacities, count($candidates));

        if ($plan === []) {
            return null;
        }

        $candidateIds = array_column($candidates, 'id');
        $startedAt = microtime(true);

        try {
            $response = Http::timeout((int) config('services.phi4mini.timeout', 20))
                ->post(rtrim(config('services.phi4mini.url'), '/').'/api/generate', [
                    'model' => config('services.phi4mini.model', 'phi4-mini'),
                    'prompt' => $this->buildPrompt($candidates, $plan, $preference, $accommodationHint),
                    'format' => $this->jsonSchema($plan, $candidateIds),
                    'stream' => false,
                    // Low and close to deterministic: this is a closed-set
                    // arrangement task, not open-ended generation, so sampling
                    // randomness only costs correctness here and buys nothing.
                    'options' => ['temperature' => (float) config('services.phi4mini.temperature', 0.1)],
                ]);
        } catch (\Throwable $e) {
            $this->lastResponseSeconds = microtime(true) - $startedAt;
            Log::warning('Phi-4-mini itinerary skeleton request failed', ['error' => $e->getMessage()]);
            $this->lastDiagnostics = $this->emptyDiagnostics('request_failed');

            return null;
        }

        $this->lastResponseSeconds = microtime(true) - $startedAt;

        if ($response->failed()) {
            Log::warning('Phi-4-mini itinerary skeleton request returned an error', ['status' => $response->status()]);
            $this->lastDiagnostics = $this->emptyDiagnostics('http_error');

            return null;
        }

        $raw = $response->json('response');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($decoded)) {
            Log::warning('Phi-4-mini itinerary skeleton returned unparseable output', ['raw' => $raw]);
            $this->lastDiagnostics = $this->emptyDiagnostics('unparseable');

            return null;
        }

        $this->lastRawDecoded = $decoded;

        $result = $this->resolve($decoded, $plan, $candidateIds);
        $this->lastDiagnostics = $result['diagnostics'];

        if ($result['grouped'] === null) {
            Log::warning('Phi-4-mini itinerary skeleton failed validation and could not be repaired', [
                'decoded' => $decoded,
                'diagnostics' => $result['diagnostics'],
            ]);
        }

        return $result['grouped'];
    }

    /** @return array<int, array{id: int, name: string, type: ?string, drs: float, distance_from_previous_km: float|null, sequence_position: int}> */
    private function buildCandidates(array $sequence): array
    {
        $out = [];
        foreach (array_values($sequence) as $position => $entry) {
            $destination = $entry['row']['destination'];
            $out[] = [
                'id' => $destination->id,
                'name' => $destination->name,
                'type' => $destination->type,
                'drs' => $entry['row']['drs'],
                'distance_from_previous_km' => $entry['distance_km'],
                'sequence_position' => $position + 1,
            ];
        }

        return $out;
    }

    /**
     * Exactly which (day, slot-property) pairs the model actually needs to
     * fill, in fill order — never more than there are candidates for.
     *
     * $dayCapacities is a ceiling per day (ItineraryScheduleBuilder's own
     * queue-consumption loop already stops early once it runs out of stops,
     * without complaint), not a count every day must reach. Building the
     * schema straight from $dayCapacities without this trim would force the
     * model to fill slots that should not exist for a short candidate list —
     * every accredited-destination catalogue this project has seeded is well
     * short of "every day fully booked for a long trip" — and the only way
     * to satisfy that with too few real ids is to duplicate one, manufacturing
     * exactly the failure this class exists to prevent.
     *
     * @param  array<int, array<int, string>>  $dayCapacities
     * @return array<int, array<string, string>>  day number => [slot property key => original slot label], in fill order
     */
    private function buildDaySlotPlan(array $dayCapacities, int $candidateCount): array
    {
        $remaining = $candidateCount;
        $plan = [];
        ksort($dayCapacities);

        foreach ($dayCapacities as $day => $labels) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, count($labels));
            $props = [];
            for ($i = 0; $i < $take; $i++) {
                $props[$this->propKey($labels[$i])] = $labels[$i];
            }

            if ($props !== []) {
                $plan[$day] = $props;
                $remaining -= $take;
            }
        }

        return $plan;
    }

    /** "Morning" -> "morning"; safe against any future slot vocabulary, not just the current Morning/Afternoon/Evening set. */
    private function propKey(string $label): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $label), '_'));
    }

    private function buildPrompt(array $candidates, array $plan, TouristPreference $preference, ?array $accommodationHint): string
    {
        $candidateLines = implode("\n", array_map(fn (array $c) => sprintf(
            '- id %d: %s (%s) — stop #%d in the nearest-neighbor order%s',
            $c['id'],
            $c['name'],
            $c['type'] ?? 'unspecified type',
            $c['sequence_position'],
            $c['distance_from_previous_km'] !== null
                ? sprintf(', %.1f km from the previous stop', $c['distance_from_previous_km'])
                : ''
        ), $candidates));

        $schemaOutline = implode("\n", array_map(
            fn (array $props, int $day) => "  \"day_{$day}\": { ".implode(', ', array_keys($props)).' }',
            $plan,
            array_keys($plan)
        ));

        $candidateIds = array_column($candidates, 'id');
        $idList = implode(', ', $candidateIds);
        $count = count($candidateIds);

        $context = json_encode([
            'travel_purpose' => $preference->travel_purpose,
            'budget' => $preference->budget,
            'distance_pref' => $preference->distance_pref,
            'accommodation_hint' => $accommodationHint,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
        You are an itinerary SCHEDULING assistant. You are NOT a destination recommender. A separate system has already chosen, ranked, and ordered every destination for this trip — your only job is deciding which day and time-of-day slot each already-chosen destination goes in. You must NOT choose, add, remove, or substitute any destination.

        THE CANDIDATE SET IS CLOSED. These are the only {$count} destination ids that exist for this task, already listed in nearest-neighbor order (stop #1 is closest to the traveller's start, stop #2 is next, and so on):
        {$candidateLines}

        Keep stops in roughly this order within and across days — it already reflects geographic proximity computed by a separate distance-based algorithm — unless a slot assignment genuinely requires reordering.

        RULES — your answer is INVALID if you break any of these:
        1. Use each candidate id EXACTLY ONCE across your entire answer.
        2. NEVER repeat an id in more than one slot.
        3. NEVER omit an id — every one of the {$count} ids above must appear somewhere.
        4. NEVER invent an id that is not in the closed set above.
        5. Fill every property in the structure below with a real candidate id — do not leave one out and do not add extra properties.

        EXAMPLE with placeholder ids 10, 20, 30 (not your real candidates — your real ids are listed above):
          Candidates: 10, 20, 30
          Structure:  {"day_1": {"morning": ?, "afternoon": ?}, "day_2": {"morning": ?}}
          VALID answer:   {"day_1": {"morning": 10, "afternoon": 20}, "day_2": {"morning": 30}}
            — every id (10, 20, 30) appears exactly once. Nothing repeated, nothing missing.
          INVALID answer: {"day_1": {"morning": 10, "afternoon": 20}, "day_2": {"morning": 10}}
            — WRONG: id 10 is used twice and id 30 never appears. This exact mistake gets an answer rejected.

        Trip context:
        {$context}

        Fill in exactly this structure, one candidate id per property, using only the ids listed above ({$idList}):
        {
        {$schemaOutline}
        }

        Respond with JSON only, matching the required schema exactly — no prose, no explanation. Before answering, verify: does every id from {$idList} appear exactly once across your whole answer, with nothing repeated and nothing invented?
        PROMPT;
    }

    /**
     * Ollama's structured-output schema (constrains decoding, not just the
     * prompt): one object per day still needing stops, each property
     * constrained to the closed candidate id set via `enum` so an invented
     * or out-of-range id is difficult for the model to produce at all, and
     * `required` fixed to exactly that day's real slot count so the total
     * across the whole response always equals the candidate count — never
     * $dayCapacities' raw ceiling, which can be higher than the candidate
     * list on a short catalogue (see buildDaySlotPlan()).
     *
     * What this cannot enforce — because JSON Schema has no cross-property
     * uniqueness constraint — is the same valid id appearing in two
     * different properties. That one remaining failure mode is exactly what
     * resolve()'s deterministic repair step exists to fix.
     *
     * @param  array<int, array<string, string>>  $plan
     * @param  array<int, int>  $candidateIds
     */
    private function jsonSchema(array $plan, array $candidateIds): array
    {
        $dayProperties = [];
        $required = [];

        foreach ($plan as $day => $props) {
            $properties = [];
            foreach (array_keys($props) as $propKey) {
                $properties[$propKey] = ['type' => 'integer', 'enum' => $candidateIds];
            }

            $dayKey = "day_{$day}";
            $dayProperties[$dayKey] = [
                'type' => 'object',
                'properties' => $properties,
                'required' => array_keys($props),
                'additionalProperties' => false,
            ];
            $required[] = $dayKey;
        }

        return [
            'type' => 'object',
            'properties' => $dayProperties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * Validates the decoded response against $plan and, if it falls short,
     * deterministically repairs it rather than discarding it outright.
     *
     * Every slot $plan calls for is classified as filled (a valid, not
     * -yet-used candidate id) or a gap (absent, an unknown value, or a
     * repeat of an id another slot already claimed). Because $plan always
     * declares exactly as many slots as there are candidates, the count of
     * gaps and the count of never-claimed candidates are always equal by
     * construction — so repair is a straight one-to-one fill: each gap, in
     * the order it appears (day order, then slot order within the day),
     * takes the next unplaced candidate in the model's own nearest-neighbor
     * order. No id is ever introduced that was not already in $candidateIds.
     *
     * @param  array<int, array<string, string>>  $plan
     * @param  array<int, int>  $candidateIds
     * @return array{grouped: ?array<int, array<int, array{destination_id: int, slot: string}>>, diagnostics: array}
     */
    private function resolve(array $decoded, array $plan, array $candidateIds): array
    {
        $candidateIdSet = array_fill_keys($candidateIds, true);

        $slots = [];
        foreach ($plan as $day => $props) {
            foreach ($props as $propKey => $label) {
                $slots[] = ['day' => $day, 'prop' => $propKey, 'label' => $label];
            }
        }

        $claimed = [];
        $duplicateIds = [];
        $unknownValues = [];
        $assignments = array_fill(0, count($slots), null);

        foreach ($slots as $i => $slot) {
            $dayObj = $decoded['day_'.$slot['day']] ?? null;
            $present = is_array($dayObj) && array_key_exists($slot['prop'], $dayObj);
            $id = $present ? $this->toWholeInt($dayObj[$slot['prop']]) : null;

            if ($id === null) {
                // Present but not a whole number (a string, null, a fraction)
                // is worth recording as an unknown value, not silently
                // treated the same as the property being absent entirely.
                if ($present && $dayObj[$slot['prop']] !== null) {
                    $unknownValues[] = $dayObj[$slot['prop']];
                }

                continue; // a gap to repair either way
            }

            if (! isset($candidateIdSet[$id])) {
                $unknownValues[] = $dayObj[$slot['prop']];

                continue; // not in the closed set -- dropped, never used as-is
            }

            if (isset($claimed[$id])) {
                $duplicateIds[] = $id;

                continue; // already used by an earlier slot -- this occurrence is a gap
            }

            $claimed[$id] = true;
            $assignments[$i] = $id;
        }

        $missingIds = array_values(array_filter($candidateIds, fn (int $id) => ! isset($claimed[$id])));
        $gapCount = count(array_filter($assignments, fn ($a) => $a === null));

        $rawValid = $gapCount === 0 && $duplicateIds === [] && $unknownValues === [];

        $diagnostics = [
            'raw_valid' => $rawValid,
            'repaired' => false,
            'duplicate_ids' => array_values(array_unique($duplicateIds)),
            'unknown_values' => $unknownValues,
            'missing_ids' => $missingIds,
            'failure_stage' => null,
        ];

        if ($rawValid) {
            return ['grouped' => $this->materialize($slots, $assignments), 'diagnostics' => $diagnostics];
        }

        /*
         * $plan always declares exactly count($candidateIds) slots, so gaps
         * and missing ids are always the same count -- this check is a
         * defensive invariant, not a case expected to trigger, and exists so
         * a future bug here fails safe (full fallback) instead of silently
         * mis-assigning.
         */
        if ($gapCount !== count($missingIds)) {
            $diagnostics['failure_stage'] = 'repair_count_mismatch';

            return ['grouped' => null, 'diagnostics' => $diagnostics];
        }

        $cursor = 0;
        foreach ($assignments as $i => $id) {
            if ($id === null) {
                $assignments[$i] = $missingIds[$cursor];
                $cursor++;
            }
        }

        $diagnostics['repaired'] = true;

        return ['grouped' => $this->materialize($slots, $assignments), 'diagnostics' => $diagnostics];
    }

    /** @return array<int, array<int, array{destination_id: int, slot: string}>> */
    private function materialize(array $slots, array $assignments): array
    {
        $grouped = [];
        foreach ($slots as $i => $slot) {
            $grouped[$slot['day']][] = ['destination_id' => $assignments[$i], 'slot' => $slot['label']];
        }
        ksort($grouped);

        return $grouped;
    }

    private function emptyDiagnostics(string $failureStage): array
    {
        return [
            'raw_valid' => false,
            'repaired' => false,
            'duplicate_ids' => [],
            'unknown_values' => [],
            'missing_ids' => [],
            'failure_stage' => $failureStage,
        ];
    }

    /**
     * Accepts a JSON-decoded integer OR a whole-number float (some JSON
     * encoders write a schema-valid "integer" field as e.g. 1.0 rather than
     * 1), and rejects anything else — a fractional number, a numeric string,
     * null. Returns the value as a genuine PHP int so every caller can keep
     * comparing/indexing with strict types.
     */
    private function toWholeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return null;
    }
}
