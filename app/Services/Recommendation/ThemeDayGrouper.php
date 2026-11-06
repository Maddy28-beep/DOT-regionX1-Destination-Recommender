<?php

namespace App\Services\Recommendation;

use App\Models\DestinationEmbedding;
use App\Services\Embeddings\DestinationEmbeddingService;

/**
 * Theme-based days: uses the pretrained embedding vectors to decide WHICH stops
 * share a day, so that a day's places are alike (a wildlife day, a farm day)
 * instead of simply being the next two on a nearest-neighbor route.
 *
 * What it takes: the stops the recommender already chose and ordered, and how many
 * stops each day holds. It never adds, drops, or replaces a stop.
 *
 * What it does: starting from the plain nearest-neighbor grouping, it tries
 * swapping stops between days and keeps a swap only when
 *   1. the days become more alike (sum of pairwise cosine similarity rises), and
 *   2. the trip's total travelling stays within MAX_EXTRA_DISTANCE of the
 *      nearest-neighbor plan (plus a small allowance for very short trips).
 * The search is deterministic, so the same trip always groups the same way.
 *
 * If any stop has no usable vector (missing, empty, not numbers, no length, or a different number of dimensions from the
 * others), nothing is changed -- and skipReason() says so, so that a plan that silently lost its themed days can be seen
 * and counted instead of just looking like a trip where nothing could be improved.
 */
class ThemeDayGrouper
{
    /** The themed plan may travel at most this much further than the plain route. */
    public const MAX_EXTRA_DISTANCE = 0.25;

    /** Kilometres of slack so a very short route is not frozen by the percentage. */
    public const DISTANCE_ALLOWANCE_KM = 3.0;

    private const MAX_ROUNDS = 50;

    /** @var array{reason: string, destination_ids: list<int>}|null why the last group() call left the plan alone for want of embeddings */
    private ?array $skip = null;

    /**
     * Why the last group() call changed nothing because of the embeddings, or null when it ran (or had nothing to do for
     * another reason: fewer than three stops, or a single day).
     *
     * @return array{reason: string, destination_ids: list<int>}|null
     */
    public function skipReason(): ?array
    {
        return $this->skip;
    }

    /**
     * @param  array<int, array{row: array, distance_km: float|null}>  $sequence
     * @param  array<int, array<int, string>>  $dayCapacities  day number => slot labels
     * @param  array{lat: float, lng: float}  $origin
     * @return array{sequence: array, days: array<int, array<string, mixed>>, summary: array<string, mixed>}|null
     */
    public function group(array $sequence, array $dayCapacities, array $origin): ?array
    {
        $this->skip = null;

        $count = count($sequence);
        if ($count < 3) {
            return null;
        }

        $sizes = $this->daySizes($dayCapacities, $count);
        if (count($sizes) < 2) {
            return null;
        }

        $ids = array_map(fn (array $e) => $e['row']['destination']->id, $sequence);
        $vectors = $this->usableVectors($ids);
        if ($vectors === null) {
            return null; // a stop without a usable vector: leave the plan exactly as it is (skipReason() says which)
        }

        // Baseline: the plain nearest-neighbor grouping, in order.
        $groups = [];
        $offset = 0;
        foreach ($sizes as $day => $size) {
            $groups[$day] = array_slice(array_keys($sequence), $offset, $size);
            $offset += $size;
        }

        $sim = fn (int $i, int $j): float => DestinationEmbeddingService::similarity(
            $vectors[$sequence[$i]['row']['destination']->id],
            $vectors[$sequence[$j]['row']['destination']->id],
        );

        $before = $this->score($groups, $sim);
        $typesBefore = $this->typesPerDay($groups, $sequence);
        $distanceBefore = $this->totalDistance($groups, $sequence, $origin);
        $limit = $distanceBefore * (1 + self::MAX_EXTRA_DISTANCE) + self::DISTANCE_ALLOWANCE_KM;

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $best = null;
            $bestGain = 1e-9;
            $current = $this->score($groups, $sim);
            $days = array_keys($groups);

            foreach ($days as $a => $dayA) {
                foreach (array_slice($days, $a + 1) as $dayB) {
                    foreach ($groups[$dayA] as $pa => $stopA) {
                        foreach ($groups[$dayB] as $pb => $stopB) {
                            $trial = $groups;
                            $trial[$dayA][$pa] = $stopB;
                            $trial[$dayB][$pb] = $stopA;

                            $gain = $this->score($trial, $sim) - $current;
                            if ($gain <= $bestGain) {
                                continue;
                            }
                            if ($this->totalDistance($trial, $sequence, $origin) > $limit) {
                                continue;
                            }
                            $bestGain = $gain;
                            $best = $trial;
                        }
                    }
                }
            }

            if ($best === null) {
                break;
            }
            $groups = $best;
        }

        // Within a day, keep the original route order.
        foreach ($groups as $day => $members) {
            sort($members);
            $groups[$day] = $members;
        }

        $reordered = [];
        $dayInfo = [];
        foreach ($groups as $day => $members) {
            foreach ($members as $index) {
                $reordered[] = $sequence[$index];
            }
            $dayInfo[$day] = $this->describe($members, $sequence, $sim);
        }

        $after = $this->score($groups, $sim);
        $pairs = max(1, $this->pairCount($groups));
        $meanBefore = (int) round($before / $pairs * 100);
        $meanAfter = (int) round($after / $pairs * 100);

        return [
            'sequence' => $reordered,
            'days' => $dayInfo,
            'summary' => [
                // "Regrouped" is only claimed when the days really did change AND the page can show
                // a visible gain; a swap that moves the average by less than a whole percentage point
                // is not worth announcing, so the page says the route order was already good.
                'regrouped' => $reordered !== array_values($sequence) && $meanAfter > $meanBefore,
                'mean_similarity_before' => $meanBefore,
                'mean_similarity_after' => $meanAfter,
                'distance_before_km' => round($distanceBefore, 1),
                'distance_after_km' => round($this->totalDistance($groups, $sequence, $origin), 1),
                // An independent check on coherence that does not use the embeddings: the average
                // number of different place types in a day (lower = days are more alike).
                'types_per_day_before' => round($typesBefore, 2),
                'types_per_day_after' => round($this->typesPerDay($groups, $sequence), 2),
            ],
        ];
    }

    /**
     * The stored vector of every stop, or null (with skipReason() set and a warning logged) when one is missing or unusable.
     *
     * @param  list<int>  $ids
     * @return \Illuminate\Support\Collection<int, array<int, float>>|null
     */
    private function usableVectors(array $ids)
    {
        $unique = array_values(array_unique($ids));
        $vectors = DestinationEmbedding::whereIn('destination_id', $unique)->get()->keyBy('destination_id')
            ->map(fn (DestinationEmbedding $e) => $e->vector);

        $missing = array_values(array_filter($unique, fn ($id) => ! $vectors->has($id)));
        if ($missing !== []) {
            return $this->skipped('missing_embeddings', $missing);
        }

        // The dimension most stops share is the expected one; a stop that differs is the odd one out.
        $lengths = $vectors->map(fn ($v) => is_array($v) ? count($v) : 0)->countBy()->sortDesc();
        $expected = (int) $lengths->keys()->first();

        $invalid = [];
        foreach ($vectors as $id => $vector) {
            if (DestinationEmbeddingService::vectorProblem($vector, $expected) !== null) {
                $invalid[] = (int) $id;
            }
        }
        if ($invalid !== []) {
            return $this->skipped('invalid_embeddings', $invalid);
        }

        // Vectors are stored as unit length. One that is clearly not (a hand-edited row) is brought back to unit length,
        // so that a longer vector cannot outweigh the rest; ordinary rounding noise is left exactly as stored.
        return $vectors->map(function (array $v) {
            $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v)));

            return abs($norm - 1.0) > 0.01 ? DestinationEmbeddingService::normalise($v) : $v;
        });
    }

    /** @param  list<int>  $ids */
    private function skipped(string $reason, array $ids)
    {
        sort($ids);
        $this->skip = ['reason' => $reason, 'destination_ids' => $ids];

        try {
            \Illuminate\Support\Facades\Log::warning('Theme-based days skipped: '.$reason.'.', $this->skip);
        } catch (\Throwable) {
            // A log problem must never take an itinerary down.
        }

        return null;
    }

    /**
     * How many stops each day takes, filled in day order exactly as the schedule
     * builder fills them.
     *
     * @return array<int, int>
     */
    private function daySizes(array $dayCapacities, int $stops): array
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

    /** Average number of different place types per day, from the places' own type, not from the vectors. */
    private function typesPerDay(array $groups, array $sequence): float
    {
        $days = 0;
        $types = 0;
        foreach ($groups as $members) {
            $types += count(array_unique(array_map(fn (int $i) => (string) $sequence[$i]['row']['destination']->type, $members)));
            $days++;
        }

        return $days > 0 ? $types / $days : 0.0;
    }

    /** How many pairs of stops share a day. */
    private function pairCount(array $groups): int
    {
        return array_sum(array_map(fn (array $m) => count($m) * (count($m) - 1) / 2, $groups));
    }

    /** Sum over days of the similarity of every pair of stops sharing that day. */
    private function score(array $groups, callable $sim): float
    {
        $total = 0.0;
        foreach ($groups as $members) {
            $n = count($members);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $total += $sim($members[$i], $members[$j]);
                }
            }
        }

        return $total;
    }

    /** Travelling for the whole trip: each day walked nearest-first from the origin. Unknown positions add nothing. */
    private function totalDistance(array $groups, array $sequence, array $origin): float
    {
        $total = 0.0;
        foreach ($groups as $members) {
            $total += $this->dayDistance($members, $sequence, $origin);
        }

        return $total;
    }

    private function dayDistance(array $members, array $sequence, array $origin): float
    {
        $points = [];
        foreach ($members as $index) {
            $d = $sequence[$index]['row']['destination'];
            if ($d->latitude !== null && $d->longitude !== null) {
                $points[] = [(float) $d->latitude, (float) $d->longitude];
            }
        }

        $here = [(float) $origin['lat'], (float) $origin['lng']];
        $total = 0.0;

        while ($points) {
            $nearest = null;
            $nearestKm = null;
            foreach ($points as $k => $p) {
                $km = $this->haversineKm($here[0], $here[1], $p[0], $p[1]);
                if ($nearestKm === null || $km < $nearestKm) {
                    $nearestKm = $km;
                    $nearest = $k;
                }
            }
            $total += $nearestKm;
            $here = $points[$nearest];
            unset($points[$nearest]);
        }

        return $total;
    }

    /**
     * A plain-language label and similarity for one day, for the itinerary page.
     *
     * @return array<string, mixed>
     */
    private function describe(array $members, array $sequence, callable $sim): array
    {
        $destinations = array_map(fn (int $i) => $sequence[$i]['row']['destination'], $members);

        $tagCounts = [];
        foreach ($destinations as $d) {
            $d->loadMissing('tags');
            $own = $d->tags->where('kind', 'category')->pluck('value')->map(fn ($v) => mb_strtolower(trim((string) $v)))->filter()->unique();
            foreach ($own as $tag) {
                $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
            }
        }
        arsort($tagCounts);
        $shared = array_keys(array_filter($tagCounts, fn ($n) => $n >= 2));
        sort($shared);
        usort($shared, fn ($a, $b) => ($tagCounts[$b] <=> $tagCounts[$a]) ?: strcmp($a, $b));

        $label = count($destinations) < 2 ? null : ($shared !== [] ? ucwords($shared[0]).' day' : 'Mixed day');

        $similarity = null;
        if (count($members) >= 2) {
            $pairs = 0;
            $sum = 0.0;
            for ($i = 0; $i < count($members); $i++) {
                for ($j = $i + 1; $j < count($members); $j++) {
                    $sum += $sim($members[$i], $members[$j]);
                    $pairs++;
                }
            }
            $similarity = (int) round($sum / $pairs * 100);
        }

        return [
            'label' => $label,
            'similarity' => $similarity,
            'stops' => array_map(fn ($d) => $d->id, $destinations),
        ];
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
