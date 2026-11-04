<?php

namespace App\Services\Recommendation;

use App\Models\TouristPreference;
use App\Services\Chatbot\GroqChatbotClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The "AI-assisted skeleton" stage (thesis objective 1.3.2.2).
 *
 * Given the stops the planner has ALREADY chosen, it asks a language model (Groq, through the chatbot's client) which of
 * them belong on the same day, and for a short theme for each day. It is advice only:
 *
 *  - Only the planner's own stops can appear. The model's answer must use every candidate id exactly once, split into
 *    exactly the number of stops each day holds; anything else (unknown id, repeated id, missing id, wrong day sizes,
 *    malformed JSON, slow or failed call) is discarded and the planner's plan is used unchanged.
 *  - It never sees or influences opening hours, accreditation, travel times or the Remote last-day rule. Inside a day the
 *    stops keep the planner's own route order. The caller (ItineraryGenerationService) still builds the day with the
 *    ordinary schedule builder, which enforces all of those, and keeps the suggestion only if the built plan places at
 *    least as many stops and is no less feasible than the planner's own plan.
 *  - A suggestion that would travel more than ThemeDayGrouper's limit over the planner's plan is discarded here.
 *
 * What is sent: the number of days and stops per day, the distance range, budget level and ticked interests, and for each
 * candidate its id, name, type, price level and category tags. No origin, coordinates, dates, times, browser token or any
 * text the visitor typed. The prompt itself is never logged.
 */
class AiSkeletonAdvisor
{
    public const MAX_THEME_LENGTH = 40;

    public function __construct(private readonly GroqChatbotClient $client) {}

    /** On when a key is configured and AI_SKELETON_ENABLED is not switched off. Never on without a key. */
    public function enabled(): bool
    {
        $flag = config('ai-skeleton.enabled');
        if ($flag !== null && $flag !== '' && filter_var($flag, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false) {
            return false;
        }

        return $this->client->isConfigured();
    }

    /**
     * @param  array<int, array{row: array, distance_km: float|null}>  $baseline  the planner's own stop order (what is built without this stage)
     * @param  array<int, array{row: array, distance_km: float|null}>  $route  the plain route order, used to order stops within a day
     * @param  array<int, array<int, string>>  $dayCapacities  day number => slot labels
     * @param  array{lat: float, lng: float}  $origin  used only locally for the distance check, never sent
     * @return array{attempted: bool, seconds: float|null, plan: array{sequence: array, days: array<int, array{theme: string, stops: array<int, int>}>}|null, reason: string}
     */
    public function advise(TouristPreference $preference, array $baseline, array $route, array $dayCapacities, array $origin): array
    {
        $none = fn (string $reason, bool $attempted = false, ?float $seconds = null): array => ['attempted' => $attempted, 'seconds' => $seconds, 'plan' => null, 'reason' => $reason];

        if (! $this->enabled()) {
            return $none('disabled');
        }

        $sizes = $this->daySizes($dayCapacities, count($baseline));
        $max = max(1, (int) config('ai-skeleton.max_candidates', 24));
        if (count($sizes) < 2 || count($baseline) < 3 || count($baseline) > $max) {
            return $none('not_applicable');
        }

        $candidates = $this->candidates($route);
        $started = microtime(true);

        try {
            $raw = $this->client->complete(
                $this->systemPrompt(),
                json_encode($this->request($preference, $candidates, $sizes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                max(1, (int) config('ai-skeleton.timeout', 8)),
            );
        } catch (Throwable $e) {
            // Class name only: the exception text can carry the response body, and nothing from the prompt is logged.
            Log::warning('AI skeleton call failed, using the planner order.', ['error' => $e::class]);

            return $none('call_failed', true, round(microtime(true) - $started, 2));
        }
        $seconds = round(microtime(true) - $started, 2);

        $days = $this->validate($raw, array_keys($candidates), $sizes);
        if ($days === null) {
            return $none('invalid_answer', true, $seconds);
        }

        $sequence = $this->sequenceFor($days, $route);
        if ($this->sameOrder($sequence, $baseline)) {
            return $none('same_as_planner', true, $seconds);
        }

        if ($this->travel($sequence, $sizes, $origin) > $this->travel($baseline, $sizes, $origin) * (1 + ThemeDayGrouper::MAX_EXTRA_DISTANCE) + ThemeDayGrouper::DISTANCE_ALLOWANCE_KM) {
            return $none('too_much_travel', true, $seconds);
        }

        return ['attempted' => true, 'seconds' => $seconds, 'plan' => ['sequence' => $sequence, 'days' => $days], 'reason' => 'candidate'];
    }

    /**
     * Strict schema: {"days":[{"day":1,"theme":"...","destination_ids":[1,2]}, ...]} with every candidate used exactly once
     * and each day holding exactly the number of stops the planner gave it. Returns day number => theme and stop ids, or null.
     *
     * @param  array<int, int>  $candidateIds
     * @param  array<int, int>  $sizes  day number => stops
     * @return array<int, array{theme: string, stops: array<int, int>}>|null
     */
    public function validate(string $raw, array $candidateIds, array $sizes): ?array
    {
        $json = trim($raw);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $json, $m)) {
            $json = $m[1];
        }

        $data = json_decode($json, true);
        if (! is_array($data) || ! isset($data['days']) || ! is_array($data['days']) || ! array_is_list($data['days'])) {
            return null;
        }
        if (count($data['days']) !== count($sizes)) {
            return null;
        }

        $dayNumbers = array_keys($sizes);
        $known = array_flip($candidateIds);
        $seen = [];
        $days = [];

        foreach ($data['days'] as $i => $entry) {
            if (! is_array($entry) || ! isset($entry['day'], $entry['theme'], $entry['destination_ids'])) {
                return null;
            }
            $day = $dayNumbers[$i];
            if (! is_int($entry['day']) || $entry['day'] !== $day || ! is_string($entry['theme']) || ! is_array($entry['destination_ids']) || ! array_is_list($entry['destination_ids'])) {
                return null;
            }
            if (count($entry['destination_ids']) !== $sizes[$day]) {
                return null;
            }

            $ids = [];
            foreach ($entry['destination_ids'] as $id) {
                if (! is_int($id) || ! isset($known[$id]) || isset($seen[$id])) {
                    return null;
                }
                $seen[$id] = true;
                $ids[] = $id;
            }

            $theme = $this->cleanTheme($entry['theme']);
            if ($theme === null) {
                return null;
            }
            $days[$day] = ['theme' => $theme, 'stops' => $ids];
        }

        return count($seen) === count($candidateIds) ? $days : null;
    }

    /** A short plain-text label: tags and control characters removed, whitespace collapsed, no longer than MAX_THEME_LENGTH. */
    private function cleanTheme(string $theme): ?string
    {
        $theme = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $theme);
        $theme = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $theme)));
        $theme = trim((string) preg_replace('/[<>{}\[\]`]/u', '', $theme));

        if ($theme === '' || mb_strlen($theme) > self::MAX_THEME_LENGTH) {
            return null;
        }

        return $theme;
    }

    /**
     * The model's day grouping as a stop sequence: days in order, and inside a day the planner's own route order.
     *
     * @param  array<int, array{theme: string, stops: array<int, int>}>  $days
     * @return array<int, array{row: array, distance_km: float|null}>
     */
    private function sequenceFor(array $days, array $route): array
    {
        $position = [];
        foreach ($route as $i => $entry) {
            $position[$entry['row']['destination']->id] = $i;
        }

        $sequence = [];
        foreach ($days as $day) {
            $ids = $day['stops'];
            usort($ids, fn (int $a, int $b) => $position[$a] <=> $position[$b]);
            foreach ($ids as $id) {
                $sequence[] = $route[$position[$id]];
            }
        }

        return $sequence;
    }

    private function sameOrder(array $a, array $b): bool
    {
        $ids = fn (array $s): array => array_map(fn (array $e) => $e['row']['destination']->id, $s);

        return $ids($a) === $ids($b);
    }

    /**
     * @param  array<int, array{row: array, distance_km: float|null}>  $route
     * @return array<int, array<string, mixed>> destination id => the facts sent about it
     */
    private function candidates(array $route): array
    {
        $out = [];
        foreach ($route as $entry) {
            $d = $entry['row']['destination'];
            $d->loadMissing('tags');
            $out[$d->id] = [
                'id' => $d->id,
                'name' => $d->name,
                'type' => $d->type,
                'price_tier' => $d->price_tier,
                'tags' => $d->tags->where('kind', 'category')->pluck('value')->map(fn ($v) => trim((string) $v))->filter()->unique()->values()->take(6)->all(),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function request(TouristPreference $preference, array $candidates, array $sizes): array
    {
        return [
            'trip' => [
                'days' => count($sizes),
                'stops_per_day' => array_values($sizes),
                'distance_range' => $preference->distance_pref,
                'budget' => $preference->budget,
                'interests' => $preference->activities->pluck('activity')->values()->all(),
            ],
            'candidates' => array_values($candidates),
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
You help a tourism planner for Davao Region, Philippines split an already-chosen list of places into days.
The user message is JSON: a trip (days, stops_per_day, distance_range, budget, interests) and candidates (id, name, type, price_tier, tags).
Decide which candidates go together on each day so that each day has a coherent theme, matching the traveller's interests where possible, and give each day a theme of at most 40 characters.
Rules: use every candidate id exactly once; day N must contain exactly stops_per_day[N-1] ids; do not invent ids; no other text.
Reply with JSON only, exactly: {"days":[{"day":1,"theme":"short theme","destination_ids":[1,2]}]}
TXT;
    }

    /**
     * @param  array<int, array{row: array}>  $sequence
     * @param  array<int, int>  $sizes
     */
    private function travel(array $sequence, array $sizes, array $origin): float
    {
        $offset = 0;
        $total = 0.0;
        foreach ($sizes as $size) {
            $points = [];
            foreach (array_slice($sequence, $offset, $size) as $entry) {
                $d = $entry['row']['destination'];
                if ($d->latitude !== null && $d->longitude !== null) {
                    $points[] = [(float) $d->latitude, (float) $d->longitude];
                }
            }
            $offset += $size;

            $here = [(float) $origin['lat'], (float) $origin['lng']];
            while ($points) {
                $best = null;
                $bestKm = null;
                foreach ($points as $k => $p) {
                    $km = $this->haversineKm($here[0], $here[1], $p[0], $p[1]);
                    if ($bestKm === null || $km < $bestKm) {
                        $bestKm = $km;
                        $best = $k;
                    }
                }
                $total += $bestKm;
                $here = $points[$best];
                unset($points[$best]);
            }
        }

        return $total;
    }

    /** @return array<int, int> */
    public function daySizes(array $dayCapacities, int $stops): array
    {
        ksort($dayCapacities);
        $remaining = $stops;
        $sizes = [];
        foreach ($dayCapacities as $day => $slots) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, count($slots));
            if ($take > 0) {
                $sizes[$day] = $take;
                $remaining -= $take;
            }
        }

        return $sizes;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
