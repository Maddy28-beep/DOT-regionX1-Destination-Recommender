<?php

namespace App\Services\Recommendation;

use App\Models\ExitSurvey;
use App\Models\ExitSurveyVisit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Association Rule Mining through the Apriori Algorithm (manuscript Sec. 2.3.4).
 *
 * Each completed exit survey is treated as one transaction, and the listings
 * recorded in its ExitSurveyVisit rows are the itemset for that transaction
 * (mirroring Table 10's "Sample Tourist Visitation Dataset"). This class mines
 * 2-item association rules ("tourists who visited A also visited B") using:
 *
 *   Support(A->B)    = |transactions containing both A and B| / |total transactions|     -- Equation 8
 *   Confidence(A->B)  = |transactions containing both A and B| / |transactions containing A| -- Equation 9
 */
class AprioriService
{
    /**
     * Complementary listings frequently co-visited with a given listing, ranked by confidence.
     *
     * @return Collection<int, array{listing_kind: string, listing_id: int, co_count: int, support: float, confidence: float}>
     */
    public function getAssociatedListings(
        string $listingKind,
        int $listingId,
        int $limit = 5,
        float $minConfidence = 0.1,
        int $minSupportCount = 2
    ): Collection {
        $surveyIdsWithA = ExitSurveyVisit::where('listing_kind', $listingKind)
            ->where('listing_id', $listingId)
            ->pluck('exit_survey_id');

        $countA = $surveyIdsWithA->count();
        $totalTransactions = ExitSurvey::count();

        if ($countA === 0 || $totalTransactions === 0) {
            return collect();
        }

        $coOccurrences = ExitSurveyVisit::whereIn('exit_survey_id', $surveyIdsWithA)
            ->where(function ($q) use ($listingKind, $listingId) {
                $q->where('listing_kind', '!=', $listingKind)
                    ->orWhere('listing_id', '!=', $listingId);
            })
            ->selectRaw('listing_kind, listing_id, count(distinct exit_survey_id) as co_count')
            ->groupBy('listing_kind', 'listing_id')
            ->havingRaw('count(distinct exit_survey_id) >= ?', [$minSupportCount])
            ->get();

        return $coOccurrences
            ->map(fn ($row) => [
                'listing_kind' => $row->listing_kind,
                'listing_id' => (int) $row->listing_id,
                'co_count' => (int) $row->co_count,
                'support' => round($row->co_count / $totalTransactions, 4),
                'confidence' => round($row->co_count / $countA, 4),
            ])
            ->filter(fn ($rule) => $rule['confidence'] >= $minConfidence)
            ->sortByDesc('confidence')
            ->take($limit)
            ->values();
    }

    /** Attach the resolved Eloquent model to each rule row (via the morph map), dropping any that no longer resolve. */
    public function resolveListings(Collection $rules): Collection
    {
        $morphMap = Relation::morphMap();

        return $rules
            ->map(function (array $rule) use ($morphMap) {
                $modelClass = $morphMap[$rule['listing_kind']] ?? null;
                $rule['listing'] = $modelClass ? $modelClass::find($rule['listing_id']) : null;

                return $rule;
            })
            ->filter(fn (array $rule) => $rule['listing'] !== null)
            ->values();
    }

    /**
     * Convenience: top associated listings for a destination, already resolved to models,
     * used by the itinerary generator to suggest complementary accommodations/restaurants/etc.
     */
    public function suggestionsFor(string $listingKind, int $listingId, int $limit = 3): Collection
    {
        $rules = $this->getAssociatedListings($listingKind, $listingId, $limit);

        return $this->resolveListings($rules);
    }

    /**
     * Every directional association rule (A -> B) across the whole exit-survey dataset, ranked by
     * confidence — used by the admin "Association Rules & Co-Visitation Patterns" overview page,
     * as opposed to getAssociatedListings() which scopes to a single starting listing.
     *
     * @return Collection<int, array{a_kind: string, a_id: int, b_kind: string, b_id: int, co_count: int, support: float, confidence: float}>
     */
    public function topRules(int $limit = 15, int $minSupportCount = 2, float $minConfidence = 0.15): Collection
    {
        $totalTransactions = ExitSurvey::count();
        if ($totalTransactions === 0) {
            return collect();
        }

        $visitsByTransaction = ExitSurveyVisit::query()
            ->select('exit_survey_id', 'listing_kind', 'listing_id')
            ->get()
            ->groupBy('exit_survey_id');

        $antecedentCounts = [];
        $pairCounts = [];

        foreach ($visitsByTransaction as $visits) {
            $items = $visits->map(fn ($v) => $v->listing_kind.':'.$v->listing_id)->unique()->values();
            if ($items->count() < 2) {
                continue;
            }

            foreach ($items as $a) {
                $antecedentCounts[$a] = ($antecedentCounts[$a] ?? 0) + 1;
                foreach ($items as $b) {
                    if ($a === $b) {
                        continue;
                    }
                    $key = $a.'|'.$b;
                    $pairCounts[$key] = ($pairCounts[$key] ?? 0) + 1;
                }
            }
        }

        $rules = collect($pairCounts)
            ->map(function ($coCount, $key) use ($antecedentCounts, $totalTransactions) {
                [$a, $b] = explode('|', $key);
                [$aKind, $aId] = explode(':', $a);
                [$bKind, $bId] = explode(':', $b);

                return [
                    'a_kind' => $aKind,
                    'a_id' => (int) $aId,
                    'b_kind' => $bKind,
                    'b_id' => (int) $bId,
                    'co_count' => $coCount,
                    'support' => round($coCount / $totalTransactions, 4),
                    'confidence' => round($coCount / $antecedentCounts[$a], 4),
                ];
            })
            ->filter(fn ($rule) => $rule['co_count'] >= $minSupportCount && $rule['confidence'] >= $minConfidence)
            ->sortByDesc('confidence')
            ->take($limit)
            ->values();

        $morphMap = Relation::morphMap();
        $resolve = function (string $kind, int $id) use ($morphMap) {
            $modelClass = $morphMap[$kind] ?? null;

            return $modelClass ? $modelClass::find($id) : null;
        };

        return $rules->map(function (array $rule) use ($resolve) {
            $rule['a_listing'] = $resolve($rule['a_kind'], $rule['a_id']);
            $rule['b_listing'] = $resolve($rule['b_kind'], $rule['b_id']);

            return $rule;
        })->filter(fn ($rule) => $rule['a_listing'] && $rule['b_listing'])->values();
    }
}
