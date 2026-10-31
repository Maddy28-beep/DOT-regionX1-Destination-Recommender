<?php

namespace App\Services\Recommendation;

use App\Models\Destination;
use Illuminate\Support\Collection;

/**
 * Makes sure every interest a traveller chose gets a stop when the catalogue can offer one.
 *
 * The planner takes the best-scoring places for the trip. Scores add up many things, so a trip that asked for Cultural
 * Heritage, Nature and Wildlife could be filled by the three highest-scoring nature places and show no heritage at all.
 * Here, an interest with no stop among the chosen places takes the place of the lowest-ranked chosen stop that no
 * interest depends on, using the best-ranked place that covers it. Nothing else about the ranking changes, and an
 * interest nothing in the ranked pool covers is left alone.
 */
final class InterestCoverage
{
    /**
     * Whether a place counts as the interest: its type is one the interest covers, or one of its category tags
     * contains one of the interest's keywords (the test the ranking itself uses).
     */
    public static function covers(Destination $destination, string $interest): bool
    {
        if (in_array($destination->type, ContentBasedRecommendationService::typesForInterest($interest), true)) {
            return true;
        }

        $keywords = ContentBasedRecommendationService::tagsForInterest($interest);
        $tags = $destination->tags->where('kind', 'category')->pluck('value')->map(fn ($v) => mb_strtolower($v));

        return $tags->contains(fn ($tag) => collect($keywords)->contains(fn ($k) => str_contains($tag, $k)));
    }

    /**
     * @param  Collection<int, array{destination: Destination}>  $ranked  best first
     * @param  array<int, string>  $interests
     * @return Collection<int, array{destination: Destination}>  at most $max places, best first except where coverage moved one up
     */
    public function select(Collection $ranked, int $max, array $interests): Collection
    {
        $chosen = $ranked->take($max)->values();
        $pool = $ranked->slice($max)->values();

        foreach (array_values(array_unique(array_filter($interests))) as $interest) {
            if ($chosen->contains(fn ($row) => self::covers($row['destination'], $interest))) {
                continue;
            }

            $incoming = null;
            $out = null;
            foreach ($pool as $candidate) {
                if (! self::covers($candidate['destination'], $interest)) {
                    continue;
                }
                // A combined-interest venue can replace a sole cover only when it preserves that coverage.
                $replace = $this->lowestRankedReplaceable($chosen, $interests, $candidate['destination']);
                if ($replace !== null) {
                    $incoming = $candidate;
                    $out = $replace;
                    break;
                }
            }
            if ($incoming === null || $out === null) {
                continue;
            }

            $chosen = $chosen->map(fn ($row, $i) => $i === $out ? $incoming : $row)->values();
            $pool = $pool->reject(fn ($row) => $row['destination']->id === $incoming['destination']->id)->values();
        }

        return $chosen;
    }

    /** The lowest-ranked stop whose required coverage the incoming venue preserves, or null. */
    private function lowestRankedReplaceable(Collection $chosen, array $interests, Destination $incoming): ?int
    {
        for ($i = $chosen->count() - 1; $i >= 0; $i--) {
            $others = $chosen->reject(fn ($row, $k) => $k === $i);
            $needed = collect($interests)->contains(fn ($interest) => self::covers($chosen[$i]['destination'], $interest)
                && ! $others->contains(fn ($row) => self::covers($row['destination'], $interest))
                && ! self::covers($incoming, $interest));

            if (! $needed) {
                return $i;
            }
        }

        return null;
    }
}
