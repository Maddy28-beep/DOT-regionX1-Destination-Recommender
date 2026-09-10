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
 * souvenir decision exactly as it did before this class existed.
 *
 * The model is given a closed list of candidates and cannot introduce a
 * destination outside it: every id it returns is checked against the input
 * set in validate(), and the whole response is discarded on the first thing
 * that does not check out — there is no partial acceptance. A discarded
 * response means the caller gets null, and ItineraryScheduleBuilder then
 * uses the original nearest-neighbor queue order exactly as it always has:
 * this class can only ever refine a plan that already works, never break one.
 */
class ItinerarySkeletonMlService
{
    public function isConfigured(): bool
    {
        return filled(config('services.phi4mini.url'));
    }

    /**
     * @param  array<int, array{row: array{destination: \App\Models\Destination, drs: float}, distance_km: float|null}>  $sequence  Haversine/heuristic-ordered stops (ItineraryGenerationService::sequenceByNearestNeighbor)
     * @param  array<int, array<int, string>>  $dayCapacities  day number => slot names it can hold
     * @param  array{name: string, apriori_confidence: float}|null  $accommodationHint
     * @return array<int, array<int, array{destination_id: int, slot: string}>>|null  day number => ordered stops, or null on any failure/invalid output
     */
    public function proposeSkeleton(
        array $sequence,
        array $dayCapacities,
        TouristPreference $preference,
        ?array $accommodationHint,
    ): ?array {
        if (! $this->isConfigured() || empty($sequence)) {
            return null;
        }

        $candidates = $this->buildCandidates($sequence);

        try {
            $response = Http::timeout((int) config('services.phi4mini.timeout', 12))
                ->post(rtrim(config('services.phi4mini.url'), '/').'/api/generate', [
                    'model' => config('services.phi4mini.model', 'phi4-mini'),
                    'prompt' => $this->buildPrompt($candidates, $dayCapacities, $preference, $accommodationHint),
                    'format' => $this->jsonSchema(),
                    'stream' => false,
                    'options' => ['temperature' => 0.2],
                ]);
        } catch (\Throwable $e) {
            Log::warning('Phi-4-mini itinerary skeleton request failed', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('Phi-4-mini itinerary skeleton request returned an error', ['status' => $response->status()]);

            return null;
        }

        $raw = $response->json('response');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($decoded)) {
            Log::warning('Phi-4-mini itinerary skeleton returned unparseable output', ['raw' => $raw]);

            return null;
        }

        $skeleton = $this->validate($decoded, $candidates, $dayCapacities);

        if ($skeleton === null) {
            Log::warning('Phi-4-mini itinerary skeleton failed validation', ['decoded' => $decoded]);
        }

        return $skeleton;
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

    private function buildPrompt(array $candidates, array $dayCapacities, TouristPreference $preference, ?array $accommodationHint): string
    {
        $payload = [
            'total_days' => count($dayCapacities),
            'day_capacities' => $dayCapacities,
            'candidates' => $candidates,
            'accommodation_hint' => $accommodationHint,
            'preferences' => [
                'travel_purpose' => $preference->travel_purpose,
                'budget' => $preference->budget,
                'distance_pref' => $preference->distance_pref,
            ],
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $ids = implode(', ', array_column($candidates, 'id'));
        $count = count($candidates);

        return <<<PROMPT
        You are arranging an already-decided list of travel stops into a day-by-day skeleton. You must NOT add, remove, or substitute any stop — use only the destination ids given in "candidates". Keep stops in roughly the given sequence_position order across days where reasonable, since that order already reflects geographic proximity computed by a separate distance-based algorithm.

        Data:
        {$json}

        Checklist before you answer — your "stops" arrays across ALL days, combined, must satisfy every one of these:
        1. There are exactly {$count} candidates. Your output must contain exactly {$count} stops in total, no more and no fewer.
        2. Every one of these destination_id values must appear exactly once, each in exactly one day: {$ids}
        3. Do not repeat a destination_id. Do not omit any destination_id from the list above.
        4. Each day's stop count must not exceed the number of slots listed for it in "day_capacities".
        5. Every day_number must be between 1 and total_days.

        Respond with JSON only, matching the required schema. Before finalizing, count your stops and verify the count equals {$count} and every id from the list above was used exactly once.
        PROMPT;
    }

    /** Ollama's structured-output schema: constrains decoding, doesn't just hope the prompt is followed. */
    private function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'day_number' => ['type' => 'integer'],
                            'stops' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'destination_id' => ['type' => 'integer'],
                                        'slot' => ['type' => 'string'],
                                    ],
                                    'required' => ['destination_id', 'slot'],
                                ],
                            ],
                        ],
                        'required' => ['day_number', 'stops'],
                    ],
                ],
            ],
            'required' => ['days'],
        ];
    }

    /**
     * Enforces every validation rule the implementation plan requires. Any
     * single failure discards the whole response — there is no partial
     * acceptance of a day that looked fine next to one that didn't.
     *
     * @param  array<int, array{id: int}>  $candidates
     * @param  array<int, array<int, string>>  $dayCapacities
     * @return array<int, array<int, array{destination_id: int, slot: string}>>|null
     */
    private function validate(array $decoded, array $candidates, array $dayCapacities): ?array
    {
        $days = $decoded['days'] ?? null;
        if (! is_array($days)) {
            return null;
        }

        $candidateIds = array_column($candidates, 'id');
        $seen = [];
        $grouped = [];

        foreach ($days as $day) {
            $dayNumber = $day['day_number'] ?? null;
            $stops = $day['stops'] ?? null;

            if (! is_int($dayNumber) || ! is_array($stops)) {
                return null;
            }

            // Rule: no day_number may exceed total_days (or be otherwise out of range).
            if (! array_key_exists($dayNumber, $dayCapacities)) {
                return null;
            }

            // Rule: no day may exceed its available capacity.
            if (count($stops) > count($dayCapacities[$dayNumber])) {
                return null;
            }

            $dayGroup = [];
            foreach ($stops as $stop) {
                $id = $stop['destination_id'] ?? null;
                $slot = $stop['slot'] ?? null;

                if (! is_int($id) || ! is_string($slot) || $slot === '') {
                    return null;
                }

                // Rule: every destination_id must exist in the candidate list
                // — this is what stops the model inventing a destination.
                if (! in_array($id, $candidateIds, true)) {
                    return null;
                }

                // Rule: every candidate must appear exactly once.
                if (isset($seen[$id])) {
                    return null;
                }
                $seen[$id] = true;

                $dayGroup[] = ['destination_id' => $id, 'slot' => $slot];
            }

            $grouped[$dayNumber] = $dayGroup;
        }

        // Rule: every candidate must appear exactly once — across the whole
        // response, not just without duplicates within a single day.
        if (count($seen) !== count($candidateIds)) {
            return null;
        }

        return $grouped;
    }
}
