<?php

namespace App\Services\Recommendation;

use App\Models\Accommodation;
use App\Models\Itinerary;
use App\Models\TouristPreference;
use Illuminate\Support\Facades\DB;

/**
 * AI-Driven Itinerary Generation module (manuscript Sec. 2.3.4, "AI-Driven Itinerary
 * Generation"). Combines the Content-Based Recommendation ranking, Apriori-derived
 * complementary establishments, and a heuristic rule-based sequencing step
 * (Haversine distance + Nearest Neighbor) into a day-by-day travel plan.
 *
 * Per the manuscript: "the heuristic approach does not replace the Content-Based
 * Recommendation and Apriori Algorithm... it serves as a supporting rule-based
 * algorithm that arranges the recommended destinations into a logical and
 * convenient travel sequence."
 */
class ItineraryGenerationService
{
    /** Fallback baseline when no live location is provided: Davao City center (matches "tourist's selected starting location"). */
    private const DEFAULT_ORIGIN_LAT = 7.0731;

    private const DEFAULT_ORIGIN_LNG = 125.6128;

    /** What to call the fallback baseline on the schedule's arrival row. */
    private const DEFAULT_ORIGIN_LABEL = 'Davao City centre';

    private const DESTINATIONS_PER_DAY = 2;

    /**
     * When each sightseeing slot opens. A slot is only offered on arrival day
     * if the traveller is on the ground before it starts -- landing at 18:00
     * and being handed a morning stop is the bug this exists to prevent.
     */
    private const SLOT_START_HOUR = [
        'Morning' => 8,
        'Afternoon' => 12,
        'Evening' => 17,
    ];

    public function __construct(
        private readonly ContentBasedRecommendationService $contentBased,
        private readonly AprioriService $apriori,
        private readonly ItineraryScheduleBuilder $schedule,
    ) {}

    /**
     * An itinerary belongs to the preference it was generated from, and to
     * nobody else -- the site has no traveler accounts. The row is persisted
     * (the algorithm below writes the full ranking and the day-by-day items,
     * and the view reads them back) and reached through the session.
     */
    public function generate(TouristPreference $preference, ?float $originLat = null, ?float $originLng = null): Itinerary
    {
        /*
         * Origin precedence: an explicitly passed position (a fresh reading
         * taken on "regenerate") beats the one saved with the preference, which
         * in turn beats the regional default. Falling straight through to the
         * default whenever no argument was passed is what used to make the
         * saved plan and every regeneration disagree about where the trip
         * started.
         */
        $saved = $preference->origin();
        $shared = ($originLat !== null && $originLng !== null) || $saved !== null;

        $originLat ??= $saved['lat'] ?? self::DEFAULT_ORIGIN_LAT;
        $originLng ??= $saved['lng'] ?? self::DEFAULT_ORIGIN_LNG;

        /*
         * The schedule names this point on its first and last rows. Prefer what
         * the traveller called it -- an address they typed or picked -- over a
         * generic phrase, so "Travel to Francisco Bangoy International Airport"
         * reads as a real instruction rather than "travel to your starting
         * point".
         */
        $origin = [
            'lat' => $originLat,
            'lng' => $originLng,
            'label' => $preference->origin_label
                ?: ($shared ? 'your starting point' : self::DEFAULT_ORIGIN_LABEL),
            'region' => null,
        ];

        $ranked = $this->contentBased->rank($preference);
        // rank() decides this as a side effect and only exposes it on the
        // service instance for the duration of this request -- capture it
        // now so it can be persisted, or it's gone once this method returns.
        $rangeTierUsed = $this->contentBased->lastRangeTierUsed;
        $rangeWidened = $this->contentBased->lastRangeWidened;

        if ($ranked->isEmpty()) {
            throw new \RuntimeException('No accredited destinations are available to build an itinerary.');
        }

        $totalDays = max(1, (int) $preference->travel_days);

        // Arrival day may hold fewer stops than a full day, so capacity has to
        // be summed per day rather than assumed uniform.
        $dayCapacities = $this->dayCapacities($preference, $totalDays);
        $maxStops = min($ranked->count(), array_sum(array_map('count', $dayCapacities)));
        $topRanked = $ranked->take($maxStops);

        $sequence = $this->sequenceByNearestNeighbor($topRanked, $originLat, $originLng);

        return DB::transaction(function () use ($preference, $totalDays, $ranked, $sequence, $dayCapacities, $origin, $rangeTierUsed, $rangeWidened) {
            $itinerary = Itinerary::create([
                'preference_id' => $preference->id,
                'total_days' => $totalDays,
                'est_party_size' => null,
                'generated_at' => now(),
                'range_tier_used' => $rangeTierUsed,
                'range_widened' => $rangeWidened,
            ]);

            // Table 8: full computed Destination Recommendation ranking, not just the stops used.
            foreach ($ranked->values() as $index => $row) {
                // The five factors are kept alongside the combined score:
                // they are already computed, and without them the itinerary
                // can state a ranking but not show how it was reached.
                $itinerary->matches()->create([
                    'destination_id' => $row['destination']->id,
                    'rank' => $index + 1,
                    'match_score' => $row['drs'],
                    'pm' => $row['pm'],
                    'rs' => $row['rs'],
                    'ps' => $row['ps'],
                    'ds' => $row['ds'],
                    'as' => $row['as'],
                ]);
            }

            /*
             * The stops and their order are settled above; turning them into a
             * timed day -- journeys, meals, the night -- is the schedule
             * builder's job. Keeping that separate stops this method from
             * owning both "which places" and "at what o'clock".
             */
            $this->schedule->build(
                $itinerary,
                $sequence,
                $preference,
                $dayCapacities,
                $origin,
                $this->pickAccommodation($sequence, $preference),
            );

            return $itinerary->load(['matches.destination', 'items.destination', 'items.accommodation']);
        });
    }

    /**
     * Nearest Neighbor heuristic: starting from the baseline, repeatedly visit
     * whichever remaining ranked destination is geographically closest.
     */
    private function sequenceByNearestNeighbor($topRanked, float $originLat, float $originLng): array
    {
        $remaining = $topRanked->values()->all();
        $sequence = [];
        $currentLat = $originLat;
        $currentLng = $originLng;

        while (! empty($remaining)) {
            $nearestIndex = null;
            $nearestDistance = null;

            foreach ($remaining as $index => $row) {
                $destination = $row['destination'];
                if ($destination->latitude === null || $destination->longitude === null) {
                    continue;
                }
                $distance = $this->haversineKm($currentLat, $currentLng, (float) $destination->latitude, (float) $destination->longitude);
                if ($nearestDistance === null || $distance < $nearestDistance) {
                    $nearestDistance = $distance;
                    $nearestIndex = $index;
                }
            }

            // destinations missing coordinates just get appended in ranked order
            if ($nearestIndex === null) {
                $row = array_shift($remaining);
                $sequence[] = ['row' => $row, 'distance_km' => null];

                continue;
            }

            $row = $remaining[$nearestIndex];
            unset($remaining[$nearestIndex]);
            $remaining = array_values($remaining);

            $sequence[] = ['row' => $row, 'distance_km' => round($nearestDistance, 1)];
            $currentLat = (float) $row['destination']->latitude;
            $currentLng = (float) $row['destination']->longitude;
        }

        return $sequence;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    /**
     * Which sightseeing slots each day can actually hold.
     *
     * Every day but the first gets the standard allowance. Day 1 is trimmed to
     * the slots that have not already passed by the time the traveller lands,
     * so a 6pm arrival no longer produces a morning stop nobody can reach. With
     * no arrival time given, nothing changes from the previous behaviour.
     *
     * @return array<int, array<int, string>> day number => ordered slot names
     */
    private function dayCapacities(TouristPreference $preference, int $totalDays): array
    {
        $slots = array_keys(self::SLOT_START_HOUR);
        $standard = array_slice($slots, 0, self::DESTINATIONS_PER_DAY);

        $capacities = [];
        for ($day = 1; $day <= $totalDays; $day++) {
            $capacities[$day] = $standard;
        }

        $arrivalHour = $this->arrivalHour($preference);
        if ($arrivalHour === null) {
            return $capacities;
        }

        /*
         * Arrival day offers only the slots that begin AFTER the traveller
         * lands, on the assumption that the block they arrive in is spent
         * getting to their accommodation and settling in. Landing at 14:00
         * therefore buys the evening, not the afternoon they are still
         * travelling through.
         *
         * All three slots are candidates here, not just the two a normal day
         * uses -- restricting the pool first was a bug: a 2pm arrival matched
         * neither Morning nor Afternoon and silently lost the whole day.
         * The result is still capped at the normal daily allowance.
         */
        $remaining = array_values(array_filter(
            $slots,
            fn (string $slot) => self::SLOT_START_HOUR[$slot] > $arrivalHour
        ));

        // An evening landing legitimately leaves day 1 with no sightseeing at
        // all. The day still exists and still carries the accommodation
        // check-in, which is the only thing that can honestly happen that night.
        $capacities[1] = array_slice($remaining, 0, self::DESTINATIONS_PER_DAY);

        return $capacities;
    }

    /** Arrival hour as a number, or null when the traveller did not say. */
    private function arrivalHour(TouristPreference $preference): ?int
    {
        $time = $preference->arrival_time;

        if (blank($time)) {
            return null;
        }

        // The column is a time; depending on driver it comes back as a string
        // or a date object, so normalise rather than assuming either.
        if ($time instanceof \DateTimeInterface) {
            return (int) $time->format('G');
        }

        return (int) explode(':', (string) $time)[0];
    }

    /**
     * Suggests a nearby accommodation using Apriori co-visitation, falling back
     * to the traveller's stated accommodation preference.
     *
     * Returns the rule that produced it as well as the listing, so the
     * itinerary can show WHY this stay was suggested -- an association rule
     * with no support or confidence attached is an assertion, not evidence.
     *
     * @return array{listing: Accommodation, rule: array|null}|null
     */
    private function pickAccommodation(array $dayStops, TouristPreference $preference): ?array
    {
        $wanted = $preference->accommodation_pref;
        $wanted = ($wanted && $wanted !== 'Any') ? $wanted : null;

        foreach ($dayStops as $stop) {
            $destination = $stop['row']['destination'];
            $suggestions = $this->apriori->suggestionsFor('destination', $destination->id, 3)
                ->filter(fn ($rule) => $rule['listing_kind'] === 'accommodation')
                /*
                 * The stated accommodation type is a requirement, not a hint.
                 * Apriori only knows what past tourists happened to pair with a
                 * destination, and every accommodation it can suggest in this
                 * catalogue is a Beach Resort -- so a traveller who asked for a
                 * Hotel was handed Pearl Farm Beach Resort purely because Samal
                 * Island is popular. This preference used to be consulted only
                 * in the catalogue query below, which any destination carrying
                 * a co-visitation rule never reached. A soft historical signal
                 * must not overrule a hard stated one: when nothing Apriori
                 * suggests fits, fall through and book from the catalogue
                 * instead of quietly ignoring what was asked for.
                 */
                ->filter(fn ($rule) => $wanted === null || $rule['listing']->type === $wanted)
                ->values();

            if ($suggestions->isNotEmpty()) {
                $rule = $this->weightedPick($suggestions, $preference);

                return [
                    'listing' => $rule['listing'],
                    'rule' => [
                        'basis' => $destination->name,
                        'support' => $rule['support'],
                        'confidence' => $rule['confidence'],
                        'co_count' => $rule['co_count'],
                    ],
                ];
            }
        }

        $query = Accommodation::where('is_accredited', true)->whereNull('archived_at');
        if ($wanted !== null) {
            $query->where('type', $wanted);
        }

        /*
         * Prefer a stay we can actually place on the map. Most of the
         * catalogue has no coordinates (the accreditation import carried
         * addresses, not positions), and picking one of those leaves every
         * transfer to and from the hotel unmeasurable. Rating still decides
         * among the ones we can locate, and an unlocatable stay is still
         * offered rather than none at all.
         */
        $listing = (clone $query)->whereNotNull('latitude')->whereNotNull('longitude')
                ->orderByDesc('rating')->first()
            ?? $query->orderByDesc('rating')->first()
            ?? Accommodation::where('is_accredited', true)->whereNull('archived_at')
                ->orderByDesc('rating')->first();

        return $listing ? ['listing' => $listing, 'rule' => null] : null;
    }

    /**
     * Chooses among several Apriori-suggested accommodations, weighted by
     * confidence, on a seed that changes when the traveller regenerates.
     *
     * suggestionsFor() always returned the single highest-confidence rule
     * before this: whichever accommodation happened to have the strongest
     * historical pairing with a popular destination (Samal Island -> BlueJaz
     * Beach Resort, 59% confidence) won every single time that destination
     * appeared, regardless of how many times the plan was regenerated --
     * real alternatives with real support (Pearl Farm Beach Resort at 31%,
     * Paradise Island at 16%) never got a chance. Destination ranking
     * already solves this exact "always the same pick" problem with a
     * variation-seeded tie-break (see ContentBasedRecommendationService);
     * this applies the same idea here; a weighted draw rather than a plain
     * tie-break because these confidences are genuinely different, not tied.
     *
     * @param  \Illuminate\Support\Collection<int, array{listing_id: int, confidence: float}>  $suggestions  sorted by confidence, already non-empty
     * @return array{listing: Accommodation, support: float, confidence: float, co_count: int}
     */
    private function weightedPick($suggestions, TouristPreference $preference): array
    {
        $totalConfidence = $suggestions->sum('confidence');

        // All-zero confidence can't be weighted meaningfully; keep the
        // strongest suggestion rather than divide by zero.
        if ($totalConfidence <= 0) {
            return $suggestions->first();
        }

        $seed = crc32($suggestions->first()['listing_id'].':'.((int) $preference->variation));
        $threshold = ($seed % 10000 / 10000) * $totalConfidence;

        $cumulative = 0.0;
        foreach ($suggestions as $rule) {
            $cumulative += $rule['confidence'];
            if ($threshold < $cumulative) {
                return $rule;
            }
        }

        return $suggestions->last();
    }
}
