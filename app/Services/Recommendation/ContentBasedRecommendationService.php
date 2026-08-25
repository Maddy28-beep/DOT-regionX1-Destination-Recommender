<?php

namespace App\Services\Recommendation;

use App\Models\Destination;
use App\Models\Tourist;
use App\Models\TouristHealthProfile;
use App\Models\TouristPreference;
use Illuminate\Support\Collection;

/**
 * Content-Based Recommendation module (manuscript Sec. 2.3.3).
 *
 * Ranks candidate destinations for a tourist by computing a Destination
 * Recommendation Score (DRS) from five weighted factors:
 *   DRS = (PM x 0.30) + (RS x 0.20) + (PS x 0.20) + (DS x 0.15) + (AS x 0.15)   -- Equation 3
 *
 * PM (Preference Match) is itself a weighted sum across nine preference
 * categories (Table 5), scaled to a 1-5 range (Equation 1-2).
 *
 * A few preference categories (Travel Purpose, Visitor Type, Accommodation
 * Type, Demographic) have no direct counterpart on the Destination schema.
 * Where the manuscript doesn't specify the exact data mapping, this class
 * uses a documented, deterministic heuristic per category rather than
 * leaving the factor unscored.
 */
class ContentBasedRecommendationService
{
    /** Table 5. Preference Category Weights (sum = 100). */
    private const PM_WEIGHTS = [
        'travel_purpose' => 15,
        'visitor_type' => 5,
        'budget' => 15,
        'accommodation_type' => 10,
        'duration_of_stay' => 10,
        'distance' => 15,
        'health_accessibility' => 10,
        'interest' => 15,
        'demographic' => 5,
    ];

    /** Equation 3 factor weights. */
    private const DRS_WEIGHTS = [
        'pm' => 0.30,
        'rs' => 0.20,
        'ps' => 0.20,
        'ds' => 0.15,
        'as' => 0.15,
    ];

    /** Distance buckets (km) backing both the PM "Distance" category and the DS factor (Table 6). */
    private const DISTANCE_BUCKETS = [
        'near' => [0, 15],
        'moderate' => [15, 40],
        'far' => [40, PHP_FLOAT_MAX],
    ];

    /** Maps tourist-facing interest/activity options to the free-form category tags seeded on destinations. */
    private const INTEREST_TAG_MAP = [
        'Beach & Island' => ['beach', 'island hopping', 'surfing', 'turtle sanctuary'],
        'Nature & Adventure' => ['nature', 'zipline', 'cool climate', 'garden', 'adventure'],
        'Cultural Heritage' => ['cultural heritage', 'city center'],
        'Wildlife' => ['wildlife', 'conservation'],
        'Food Tourism' => ['food', 'culinary'],
        'Shopping & Souvenirs' => ['shopping', 'souvenir'],
        'Hiking & Trekking' => ['hiking', 'highest peak', 'trekking'],
        'Relaxation & Wellness' => ['chocolate experience', 'family friendly', 'relaxation'],
    ];

    private const BUDGET_ORDER = ['Free' => 0, 'Budget-Friendly' => 1, 'Mid-range' => 2, 'Premium' => 3];

    /**
     * Rank candidate destinations for a tourist's active preference profile.
     *
     * @return Collection<int, array{destination: Destination, pm: float, rs: float, ps: float, ds: float, as: float, drs: float}>
     */
    public function rank(Tourist $tourist, TouristPreference $preference, ?Collection $candidates = null): Collection
    {
        $candidates ??= Destination::query()
            ->where('is_accredited', true)
            ->whereNull('archived_at')
            ->with('tags')
            ->get();

        if ($candidates->isEmpty()) {
            return collect();
        }

        $maxReviewCount = max($candidates->max('review_count'), 1);
        $selectedActivities = $preference->activities->pluck('activity')->all();
        $selectedAmenities = $preference->amenities->pluck('amenity')->all();
        $healthProfile = $tourist->healthProfile()->with('conditions')->first();

        return $candidates
            ->map(function (Destination $destination) use ($preference, $maxReviewCount, $selectedActivities, $selectedAmenities, $healthProfile) {
                $pm = $this->preferenceMatch($destination, $preference, $selectedActivities, $healthProfile);
                $rs = $this->ratingsScore($destination);
                $ps = $this->popularityScore($destination, $maxReviewCount);
                $ds = $this->distanceScore($destination, $preference);
                $as = $this->amenitiesScore($destination, $selectedAmenities);

                $drs = ($pm * self::DRS_WEIGHTS['pm'])
                    + ($rs * self::DRS_WEIGHTS['rs'])
                    + ($ps * self::DRS_WEIGHTS['ps'])
                    + ($ds * self::DRS_WEIGHTS['ds'])
                    + ($as * self::DRS_WEIGHTS['as']);

                return [
                    'destination' => $destination,
                    'pm' => round($pm, 2),
                    'rs' => round($rs, 2),
                    'ps' => round($ps, 2),
                    'ds' => round($ds, 2),
                    'as' => round($as, 2),
                    'drs' => round($drs, 2),
                ];
            })
            ->sortByDesc('drs')
            ->values();
    }

    /** Equation 1-2: weighted Preference Match, scaled to 1-5. */
    private function preferenceMatch(Destination $destination, TouristPreference $preference, array $selectedActivities, ?TouristHealthProfile $healthProfile): float
    {
        $categoryTags = $destination->tags->where('kind', 'category')->pluck('value')
            ->map(fn ($v) => strtolower($v))->all();

        $similarities = [
            'travel_purpose' => $preference->travel_purpose === 'Leisure' ? 1.0 : 0.6,
            'visitor_type' => 1.0, // no destination-side signal; kept neutral (weight is only 5)
            'budget' => $this->budgetSimilarity($destination->price_tier, $preference->budget),
            'accommodation_type' => $preference->accommodation_pref === 'Any' ? 1.0 : 0.7,
            'duration_of_stay' => 1.0, // visit_duration not yet populated on destinations; neutral
            'distance' => $this->distanceBucket((float) $destination->distance_km) === $preference->distance_pref ? 1.0 : 0.0,
            'health_accessibility' => $this->healthAccessibilitySimilarity($destination, $preference, $healthProfile),
            'interest' => $this->interestSimilarity($categoryTags, $selectedActivities),
            'demographic' => 1.0, // multi-select demographic groups are not part of the current schema; neutral
        ];

        $weightedSum = 0;
        foreach (self::PM_WEIGHTS as $category => $weight) {
            $weightedSum += $weight * $similarities[$category];
        }

        $pm = $weightedSum / array_sum(self::PM_WEIGHTS);

        return $pm * 5; // Equation 2: scale 0-1 to 1-5 range
    }

    private function budgetSimilarity(?string $destinationTier, string $preferredBudget): float
    {
        if ($destinationTier === 'Free') {
            return 1.0; // free destinations fit any budget
        }
        if (! isset(self::BUDGET_ORDER[$destinationTier]) || ! isset(self::BUDGET_ORDER[$preferredBudget])) {
            return 0.5;
        }
        $diff = abs(self::BUDGET_ORDER[$destinationTier] - self::BUDGET_ORDER[$preferredBudget]);

        return match (true) {
            $diff === 0 => 1.0,
            $diff === 1 => 0.5,
            default => 0.0,
        };
    }

    private function healthAccessibilitySimilarity(Destination $destination, TouristPreference $preference, ?TouristHealthProfile $healthProfile): float
    {
        $hasStructuredNeed = $healthProfile
            && $healthProfile->consent
            && ($healthProfile->conditions->isNotEmpty() || filled($healthProfile->other_text));

        if (blank($preference->accessibility_notes) && ! $hasStructuredNeed) {
            return 1.0; // no condition stated anywhere, fully compatible
        }
        $hasRamp = $destination->tags->where('kind', 'amenity')->pluck('value')
            ->contains(fn ($v) => str_contains(strtolower($v), 'accessibility'));

        return $hasRamp ? 1.0 : 0.3;
    }

    private function interestSimilarity(array $categoryTags, array $selectedActivities): float
    {
        if (empty($selectedActivities)) {
            return 1.0;
        }

        $matched = 0;
        foreach ($selectedActivities as $activity) {
            $keywords = self::INTEREST_TAG_MAP[$activity] ?? [];
            foreach ($keywords as $keyword) {
                if (collect($categoryTags)->contains(fn ($tag) => str_contains($tag, $keyword))) {
                    $matched++;
                    break;
                }
            }
        }

        return $matched / count($selectedActivities);
    }

    /** Equation 4: Ratings Score = the destination's average rating (already on a 1-5 scale). */
    private function ratingsScore(Destination $destination): float
    {
        return (float) $destination->rating;
    }

    /** Equation 5: Popularity Score = review count relative to the highest in the candidate set, scaled to 1-5. */
    private function popularityScore(Destination $destination, int $maxReviewCount): float
    {
        return ($destination->review_count / $maxReviewCount) * 5;
    }

    /** Table 6: Distance Score, graded by how close the destination's distance is to the preferred bucket. */
    private function distanceScore(Destination $destination, TouristPreference $preference): float
    {
        $bucket = $this->distanceBucket((float) $destination->distance_km);

        if ($bucket === $preference->distance_pref) {
            return 5.0;
        }

        $order = ['near' => 0, 'moderate' => 1, 'far' => 2];
        $diff = abs($order[$bucket] - $order[$preference->distance_pref]);

        return match ($diff) {
            1 => 3.0, // one bucket off (e.g. preferred near, destination moderate)
            default => 1.0, // two buckets off (preferred near, destination far)
        };
    }

    private function distanceBucket(float $km): string
    {
        foreach (self::DISTANCE_BUCKETS as $bucket => [$min, $max]) {
            if ($km >= $min && $km < $max) {
                return $bucket;
            }
        }

        return 'far';
    }

    /** Equation 6: Amenities Score = proportion of tourist-selected amenities present at the destination, scaled to 1-5. */
    private function amenitiesScore(Destination $destination, array $selectedAmenities): float
    {
        if (empty($selectedAmenities)) {
            return 5.0; // no preference stated, don't penalize
        }

        $destinationAmenities = $destination->tags->where('kind', 'amenity')->pluck('value')->all();
        $matched = count(array_intersect($selectedAmenities, $destinationAmenities));

        return ($matched / count($selectedAmenities)) * 5;
    }
}
