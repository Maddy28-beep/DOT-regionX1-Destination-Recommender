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
 * If any stop has no stored vector, nothing is changed.
 */
class ThemeDayGrouper
{
    /** The themed plan may travel at most this much further than the plain route. */
    public const MAX_EXTRA_DISTANCE = 0.25;

    /** Kilometres of slack so a very short route is not frozen by the percentage. */
    public const DISTANCE_ALLOWANCE_KM = 3.0;

    private const MAX_ROUNDS = 50;

    /**
     * @param  array<int, array{row: array, distance_km: float|null}>  $sequence
     * @param  array<int, array<int, string>>  $dayCapacities  day number => slot labels
     * @param  array{lat: float, lng: float}  $origin
     * @return array{sequence: array, days: array<int, array<string, mixed>>, summary: array<string, mixed>}|null
     */
    public function group(array $sequence, array $dayCapacities, array $origin): ?array
    {
        $count = count($sequence);
        if ($count < 3) {
            return null;
        }

        $ids = array_map(fn (array $e) => $e['row']['destination']->id, $sequence);
        $vectors = DestinationEmbedding::whereIn('destination_id', $ids)->get()->keyBy('destination_id')
            ->map(fn (DestinationEmbedding $e) => $e->vector);

        if ($vectors->count() !== count(array_unique($ids))) {
            return null; // a stop without a vector: leave the plan exactly as it is
        }

        $sizes = $this->daySizes($dayCapacities, $count);
        if (count($sizes) < 2) {
            return null;
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
            ],
        ];
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
