<?php

namespace App\Services\Recommendation;

use App\Models\Destination;
use App\Models\Region;
use App\Models\TouristHealthProfile;
use App\Models\TouristPreference;
use Illuminate\Support\Collection;

/**
 * Content-Based Recommendation module (manuscript Sec. 2.3.3).
 *
 * Runs in two clearly separate stages, in this order:
 *
 *   STAGE 1 -- RANGE (candidatesWithinRange()): narrows the catalogue to
 *   destinations within the traveller's chosen travel range of their
 *   baseline. This is a hard geographic gate, not a score: a destination
 *   outside the traveller's stated range is not a candidate at all, so a
 *   highly rated but distant place can no longer outrank a closer, merely
 *   decent one just because distance used to be blended in as one soft
 *   factor among several.
 *
 *   STAGE 2 -- MATCH (the rest of rank()): scores only what survived Stage 1
 *   against the traveller's other preferences, computing a Destination
 *   Recommendation Score (DRS) from five weighted factors:
 *     DRS = (PM x 0.30) + (RS x 0.20) + (PS x 0.20) + (DS x 0.15) + (AS x 0.15)   -- Equation 3
 *
 * PM (Preference Match) is itself a weighted sum across nine preference
 * categories (Table 5), scaled to a 1-5 range (Equation 1-2). DS (Distance
 * Score) still runs here too, on the survivors of Stage 1 -- it is now a
 * fine-grained tiebreaker ("closer is still slightly better within the
 * range you chose"), not the thing deciding who is even considered.
 *
 * A few preference categories (Travel Purpose, Visitor Type, Accommodation
 * Type, Demographic) have no direct counterpart on the Destination schema.
 * Where the manuscript doesn't specify the exact data mapping, this class
 * uses a documented, deterministic heuristic per category rather than
 * leaving the factor unscored.
 *
 * Sequencing the survivors into a day-by-day route (Nearest Neighbour +
 * Haversine) is a separate concern entirely and lives in
 * ItineraryGenerationService -- this class only ever decides WHICH
 * destinations are suitable, never in what ORDER to visit them.
 */
class ContentBasedRecommendationService
{
    /** @var array<int, array{lat: float, lng: float}|null> */
    private array $regionCentroids = [];

    /**
     * Whether the last rank() call had to widen past the traveller's chosen
     * range to find enough destinations, and which range it settled on.
     *
     * A handful of provinces have very few accredited destinations in this
     * catalogue (Davao Oriental had exactly one at the time of writing), so a
     * strict "within the city" filter from a baseline there can otherwise
     * return nothing at all. Exposed as a side value rather than changing
     * rank()'s return shape, which every existing caller already destructures
     * as a flat list of scored rows.
     */
    public ?string $lastRangeTierUsed = null;

    public bool $lastRangeWidened = false;

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

    /**
     * The score for a factor we have no data on.
     *
     * Midpoint of the 1-5 scale. The catalogue is mostly DOT-accredited
     * listings imported without ratings, tags or coordinates, and scoring those
     * gaps as 0 treated "we have not recorded this yet" as "this place is bad".
     * Every one of the 17 imported destinations came out on exactly 2.09 and
     * could never reach the top of a ranking, which is why the same eight
     * seeded places appeared in every itinerary.
     */
    private const NEUTRAL_SCORE = 3.0;

    /** The same idea on the 0-1 scale the Preference Match categories use. */
    private const NEUTRAL_SIMILARITY = 0.5;

    /**
     * Prior weight for the ratings and popularity shrinkage, in reviews.
     *
     * A listing with one glowing review should not outrank one with fifty good
     * ones, and a listing with none should sit at the middle rather than the
     * bottom. Scores are pulled towards the neutral value with a strength that
     * decays as real reviews accumulate.
     */
    private const REVIEW_PRIOR = 5;

    /**
     * How far to trust a distance derived from a region centroid rather than
     * from the listing's own coordinates. Half weight: enough that being in the
     * traveller's region still counts for something, not so much that an
     * unmapped listing outranks one whose position is known.
     */
    private const APPROXIMATION_TRUST = 0.5;

    /** Maps a destination's type to the interest vocabulary, for listings carrying no category tags. */
    private const TYPE_TO_INTEREST = [
        'Beach & Leisure' => 'Beach & Island',
        'Beach & Surfing' => 'Beach & Island',
        'Nature & Adventure' => 'Nature & Adventure',
        'Nature & Leisure' => 'Nature & Adventure',
        'Adventure & Hiking' => 'Hiking & Trekking',
        'Wildlife' => 'Wildlife',
        'Cultural Heritage' => 'Cultural Heritage',
        'Farm Tourism' => 'Nature & Adventure',
        'Wellness & Spa' => 'Relaxation & Wellness',
        'Sports & Recreation' => 'Nature & Adventure',
        'Events & Conventions' => 'Cultural Heritage',
    ];

    /** Distance buckets (km) backing both the PM "Distance" category and the DS factor (Table 6). */
    private const DISTANCE_BUCKETS = [
        'near' => [0, 15],
        'moderate' => [15, 40],
        'far' => [40, PHP_FLOAT_MAX],
    ];

    /**
     * Stage 1's hard range gates, keyed the same way as distance_pref.
     *
     * Distinct from DISTANCE_BUCKETS above: those SCORE how well an already
     * -eligible destination's distance suits the preference; these decide
     * eligibility in the first place, before scoring runs at all.
     *
     * Radii are grounded in real distances measured against this catalogue:
     *   - Davao City centre -> Eden Nature Park (same city, rural fringe): ~25 km
     *   - Davao City centre -> Tagum City (a genuinely different city):    ~50 km
     *   - Davao City centre -> Dahican Beach (Mati City, Davao Oriental): ~109 km
     * 25 km keeps a baseline's own city -- including the outlying, commonly
     * marketed day-trip spots every Davao City tourist already treats as
     * local -- inside "within the city," while still cleanly excluding a
     * genuinely different city like Tagum or Digos. 75 km reaches a
     * neighbouring city or province without crossing the whole region.
     * "Willing to travel" is left uncapped: the catalogue holds nothing
     * outside Davao Region to begin with, so no cap already means exactly
     * what was asked for -- the whole region, nothing more.
     */
    private const RANGE_RADIUS_KM = [
        'near' => 25.0,
        'moderate' => 75.0,
        'far' => null,
    ];

    /**
     * Rough target for how many destinations a trip needs, used only to
     * decide whether a range has to widen (see candidatesWithinRange()).
     * Matches ItineraryGenerationService::DESTINATIONS_PER_DAY -- duplicated
     * rather than shared, since the two classes are otherwise deliberately
     * decoupled and this is the only number either needs from the other.
     */
    private const TARGET_STOPS_PER_DAY = 2;

    /**
     * Approximate centre of a Davao Region province/city, used as the LAST
     * resort in regionCentroid() -- only when none of that region's own
     * destinations have coordinates to average.
     *
     * Without this, a destination in one of these provinces had NO distance
     * at all, and Stage 1's range filter treats "unknown" as "cannot judge,
     * let it through regardless of range" -- the right call for a place we
     * merely lack extra detail on, but wrong here: it let two genuinely
     * distant Davao del Norte listings (the Tagum area, ~71 km from a Mati
     * City baseline -- outside "within the city," inside "moderate") pass
     * every single tier including "within the city," while closer,
     * better-mapped Davao City destinations were correctly excluded.
     *
     * These are real, geocoded provincial capitals (confirmed live against
     * the address-autocomplete endpoint this feature already uses), not
     * guesses, and only cover the three regions with zero mapped
     * destinations of their own at the time of writing. A region that gains
     * even one mapped destination no longer needs an entry here -- the
     * dynamic sibling-average in regionCentroid() takes over automatically,
     * since it is tried first.
     */
    private const REGION_FALLBACK_CENTRE = [
        'Davao del Norte' => ['lat' => 7.4471, 'lng' => 125.8095],   // Tagum City
        'Davao de Oro' => ['lat' => 7.6022, 'lng' => 125.9688],       // Nabunturan
        'Davao Occidental' => ['lat' => 6.4144, 'lng' => 125.6109],   // Malita
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
     * Rank candidate destinations for a stated set of travel preferences.
     *
     * Two stages, in order (see the class docblock): first candidatesWithinRange()
     * narrows the pool to the traveller's chosen distance_pref range of their
     * baseline -- a hard geographic filter -- then every destination that
     * survives is scored against everything else they asked for. Distance is
     * therefore never allowed to be outscored by popularity: a far, highly
     * rated destination is not merely penalised, it is not a candidate at all
     * unless the range itself had to widen to find enough places to visit.
     *
     * The health/accessibility answers come off the preference itself: they
     * are given while planning the trip, and there is no account to hang them
     * on. A visitor who skips those questions simply scores that dimension as
     * "no structured need".
     *
     * @return Collection<int, array{destination: Destination, pm: float, rs: float, ps: float, ds: float, as: float, drs: float}>
     */
    public function rank(TouristPreference $preference, ?Collection $candidates = null): Collection
    {
        $candidates ??= Destination::query()
            ->where('is_accredited', true)
            ->whereNull('archived_at')
            ->with('tags')
            ->get();

        if ($candidates->isEmpty()) {
            return collect();
        }

        // STAGE 1: where can this trip go, before asking what it should
        // contain. See candidatesWithinRange().
        $candidates = $this->candidatesWithinRange($candidates, $preference);

        if ($candidates->isEmpty()) {
            return collect();
        }

        // STAGE 2, from here on: match what survived Stage 1 against every
        // other preference. $maxReviewCount and $catalogueMean are computed
        // over the range-filtered pool, not the whole catalogue -- "most
        // popular among what's actually in range" is the honest comparison.
        $maxReviewCount = max($candidates->max('review_count'), 1);

        // The mean of the listings that actually have reviews. An unrated
        // listing rests here rather than at zero.
        $rated = $candidates->filter(fn (Destination $d) => (int) $d->review_count > 0);
        $catalogueMean = $rated->isEmpty()
            ? self::NEUTRAL_SCORE
            : (float) $rated->avg('rating');
        $selectedActivities = $preference->activities->pluck('activity')->all();
        $selectedAmenities = $preference->amenities->pluck('amenity')->all();
        $healthProfile = $preference->healthProfile()->with('conditions')->first();

        return $candidates
            ->map(function (Destination $destination) use ($preference, $maxReviewCount, $catalogueMean, $selectedActivities, $selectedAmenities, $healthProfile) {
                $pm = $this->preferenceMatch($destination, $preference, $selectedActivities, $healthProfile);
                $rs = $this->ratingsScore($destination, $catalogueMean);
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
            /*
             * Sort by score, then break ties deterministically on a seed that
             * changes when the traveller asks for a fresh plan.
             *
             * Large groups of destinations score identically because nothing
             * has been recorded to tell them apart, and resolving those by
             * database id handed the win to the same listing every time. Only
             * equals are reordered: anything the score can distinguish keeps
             * its place, and a given seed always produces the same ranking, so
             * a plan is still reproducible.
             */
            ->sortBy([
                fn (array $a, array $b) => $b['drs'] <=> $a['drs'],
                fn (array $a, array $b) => $this->tieBreak($a['destination'], $preference)
                    <=> $this->tieBreak($b['destination'], $preference),
            ])
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
            'distance' => $this->distanceSimilarity($destination, $preference),
            'health_accessibility' => $this->healthAccessibilitySimilarity($destination, $preference, $healthProfile),
            'interest' => $this->interestSimilarity($categoryTags, $selectedActivities, $destination->type),
            'demographic' => 1.0, // multi-select demographic groups are not part of the current schema; neutral
        ];

        $weightedSum = 0;
        foreach (self::PM_WEIGHTS as $category => $weight) {
            $weightedSum += $weight * $similarities[$category];
        }

        $pm = $weightedSum / array_sum(self::PM_WEIGHTS);

        return $pm * 5; // Equation 2: scale 0-1 to 1-5 range
    }

    /**
     * A stable pseudo-random ordinal for one destination under one plan.
     *
     * crc32 of the pair spreads ids evenly, so the winner among tied listings
     * varies with the seed rather than always being the lowest id.
     */
    private function tieBreak(Destination $destination, TouristPreference $preference): int
    {
        return crc32($destination->id.':'.((int) $preference->variation));
    }

    /** The PM "Distance" category, sharing the DS factor's unknown handling. */
    private function distanceSimilarity(Destination $destination, TouristPreference $preference): float
    {
        $distance = $this->distanceKmFor($destination, $preference);

        if ($distance === null) {
            return self::NEUTRAL_SIMILARITY;
        }

        $matches = $this->distanceBucket($distance['km']) === $preference->distance_pref;

        if (! $distance['approximate']) {
            return $matches ? 1.0 : 0.0;
        }

        // Halfway between the neutral value and the confident one.
        return self::NEUTRAL_SIMILARITY + (($matches ? 1.0 : 0.0) - self::NEUTRAL_SIMILARITY) * self::APPROXIMATION_TRUST;
    }

    /** Pulls a score back towards neutral in proportion to how rough its evidence is. */
    private function damped(float $score): float
    {
        return self::NEUTRAL_SCORE + ($score - self::NEUTRAL_SCORE) * self::APPROXIMATION_TRUST;
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

    /**
     * @param  array<int, string>  $categoryTags  lower-cased category tags, may be empty
     */
    private function interestSimilarity(array $categoryTags, array $selectedActivities, ?string $type = null): float
    {
        if (empty($selectedActivities)) {
            return 1.0;
        }

        /*
         * Most of the catalogue carries no category tags at all -- only 8 of 25
         * destinations have any -- and an untagged listing scored 0 here, which
         * read as "matches none of your interests" when it actually meant "we
         * never recorded what this place is". Its type is recorded for every
         * listing, so use that as the coarse signal instead.
         */
        if (empty($categoryTags)) {
            $implied = self::TYPE_TO_INTEREST[$type] ?? null;

            return $implied === null
                ? self::NEUTRAL_SIMILARITY
                : (in_array($implied, $selectedActivities, true) ? 1.0 : self::NEUTRAL_SIMILARITY);
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
    /**
     * Equation 4, with the unrated case handled honestly.
     *
     * A listing with no reviews used to score 0 -- the worst possible rating --
     * purely for being new to the platform. It now sits at the catalogue mean
     * and moves towards its own rating as real reviews arrive, so evidence,
     * not its absence, is what moves the score.
     */
    private function ratingsScore(Destination $destination, float $catalogueMean): float
    {
        $reviews = (int) $destination->review_count;

        if ($reviews < 1) {
            return $catalogueMean;
        }

        $confidence = $reviews / ($reviews + self::REVIEW_PRIOR);

        return $catalogueMean + (((float) $destination->rating) - $catalogueMean) * $confidence;
    }

    /** Equation 5: Popularity Score = review count relative to the highest in the candidate set, scaled to 1-5. */
    /**
     * Equation 5, shrunk the same way as the ratings factor.
     *
     * Review coverage is very thin (13 reviews across the whole catalogue), so
     * a raw proportion of the busiest listing made "nobody has reviewed this"
     * indistinguishable from "nobody wants to go here". The observed share is
     * still what drives the score once reviews exist; before that it rests at
     * the neutral value.
     */
    private function popularityScore(Destination $destination, int $maxReviewCount): float
    {
        $reviews = (int) $destination->review_count;

        if ($reviews < 1) {
            return self::NEUTRAL_SCORE;
        }

        $observed = ($reviews / $maxReviewCount) * 5;
        $confidence = $reviews / ($reviews + self::REVIEW_PRIOR);

        return self::NEUTRAL_SCORE + ($observed - self::NEUTRAL_SCORE) * $confidence;
    }

    /**
     * Stage 1 of the pipeline: which destinations are even in play, given the
     * traveller's baseline and how far they said they are willing to go.
     *
     * Widens progressively -- near -> moderate -> uncapped -- but ONLY when
     * the requested range genuinely does not hold enough destinations to fill
     * the trip; a range that already has enough is never loosened just
     * because a wider one exists. A destination whose distance cannot be
     * determined at all (no coordinates, no stored figure, no mapped
     * neighbours in its own region) is never excluded here -- see
     * distanceKmFor() -- the same "unknown means cannot judge, not too far"
     * principle already applied to every other missing-data case in this
     * class.
     *
     * Sets lastRangeTierUsed / lastRangeWidened as a side effect so a caller
     * (or a test) can see whether this happened, without rank() having to
     * change what it returns.
     */
    private function candidatesWithinRange(Collection $candidates, TouristPreference $preference): Collection
    {
        $tiers = array_keys(self::RANGE_RADIUS_KM);
        $start = array_search($preference->distance_pref, $tiers, true);
        $start = $start === false ? 0 : $start;

        $target = max(self::TARGET_STOPS_PER_DAY, (int) $preference->travel_days * self::TARGET_STOPS_PER_DAY);

        for ($i = $start; $i < count($tiers); $i++) {
            $tier = $tiers[$i];
            $radius = self::RANGE_RADIUS_KM[$tier];

            $eligible = $radius === null
                ? $candidates
                : $candidates->filter(function (Destination $destination) use ($preference, $radius) {
                    $distance = $this->distanceKmFor($destination, $preference);

                    return $distance === null || $distance['km'] <= $radius;
                })->values();

            if ($eligible->count() >= $target || $radius === null) {
                $this->lastRangeTierUsed = $tier;
                $this->lastRangeWidened = $i > $start;

                return $eligible;
            }
        }

        // Unreachable in practice: the last tier is always uncapped, and the
        // loop always returns on an uncapped tier.
        return $candidates;
    }

    /** Table 6: Distance Score, graded by how close the destination's distance is to the preferred bucket. */
    /**
     * Equation 7. Measured from where the traveller actually is, when they told
     * us, rather than always from the city centre.
     */
    private function distanceScore(Destination $destination, TouristPreference $preference): float
    {
        $distance = $this->distanceKmFor($destination, $preference);

        // No coordinates, no recorded distance, no mapped neighbours: unknown,
        // not "next door". Casting a null distance to 0.0 silently filed every
        // unmapped listing in the "near" bucket.
        if ($distance === null) {
            return self::NEUTRAL_SCORE;
        }

        $bucket = $this->distanceBucket($distance['km']);
        $order = ['near' => 0, 'moderate' => 1, 'far' => 2];
        $diff = abs($order[$bucket] - $order[$preference->distance_pref]);

        $score = match (true) {
            $diff === 0 => 5.0,
            $diff === 1 => 3.0, // one bucket off (e.g. preferred near, destination moderate)
            default => 1.0,     // two buckets off (preferred near, destination far)
        };

        return $distance['approximate']
            ? $this->damped($score)
            : $score;
    }

    /**
     * How far this destination is, and how much that figure can be trusted.
     *
     * Preference order: a real Haversine distance from the position the
     * traveller shared, then the listing's recorded distance-from-city-centre,
     * then the centre of its region. The stored column is a fixed figure that
     * cannot know where the traveller is, which is the whole point of asking.
     *
     * The `approximate` flag matters: a region centroid can be kilometres out
     * for a city the size of Davao, so a listing placed that way must not score
     * as confidently as one we have actual coordinates for. Without the flag,
     * unmapped listings took the maximum distance score and displaced
     * destinations whose position is known exactly.
     *
     * @return array{km: float, approximate: bool}|null
     */
    private function distanceKmFor(Destination $destination, TouristPreference $preference): ?array
    {
        $origin = $preference->origin();

        if ($origin && $destination->latitude !== null && $destination->longitude !== null) {
            return [
                'km' => $this->haversineKm(
                    $origin['lat'],
                    $origin['lng'],
                    (float) $destination->latitude,
                    (float) $destination->longitude,
                ),
                'approximate' => false,
            ];
        }

        if ($destination->distance_km !== null) {
            return ['km' => (float) $destination->distance_km, 'approximate' => false];
        }

        /*
         * Last resort: the centre of the destination's own region. Most of the
         * imported catalogue has no coordinates, so without this a traveller
         * standing in Davao City gets "distance unknown" for the eighteen
         * Davao City destinations -- the exact case where proximity should be
         * doing the most work. A region centroid is coarse and is only ever
         * used to place a listing in a near/moderate/far bucket, never to give
         * a figure to the traveller.
         */
        if ($origin && $destination->region_id) {
            $centroid = $this->regionCentroid($destination->region_id);

            if ($centroid) {
                return [
                    'km' => $this->haversineKm($origin['lat'], $origin['lng'], $centroid['lat'], $centroid['lng']),
                    'approximate' => true,
                ];
            }
        }

        return null;
    }

    /**
     * The mean position of everything mapped in a region.
     *
     * Memoised for the life of the request: rank() asks once per candidate and
     * the answer cannot change mid-ranking.
     *
     * @return array{lat: float, lng: float}|null
     */
    private function regionCentroid(int $regionId): ?array
    {
        if (array_key_exists($regionId, $this->regionCentroids)) {
            return $this->regionCentroids[$regionId];
        }

        $mapped = Destination::query()
            ->where('region_id', $regionId)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['latitude', 'longitude']);

        if ($mapped->isNotEmpty()) {
            return $this->regionCentroids[$regionId] = [
                'lat' => (float) $mapped->avg('latitude'),
                'lng' => (float) $mapped->avg('longitude'),
            ];
        }

        // Nothing in this region has coordinates to average -- fall back to
        // the province/city's own real centre, when one is on file.
        $name = Region::find($regionId)?->name;

        return $this->regionCentroids[$regionId] = self::REGION_FALLBACK_CENTRE[$name] ?? null;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
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

        /*
         * An amenity that is not listed is not a confirmed missing amenity.
         * Tag coverage is very thin -- only 8 of 25 destinations carry any tags
         * at all -- so treating "not recorded" as "does not have it" scored
         * listings on how completely somebody had filled in their record.
         *
         * It also produced a perverse result: a place whose one recorded
         * amenity was a wheelchair ramp scored 0 against a request for parking,
         * while a place with nothing recorded scored neutral, so the better
         * documented listing lost. Confirmed matches lift the score above
         * neutral; nothing else moves it.
         */
        if ($matched === 0) {
            return self::NEUTRAL_SCORE;
        }

        $proportion = $matched / count($selectedAmenities);

        return self::NEUTRAL_SCORE + $proportion * (5.0 - self::NEUTRAL_SCORE);
    }
}
