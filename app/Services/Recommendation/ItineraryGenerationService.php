<?php

namespace App\Services\Recommendation;

use App\Models\Accommodation;
use App\Support\AccreditationGrace;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\TouristPreference;
use App\Support\TravelWindow;
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
    private const DEFAULT_ORIGIN_LAT = ContentBasedRecommendationService::DEFAULT_ORIGIN_LAT;

    private const DEFAULT_ORIGIN_LNG = ContentBasedRecommendationService::DEFAULT_ORIGIN_LNG;

    /** What to call the fallback baseline on the schedule's arrival row. */
    private const DEFAULT_ORIGIN_LABEL = 'Davao City centre';

    private const DESTINATIONS_PER_DAY = 2;

    /**
     * How far apart (slow-end journey, in minutes) two places of one Remote trip may be and still count as the same
     * neighbourhood. Davao Region's places fall into tight groups (the Mati coast, the north, the south-west): inside
     * a group the journey is at most about 150 minutes, between groups it is over 255, so a trip drawn from one group
     * is a trip of days, and a trip drawn from several is a trip of drives.
     */
    private const REMOTE_NEIGHBOURHOOD_MINUTES = 150;

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
        private readonly ThemeDayGrouper $themeDays,
    ) {}

    /**
     * An itinerary belongs to the preference it was generated from, and to
     * nobody else -- the site has no traveler accounts. The row is persisted
     * (the algorithm below writes the full ranking and the day-by-day items,
     * and the view reads them back) and reached through the session.
     */
    public function generate(TouristPreference $preference, ?float $originLat = null, ?float $originLng = null, array $swaps = []): Itinerary
    {
        // Every place picked below is checked against the traveller's own dates.
        TravelWindow::set(...$preference->travelWindow());
        $this->regionNames = null;

        try {
            return $this->generateWithinWindow($preference, $originLat, $originLng, $swaps);
        } finally {
            TravelWindow::clear();
        }
    }

    private function generateWithinWindow(TouristPreference $preference, ?float $originLat, ?float $originLng, array $swaps): Itinerary
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
            // A typed label names the point only when it was located (see TouristPreference::startLabel()).
            'label' => ($saved !== null ? $preference->origin_label : null)
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
            throw new \App\Exceptions\NoDestinationsAvailable();
        }

        $totalDays = max(1, (int) $preference->travel_days);

        /*
         * Several DOT-accredited destinations are different branches of the
         * same business (three Elysia Wellness Spa locations, two Rancho
         * Palos Verdes venues), imported as separate rows because they are
         * genuinely separate addresses. None of them carry their own
         * coordinates, rating, or tags, so they score identically and can
         * sweep the top of the ranking together -- which turned "visit
         * Elysia Wellness Spa" into three separate days of the same trip.
         * $ranked keeps every branch, scored honestly, for the full
         * Table 8 ranking persisted below; only the pool actually used to
         * pick and schedule stops is thinned to one (the best-scoring)
         * branch per business name.
         */
        $distinctRanked = $this->distinctByDestinationName($ranked);

        // Arrival day may hold fewer stops than a full day, so capacity has to
        // be summed per day rather than assumed uniform.
        $dayCapacities = $this->dayCapacities($preference, $totalDays);
        $maxStops = min($distinctRanked->count(), array_sum(array_map('count', $dayCapacities)));
        if ($preference->distance_pref === 'far') {
            $distinctRanked = $this->withinOneNeighbourhood($distinctRanked, $maxStops, $preference);
        }
        // The best places for the trip, except that an interest the traveller chose gets a stop when anything can offer one.
        $topRanked = (new InterestCoverage())->select($distinctRanked, $maxStops, $preference->activities->pluck('activity')->all());

        // The next few places in the ranking: used only to fill a day that would otherwise have no stop at all.
        $chosenIds = $topRanked->pluck('destination.id')->all();
        $reserve = $distinctRanked->reject(fn (array $row) => in_array($row['destination']->id, $chosenIds, true))
            ->take(self::RESERVE_STOPS)->values();

        $sequence = $this->sequenceByNearestNeighbor($topRanked, $originLat, $originLng);

        /*
         * Similar-place swaps: stops the traveller replaced with a similar place
         * (original destination id => replacement id). The replacement takes the
         * original's position in the sequence and the schedule is built from it,
         * so every journey time, meal and Apriori pick below follows the new place
         * instead of being patched afterwards.
         */
        $swaps = $this->applicableSwaps($swaps, $sequence);
        if ($swaps !== []) {
            $replacements = Destination::whereIn('id', array_values($swaps))->get()->keyBy('id');
            foreach ($sequence as $i => $entry) {
                $replacement = $replacements->get($swaps[$entry['row']['destination']->id] ?? 0);
                if ($replacement) {
                    $sequence[$i]['row']['destination'] = $replacement;
                }
            }
        }

        /*
         * Theme-based days (pretrained embeddings): decide which stops share a day
         * so that a day's places are alike, within a travelling limit. The stops
         * themselves are untouched; only their grouping into days can change.
         * Returns null (plain route order kept) when any stop has no stored vector.
         */
        $routeSequence = $sequence;
        $themes = $this->themeDays->group($sequence, $dayCapacities, $origin);
        $themedSequence = ($themes !== null && $themes['sequence'] !== $routeSequence) ? $themes['sequence'] : null;
        if ($themes !== null) {
            $sequence = $themes['sequence'];
        }

        /*
         * Apriori accommodation pick moved here, ahead of the transaction: it
         * only reads (co-visitation rules, then a catalogue query), never
         * writes, so computing it now — instead of inside the transaction
         * closure as before — is safe, and it lets the pretrained ML step
         * below see the same accommodation hint the schedule will actually
         * use, without querying for it twice.
         */
        // One stay per night, each near that day's last stop; falls back to the single trip-wide pick when there
        // is no night to plan (a one-day trip) or no mapped stay is available for the dates.
        $accommodationPick = $totalDays >= 2
            ? $this->pickStays($sequence, $dayCapacities, $preference, $origin)
            : $this->pickAccommodation($sequence, $preference);

        return DB::transaction(function () use ($reserve, $preference, $totalDays, $ranked, $sequence, $dayCapacities, $origin, $rangeTierUsed, $rangeWidened, $accommodationPick, $swaps, $themes, $routeSequence, $themedSequence) {
            // The schedule builder keeps why it turned each place away, per build; start this trip with none.
            $this->schedule->forgetRecordedAttempts();

            $itinerary = Itinerary::create([
                'preference_id' => $preference->id,
                'total_days' => $totalDays,
                'est_party_size' => null,
                'generated_at' => now(),
                'range_tier_used' => $rangeTierUsed,
                'range_widened' => $rangeWidened,
                'swaps' => $swaps !== [] ? $swaps : null,
                'day_themes' => $themes !== null ? ['days' => $themes['days'], 'summary' => $themes['summary']] : null,
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
            // Shared by reference on purpose: settleStays() replaces the stays and builds again, and a closure that copied
            // them (an arrow function does) would build the first stays every time.
            $stays = $accommodationPick;
            $build = function (array $stops) use (&$stays, $itinerary, $preference, $dayCapacities, $origin): void {
                $this->schedule->build($itinerary, $stops, $preference, $dayCapacities, $origin, $stays);
            };

            $built = $sequence;

            if ($themedSequence === null) {
                $build($sequence);
            } else {
                /*
                 * Regrouping must never cost the traveller a stop. The schedule builder refuses a day
                 * that cannot finish by its cutoff and drops what no longer fits, and the grouper only
                 * limits total travelling, not each day's feasibility. So build the plain route order
                 * first to learn how many stops it places, then the themed order, and keep the themed
                 * plan only if it places at least as many.
                 */
                $placed = fn (): int => $itinerary->items()->whereNotNull('destination_id')->distinct()->count('destination_id');

                $build($routeSequence);
                $routePlaced = $placed();
                $itinerary->items()->delete();
                $itinerary->unsetRelation('items');

                $build($themedSequence);
                $built = $themedSequence;

                if ($placed() < $routePlaced) {
                    $itinerary->items()->delete();
                    $itinerary->unsetRelation('items');
                    $build($routeSequence);
                    $built = $routeSequence;

                    $summary = $themes['summary'];
                    $summary['regrouped'] = false;
                    $summary['guard'] = true;
                    $summary['mean_similarity_after'] = $summary['mean_similarity_before'];
                    $summary['distance_after_km'] = $summary['distance_before_km'];
                    $summary['types_per_day_after'] = $summary['types_per_day_before'] ?? null;
                    $itinerary->update(['day_themes' => ['days' => [], 'summary' => $summary]]);
                }
            }

            $built = $this->fillEmptyDays($itinerary, $build, $built, $reserve, $dayCapacities, $origin);

            // From two days up the stay is chosen again for the stops that made it into the days (see settleStays).
            if ($totalDays >= 2) {
                $itinerary->update(['stay_plan' => $this->settleStays($itinerary, $build, $stays, $built, $sequence, $preference, $dayCapacities, $origin)]);
            } else {
                // A one-day trip has no night to settle, but a place it set out to visit and could not fit is still said so.
                $placedIds = $itinerary->items()->where('kind', 'activity')->whereNotNull('destination_id')->pluck('destination_id')->all();
                $left = $this->unplacedNotices($placedIds, $sequence, $this->schedule->recordedAttempts($built, $stays, $dayCapacities));
                if ($left !== []) {
                    $itinerary->update(['stay_plan' => [
                        'version' => 1,
                        'mode' => $this->stayMode($preference),
                        'continuity' => ['nights' => 0, 'distinct_stays' => 0, 'transfers' => 0],
                        'notices' => $left,
                        'guard' => null,
                    ]]);
                }
            }

            return $itinerary->load(['matches.destination', 'items.destination', 'items.accommodation']);
        });
    }

    /**
     * Puts the places of the best neighbourhood first, so the trip is drawn from one part of the region.
     *
     * A Remote trip has no distance limit, and the ranking alone does not care where the best places are: it picked a
     * stop in the north, one on the coast and one in the south-west, and the days that followed were mostly driving. The
     * neighbourhood of a place is every place within REMOTE_NEIGHBOURHOOD_MINUTES of it. The best one is the one whose
     * best places (as many as the trip has stops) add up to the most score, so the traveller's interests still decide
     * where the trip goes. Its places come first in the ranking's own order, then everything else nearest first, which
     * is what fills the trip when its neighbourhood holds too few places.
     *
     * Only the order changes: nothing is removed from the pool, and the interest coverage, the reserve and the rest of
     * the plan work from it as before. Places without a position cannot be placed in a neighbourhood and go last.
     *
     * @param  \Illuminate\Support\Collection<int, array{destination: Destination, drs: float}>  $ranked  best first
     * @return \Illuminate\Support\Collection<int, array{destination: Destination, drs: float}>
     */
    private function withinOneNeighbourhood(\Illuminate\Support\Collection $ranked, int $stops, TouristPreference $preference): \Illuminate\Support\Collection
    {
        $rows = $ranked->values()->all();
        $minutes = fn (Destination $a, Destination $b): int => $this->stayMinutes($this->placeOf($a), $this->placeOf($b), $preference);
        $mapped = fn (array $row): bool => $row['destination']->latitude !== null && $row['destination']->longitude !== null;

        $seed = null;
        $bestScore = -1.0;
        foreach ($rows as $candidate) {
            if (! $mapped($candidate)) {
                continue;
            }
            $scores = [];
            foreach ($rows as $row) {
                if ($mapped($row) && $minutes($candidate['destination'], $row['destination']) <= self::REMOTE_NEIGHBOURHOOD_MINUTES) {
                    $scores[] = $row['drs'];
                }
            }
            rsort($scores);
            $score = array_sum(array_slice($scores, 0, $stops));
            // Strictly greater: of equal neighbourhoods the one reached first in the ranking wins.
            if ($score > $bestScore) {
                $bestScore = $score;
                $seed = $candidate['destination'];
            }
        }

        if ($seed === null) {
            return $ranked;
        }

        $inside = [];
        $outside = [];
        foreach ($rows as $row) {
            $away = $mapped($row) ? $minutes($seed, $row['destination']) : PHP_INT_MAX;
            if ($away <= self::REMOTE_NEIGHBOURHOOD_MINUTES) {
                $inside[] = $row;
            } else {
                $outside[] = ['away' => $away, 'row' => $row];
            }
        }
        // Stable for equal distances (PHP 8 sorts are stable), so the ranking's order breaks ties.
        usort($outside, fn (array $x, array $y) => $x['away'] <=> $y['away']);

        return collect(array_merge($inside, array_column($outside, 'row')))->values();
    }

    /** How many places beyond the trip's own are kept in reserve to fill an empty day. */
    private const RESERVE_STOPS = 8;

    /** How many empty days, and how many reserve places per empty day, are tried. */
    private const FILL_DAYS_TRIED = 3;

    private const FILL_PLACES_TRIED = 3;

    /**
     * A day with no sightseeing stop at all is a wasted day: usually the best-ranked places left for it were too far
     * to reach and visit before the cutoff, and the schedule dropped them without putting anything in their place.
     * For each such day, try the nearest reserve places (the next in the ranking) at the front of that day, and keep
     * the change only when it does not lose a stop anywhere and leaves fewer empty days. Otherwise the plan stays
     * exactly as it was built.
     *
     * @param  callable(array): mixed  $build  builds the schedule for a sequence
     * @param  array<int, array{row: array, distance_km: float|null}>  $built  the sequence the current plan was built from
     * @param  \Illuminate\Support\Collection<int, array>  $reserve  ranked rows not in the trip
     * @param  array<int, array<int, string>>  $dayCapacities
     * @return array<int, array{row: array, distance_km: float|null}>  the sequence the plan is now built from
     */
    private function fillEmptyDays(Itinerary $itinerary, callable $build, array $built, \Illuminate\Support\Collection $reserve, array $dayCapacities, array $origin): array
    {
        if ($reserve->isEmpty()) {
            return $built;
        }

        $stopsByDay = fn () => $itinerary->items()->where('kind', 'activity')->whereNotNull('destination_id')->orderBy('day_number')->orderBy('sort_order')->get()->groupBy('day_number');
        $emptyDays = fn () => array_values(array_diff(array_keys($dayCapacities), $stopsByDay()->keys()->all()));
        $placed = fn () => $itinerary->items()->whereNotNull('destination_id')->where('kind', 'activity')->distinct()->count('destination_id');
        $rebuild = function (array $sequence) use ($itinerary, $build) {
            $itinerary->items()->delete();
            $itinerary->unsetRelation('items');
            $build($sequence);
        };

        $tried = 0;

        foreach ($emptyDays() as $day) {
            if ($tried++ >= self::FILL_DAYS_TRIED) {
                break;
            }

            $byDay = $stopsByDay();
            $before = $byDay->filter(fn ($rows, $d) => $d < $day)->flatten(1);
            $startsAt = $before->isNotEmpty() && ($last = Destination::find($before->last()->destination_id)) && $last->latitude !== null
                ? [(float) $last->latitude, (float) $last->longitude]
                : [(float) $origin['lat'], (float) $origin['lng']];
            $inTrip = array_map(fn (array $e) => $e['row']['destination']->id, $built);

            $candidates = $reserve
                ->reject(fn (array $row) => in_array($row['destination']->id, $inTrip, true) || $row['destination']->latitude === null)
                ->sortBy(fn (array $row) => $this->haversineKm($startsAt[0], $startsAt[1], (float) $row['destination']->latitude, (float) $row['destination']->longitude))
                ->take(self::FILL_PLACES_TRIED);

            $stopsBefore = $placed();
            $emptyBefore = count($emptyDays());

            foreach ($candidates as $row) {
                $trial = $built;
                array_splice($trial, $before->unique('destination_id')->count(), 0, [['row' => $row, 'distance_km' => null]]);

                $rebuild($trial);

                if ($placed() >= $stopsBefore && count($emptyDays()) < $emptyBefore) {
                    $built = $trial;

                    continue 2;
                }
            }

            $rebuild($built); // nothing helped: put the plan back exactly as it was
        }

        return $built;
    }

    /**
     * Keeps only swaps that still make sense for this sequence: the original is
     * in the trip, and the replacement exists, is not already in the trip, and
     * is not being used for another swap.
     *
     * @param  array<int|string, int|string>  $swaps
     * @param  array<int, array{row: array, distance_km: float|null}>  $sequence
     * @return array<int, int>
     */
    private function applicableSwaps(array $swaps, array $sequence): array
    {
        $inTrip = array_map(fn (array $e) => $e['row']['destination']->id, $sequence);
        $valid = [];

        foreach ($swaps as $original => $replacement) {
            $original = (int) $original;
            $replacement = (int) $replacement;

            if (in_array($original, $inTrip, true)
                && ! in_array($replacement, $inTrip, true)
                && ! in_array($replacement, $valid, true)
                && Destination::publiclyVisible()->availableForTrip()->whereKey($replacement)->exists()) {
                $valid[$original] = $replacement;
            }
        }

        return $valid;
    }

    /**
     * Keeps only the best-ranked row per distinct destination name.
     *
     * $ranked is already sorted highest DRS to lowest (with the deterministic
     * tie-break already applied), so keeping the first occurrence of each
     * name keeps the best-scoring branch and drops the rest -- no new
     * comparison or randomness, just a name-based filter over an order that
     * was already decided by Content-Based Recommendation.
     *
     * @param  Collection<int, array{destination: \App\Models\Destination, pm: float, rs: float, ps: float, ds: float, as: float, drs: float}>  $ranked
     * @return Collection<int, array{destination: \App\Models\Destination, pm: float, rs: float, ps: float, ds: float, as: float, drs: float}>
     */
    private function distinctByDestinationName(\Illuminate\Support\Collection $ranked): \Illuminate\Support\Collection
    {
        return $ranked
            ->unique(fn (array $row) => mb_strtolower(trim($row['destination']->name)))
            ->values();
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

    /** A change of stay that is never worth making: Close Proximity (and Commutable's one-stay plan) use this as the penalty. */
    private const STRICT_STAY_PENALTY = 1000000;

    /** The way the traveller said they are willing to go, as the stay rule needs it: near, moderate or far. Unknown plans as moderate, like the drive budget. */
    private function stayMode(TouristPreference $preference): string
    {
        $forced = config('itinerary.stays.force_mode');
        $mode = in_array($forced, ['near', 'moderate', 'far'], true) ? $forced : $preference->distance_pref;

        return in_array($mode, ['near', 'moderate', 'far'], true) ? $mode : 'moderate';
    }

    /** @return array{lat: float|null, lng: float|null, region: string|null} */
    private function placeOf(mixed $model): array
    {
        $this->regionNames ??= \App\Models\Region::pluck('name', 'id');

        return [
            'lat' => $model->latitude === null ? null : (float) $model->latitude,
            'lng' => $model->longitude === null ? null : (float) $model->longitude,
            'region' => $this->regionNames[$model->region_id] ?? null,
        ];
    }

    /** Region names by id, read once per plan (see generate()). */
    private ?\Illuminate\Support\Collection $regionNames = null;

    /** Estimated slow-end minutes between two places; 0 when either has no position. */
    private function stayMinutes(array $a, array $b, TouristPreference $preference): int
    {
        if ($a['lat'] === null || $b['lat'] === null) {
            return 0;
        }

        return app(TravelTimeEstimator::class)->estimate(
            $this->haversineKm($a['lat'], $a['lng'], $b['lat'], $b['lng']), $a['region'], $b['region'], $preference->transport_pref,
        )['max_minutes'];
    }

    /**
     * Where each day starts and ends, from the stops the schedule is expected to fill it with: in order, up to the
     * day's slots. A day with no stop starts and ends where the traveller already is.
     *
     * @param  array<int, array{row: array, distance_km: float|null}>  $sequence
     * @param  array<int, array<int, string>>  $dayCapacities
     * @return array<int, array{first: array, last: array}>
     */
    private function plannedAnchors(array $sequence, array $dayCapacities, array $origin): array
    {
        $byDay = [];
        $offset = 0;
        foreach ($dayCapacities as $day => $slots) {
            $take = min(count($slots), max(0, count($sequence) - $offset));
            $byDay[$day] = array_map(fn (array $e) => $this->placeOf($e['row']['destination']), array_slice($sequence, $offset, $take));
            $offset += $take;
        }

        return $this->anchorsFromDays($byDay, $origin);
    }

    /**
     * The same anchors from the stops the schedule really placed, day by day (the itinerary as built).
     *
     * @return array<int, array{first: array, last: array}>
     */
    private function placedAnchors(Itinerary $itinerary, int $days, array $origin): array
    {
        $rows = $itinerary->items()->where('kind', 'activity')->whereNotNull('destination_id')->orderBy('day_number')->orderBy('sort_order')->get();
        $destinations = Destination::whereIn('id', $rows->pluck('destination_id')->unique())->get()->keyBy('id');
        $byDay = [];
        foreach (range(1, $days) as $day) {
            $byDay[$day] = $rows->where('day_number', $day)->map(fn ($r) => $this->placeOf($destinations[$r->destination_id]))->values()->all();
        }

        return $this->anchorsFromDays($byDay, $origin);
    }

    /** @param  array<int, array<int, array>>  $byDay */
    private function anchorsFromDays(array $byDay, array $origin): array
    {
        $here = ['lat' => $origin['lat'], 'lng' => $origin['lng'], 'region' => null];
        $anchors = [];
        foreach ($byDay as $day => $places) {
            $first = $places[0] ?? $here;
            $last = $places !== [] ? $places[array_key_last($places)] : $here;
            $anchors[$day] = ['first' => $first, 'last' => $last];
            $here = $last;
        }

        return $anchors;
    }

    /** The stated stay type and, for a resort, the stated setting. @return array{0: string|null, 1: string|null} */
    private function statedStay(TouristPreference $preference): array
    {
        $wanted = $preference->accommodation_pref;

        return [($wanted && $wanted !== 'Any') ? $wanted : null, $this->wantedResortSetting($preference)];
    }

    /**
     * The stays that may be offered on each night, and the only place that decides it: DOT-accredited and not archived,
     * open for the trip's dates, mapped, matching the stated type, and accredited ON that night (not marked Expired,
     * and either without an expiry date, expiring on or after that night, or covered by the test grace period).
     *
     * If the stated type leaves some night without any stay, the stated type is relaxed (a resort of any setting, then
     * any type) when that covers more nights; the accreditation test is never relaxed. A night nothing is eligible for
     * has no stay, and the itinerary says so.
     *
     * @return array<int, \Illuminate\Support\Collection<int, Accommodation>>  night => stays
     */
    private function eligibleStays(TouristPreference $preference, int $nights): array
    {
        if ($nights < 1) {
            return [];
        }

        [$wanted, $setting] = $this->statedStay($preference);
        // A stay with a position can be placed on the map and costed; one without is offered only as a last resort,
        // when no stay with a position is eligible for any night ("an unlocatable stay is still offered rather than none").
        $base = fn (bool $mapped) => Accommodation::where('is_accredited', true)->whereNull('archived_at')->availableForTrip()
            ->when($mapped, fn ($q) => $q->whereNotNull('latitude')->whereNotNull('longitude'),
                fn ($q) => $q->where(fn ($w) => $w->whereNull('latitude')->orWhereNull('longitude')));

        $tiers = [];
        foreach ([true, false] as $mapped) {
            $stated = $base($mapped);
            if ($setting !== null) {
                $stated->whereIn('type', self::RESORT_TYPES)->withResortSetting($setting);
            } elseif ($wanted !== null) {
                $stated->where('type', $wanted);
            }
            $tiers[] = $stated;
            if ($setting !== null) {
                $tiers[] = $base($mapped)->whereIn('type', self::RESORT_TYPES);
            }
            if ($setting !== null || $wanted !== null) {
                $tiers[] = $base($mapped);
            }
        }

        $tripStart = $preference->travelWindow()[0];
        $best = [];
        $bestCovered = -1;
        foreach ($tiers as $query) {
            $stays = $query->with('accreditationRecords')->orderBy('id')->get();
            $byNight = [];
            $covered = 0;
            foreach (range(1, $nights) as $night) {
                $date = $tripStart->copy()->addDays($night - 1);
                $byNight[$night] = $stays->filter(fn (Accommodation $s) => $this->accreditedOn($s, $date))->values();
                $covered += $byNight[$night]->isNotEmpty() ? 1 : 0;
            }
            if ($covered > $bestCovered) {
                $best = $byNight;
                $bestCovered = $covered;
            }
            if ($covered === $nights) {
                break;
            }
        }

        return $best;
    }

    /**
     * Apriori: stays that past travellers paired with one of the stops, the strongest rule for each stay.
     *
     * @param  array<int, array{row: array, distance_km: float|null}>  $sequence
     * @return array<int, array>  stay id => rule (with the stop it was paired with as 'basis')
     */
    private function suggestedStays(array $sequence, TouristPreference $preference): array
    {
        [$wanted, $setting] = $this->statedStay($preference);
        $suggested = [];
        foreach ($sequence as $stop) {
            $destination = $stop['row']['destination'];
            foreach ($this->apriori->suggestionsFor('destination', $destination->id, 3, 'accommodation') as $rule) {
                if ($this->fitsStayPreference($rule['listing'], $wanted, $setting)
                    && (! isset($suggested[$rule['listing_id']]) || $rule['confidence'] > $suggested[$rule['listing_id']]['confidence'])) {
                    $suggested[$rule['listing_id']] = $rule + ['basis' => $destination->name];
                }
            }
        }

        return $suggested;
    }

    /**
     * A stay for each night of the trip, chosen for the whole trip at once.
     *
     * Night n is spent between day n's last stop and day n+1's first stop, so a stay costs the journey time from the
     * one plus the journey time to the other, estimated the way the schedule estimates every journey (detour factor,
     * the Samal ferry, the traveller's way of getting around). Changing stay costs a penalty in minutes, so the plan
     * with the least total of journeys plus changes wins, found exactly over all the nights at once (the cost of a
     * night depends on the previous night only through whether the stay changed). Ties keep the stay, then go to the
     * better-rated one, then the lower id, so the result never depends on the order of anything.
     *
     * $flexible false makes a change never worth it (Close Proximity, and the one-stay side of Commutable): the plan has
     * a single stay unless eligibility leaves no single stay for every night, in which case it has the fewest. $flexible
     * true uses the configured penalty (Remote, and the flexible side of Commutable).
     *
     * A stay that past travellers paired with one of the stops (an Apriori rule) replaces the stay of a block of
     * nights when it is eligible on all of them and costs no more than the Apriori band in total over the block: the
     * preference can never create a change of stay, only choose between stays that are equally good.
     *
     * @param  array<int, array{first: array, last: array}>  $anchors  day => where it starts and ends
     * @param  array<int, \Illuminate\Support\Collection<int, Accommodation>>  $eligible  night => stays
     * @param  array<int, array>  $suggested  stay id => Apriori rule
     * @return array<int, array{listing: Accommodation, rule: array|null}|null>  night => stay, null where nothing is eligible
     */
    private function planStays(array $anchors, array $eligible, TouristPreference $preference, array $suggested, bool $flexible): array
    {
        $penalty = $flexible ? (int) config('itinerary.stays.transfer_penalty_minutes.far', 30) : self::STRICT_STAY_PENALTY;
        $band = (int) config('itinerary.stays.apriori_band_minutes', 15);

        $costs = [];
        $cost = function (int $night, Accommodation $stay) use (&$costs, $anchors, $preference): int {
            return $costs[$night][$stay->id] ??= $this->stayMinutes($anchors[$night]['last'], $this->placeOf($stay), $preference)
                + $this->stayMinutes($this->placeOf($stay), $anchors[$night + 1]['first'], $preference);
        };
        // A plan is compared by total minutes, then by fewer changes of stay, then by changing later rather than earlier
        // (the traveller keeps the stay they have as long as it costs nothing extra), then by rating and id.
        $order = fn (array $x, array $y): int => [...$x['key'], -(float) $x['stay']->rating, $x['stay']->id] <=> [...$y['key'], -(float) $y['stay']->rating, $y['stay']->id];
        $lastNight = array_key_last($eligible);
        // The eligible stays of each night by id, so nothing below searches a list.
        $stayById = array_map(fn ($stays) => $stays->keyBy('id')->all(), $eligible);

        // Blocks of consecutive nights that have at least one eligible stay; a night with none splits the trip.
        $segments = [];
        $run = [];
        foreach ($eligible as $night => $stays) {
            if ($stays->isNotEmpty()) {
                $run[] = $night;
            } elseif ($run !== []) {
                $segments[] = $run;
                $run = [];
            }
        }
        if ($run !== []) {
            $segments[] = $run;
        }

        $chosen = [];
        foreach ($segments as $segment) {
            $value = [];
            $from = [];
            foreach ($segment as $i => $night) {
                // The best stay of the night before to change from: the same for every stay of this night.
                $best = null;
                if ($i > 0) {
                    $entries = [];
                    foreach ($value[$i - 1] as $id => $key) {
                        $entries[] = ['key' => $key, 'stay' => $stayById[$segment[$i - 1]][$id]];
                    }
                    usort($entries, $order);
                    $best = $entries[0];
                    $switch = [$best['key'][0] + $penalty, $best['key'][1] + 1, $best['key'][2] + ($lastNight - $night + 1)];
                }
                foreach ($stayById[$night] as $id => $stay) {
                    $c = $cost($night, $stay);
                    if ($i === 0) {
                        $value[$i][$id] = [$c, 0, 0];

                        continue;
                    }
                    $keep = $value[$i - 1][$id] ?? null;
                    if ($keep !== null && $keep <= $switch) {
                        $value[$i][$id] = [$keep[0] + $c, $keep[1], $keep[2]];
                        $from[$i][$id] = $id;
                    } else {
                        $value[$i][$id] = [$switch[0] + $c, $switch[1], $switch[2]];
                        $from[$i][$id] = $best['stay']->id;
                    }
                }
            }
            $last = count($segment) - 1;
            $ends = [];
            foreach ($value[$last] as $id => $key) {
                $ends[] = ['key' => $key, 'stay' => $stayById[$segment[$last]][$id]];
            }
            usort($ends, $order);
            $id = $ends[0]['stay']->id;
            for ($i = $last; $i >= 0; $i--) {
                $chosen[$segment[$i]] = $stayById[$segment[$i]][$id];
                $id = $from[$i][$id] ?? $id;
            }
        }

        // Apriori, block by block: the same stay on consecutive nights is one block.
        if ($suggested !== []) {
            $nights = array_keys($chosen);
            sort($nights);
            $blocks = [];
            foreach ($nights as $night) {
                $tail = array_key_last($blocks);
                if ($tail !== null && end($blocks[$tail]) === $night - 1 && $chosen[$night]->id === $chosen[$night - 1]->id) {
                    $blocks[$tail][] = $night;
                } else {
                    $blocks[] = [$night];
                }
            }
            foreach ($blocks as $block) {
                $current = $chosen[$block[0]];
                $currentTotal = array_sum(array_map(fn (int $n) => $cost($n, $current), $block));
                $fits = [];
                foreach ($suggested as $stayId => $rule) {
                    $candidate = $stayById[$block[0]][$stayId] ?? null;
                    if (! $candidate || collect($block)->contains(fn (int $n) => ! isset($stayById[$n][$stayId]))) {
                        continue;
                    }
                    if (array_sum(array_map(fn (int $n) => $cost($n, $candidate), $block)) <= $currentTotal + $band) {
                        $fits[] = $rule;
                    }
                }
                if ($fits !== []) {
                    $pick = $this->weightedPick(collect($fits)->values(), $preference);
                    foreach ($block as $n) {
                        $chosen[$n] = $stayById[$n][$pick['listing_id']];
                    }
                }
            }
        }

        $plan = [];
        foreach (array_keys($eligible) as $night) {
            $stay = $chosen[$night] ?? null;
            $rule = $stay ? ($suggested[$stay->id] ?? null) : null;
            $plan[$night] = $stay ? ['listing' => $stay, 'rule' => $rule ? [
                'basis' => $rule['basis'], 'support' => $rule['support'], 'confidence' => $rule['confidence'], 'co_count' => $rule['co_count'],
            ] : null] : null;
        }

        return $plan;
    }

    /**
     * The stays for the stops the schedule is expected to fill each day with (a first plan, made before any day is built).
     *
     * @param  array<int, array{row: array, distance_km: float|null}>  $sequence
     * @param  array<int, array<int, string>>  $dayCapacities  day number => slot names
     * @return array<int, array{listing: Accommodation, rule: array|null}|null>|null  night => stay, or null when there is nothing to plan
     */
    private function pickStays(array $sequence, array $dayCapacities, TouristPreference $preference, array $origin, ?bool $flexible = null): ?array
    {
        if (count($dayCapacities) < 2 || $sequence === []) {
            return null;
        }

        $eligible = $this->eligibleStays($preference, count($dayCapacities) - 1);
        $plan = $this->planStays(
            $this->plannedAnchors($sequence, $dayCapacities, $origin),
            $eligible,
            $preference,
            $this->suggestedStays($sequence, $preference),
            $flexible ?? $this->stayMode($preference) === 'far',
        );

        return array_filter($plan) === [] ? null : $plan;
    }

    /** @param  array<int, array|null>|null  $plan  @return array<int, int|null> night => stay id */
    private function stayIds(?array $plan): array
    {
        return array_map(fn ($s) => $s['listing']->id ?? null, $plan ?? []);
    }

    /** How many times the stay changes from one night to the next, ignoring nights with no stay. */
    private function stayChanges(?array $plan): int
    {
        $changes = 0;
        $previous = null;
        foreach ($this->stayIds($plan) as $id) {
            if ($id === null) {
                continue;
            }
            if ($previous !== null && $previous !== $id) {
                $changes++;
            }
            $previous = $id;
        }

        return $changes;
    }

    /**
     * After the first build: pick the stays again for the stops that were really placed, and keep the new plan only if
     * the itinerary built with it is better by the rules in StayPlanEvaluator. Bounded by config: one rebuild, plus one
     * comparison build for a Commutable trip. Each trial is a build in the database; whichever result is chosen is put
     * back from a copy of its rows, so nothing is built a second time to undo a trial.
     *
     * @param  callable(array): mixed  $build  builds the schedule for a sequence with the current $stays
     * @param  array<int, array{row: array, distance_km: float|null}>  $built  the sequence the itinerary was built from
     * @param  array<int, array{row: array, distance_km: float|null}>  $planned  the stops the trip set out to visit
     * @param  array<int, array<int, string>>  $dayCapacities
     * @return array<string, mixed>  the value for itineraries.stay_plan
     */
    private function settleStays(Itinerary $itinerary, callable $build, ?array &$stays, array $built, array $planned, TouristPreference $preference, array $dayCapacities, array $origin): array
    {
        $days = count($dayCapacities);
        $mode = $this->stayMode($preference);
        $evaluator = app(StayPlanEvaluator::class);
        $eligible = $this->eligibleStays($preference, $days - 1);
        $first = $evaluator->metrics($itinerary, $preference, $origin);
        $guard = ['decision' => 'not_needed', 'failed' => null, 'extra_builds' => 0, 'first' => $evaluator->summary($first), 'final' => null];

        $snapshot = fn (): array => $itinerary->items()->orderBy('id')->get()->map(fn ($r) => $r->getAttributes())->all();
        $restore = function (array $rows) use ($itinerary): void {
            $itinerary->items()->delete();
            $itinerary->unsetRelation('items');
            foreach (array_chunk($rows, 50) as $chunk) {
                \App\Models\ItineraryItem::insert($chunk);
            }
        };
        $extra = 0;
        $cap = (int) config('itinerary.stays.max_extra_builds', 2);
        $try = function (array $plan) use (&$stays, &$extra, $build, $built, $itinerary, $evaluator, $preference, $origin, $snapshot): array {
            $extra++;
            $stays = $plan;
            $itinerary->items()->delete();
            $itinerary->unsetRelation('items');
            $build($built);

            return ['stays' => $plan, 'rows' => $snapshot(), 'm' => $evaluator->metrics($itinerary, $preference, $origin)];
        };

        $current = ['stays' => $stays, 'rows' => null, 'm' => $first];
        $chosen = $current;

        if ($stays !== null && array_filter($eligible, fn ($s) => $s->isNotEmpty()) !== []) {
            $current['rows'] = $snapshot();
            $chosen = $current;
            $anchors = $this->placedAnchors($itinerary, $days, $origin);
            $suggested = $this->suggestedStays($built, $preference);

            if ($mode !== 'moderate') {
                $plan = $this->planStays($anchors, $eligible, $preference, $suggested, $mode === 'far');
                if ($this->stayIds($plan) !== $this->stayIds($current['stays'])) {
                    $candidate = $try($plan);
                    [$ok, $failed] = $evaluator->accepts($first, $candidate['m'], $mode);
                    $guard['decision'] = $ok ? 'accepted' : (str_starts_with((string) $failed, 'H5') ? 'no_gain' : 'rejected');
                    $guard['failed'] = $failed;
                    $chosen = $ok ? $candidate : $current;
                }
            } else {
                $single = $this->planStays($anchors, $eligible, $preference, $suggested, false);
                $flex = $this->planStays($anchors, $eligible, $preference, $suggested, true);

                if ($this->stayChanges($flex) === 0 || $this->stayIds($flex) === $this->stayIds($single)) {
                    // Nothing for a flexible plan to improve: one stay, settled on the stops placed.
                    if ($this->stayIds($single) !== $this->stayIds($current['stays'])) {
                        $candidate = $try($single);
                        [$ok, $failed] = $evaluator->accepts($first, $candidate['m'], $mode);
                        $guard['decision'] = $ok ? 'accepted' : (str_starts_with((string) $failed, 'H5') ? 'no_gain' : 'rejected');
                        $guard['failed'] = $failed;
                        $chosen = $ok ? $candidate : $current;
                    }
                } else {
                    // The flexible plan changes stay: build both and let the comparison decide (at most two extra builds).
                    $one = $this->stayIds($single) === $this->stayIds($current['stays']) ? $current : $try($single);
                    $flexible = $extra < $cap ? $try($flex) : null;
                    $guard['decision'] = 'kept_one_stay';
                    if ($flexible !== null) {
                        [$impractical, $why] = $evaluator->oneStayIsImpractical($one['m'], $flexible['m']);
                        if ($impractical) {
                            [$ok, $failed] = $evaluator->accepts($first, $flexible['m'], 'far');
                            if ($ok) {
                                $chosen = $flexible;
                                $guard['decision'] = 'accepted_flexible';
                                $guard['failed'] = $why;
                            } else {
                                $guard['failed'] = $failed;
                            }
                        }
                    }
                    if ($chosen === $current && $one !== $current) {
                        [$ok, $failed] = $evaluator->accepts($first, $one['m'], $mode);
                        $chosen = $ok ? $one : $current;
                        $guard['failed'] ??= $ok ? null : $failed;
                    }
                }
            }

            if ($extra > 0) {
                $restore($chosen['rows']);
                $stays = $chosen['stays'];
            }
        }

        $guard['extra_builds'] = $extra;
        $final = $chosen['m'];
        $guard['final'] = $evaluator->summary($final);

        return [
            'version' => 1,
            'mode' => $mode,
            'continuity' => [
                'nights' => $days - 1,
                'distinct_stays' => count(array_unique($final['stays'])),
                'transfers' => $final['transfers'],
            ],
            'notices' => $this->stayNotices($final, $planned, $days, $mode, $this->schedule->recordedAttempts($built, $stays, $dayCapacities)),
            'guard' => $guard,
        ];
    }

    /**
     * What the traveller should be told: nights with no accredited stay, a Close Proximity trip that could not use one
     * stay all the way, and every destination the trip set out to visit but could not place, with the reason.
     *
     * A reason is only ever one the schedule builder recorded when it turned the place away (see
     * ItineraryScheduleBuilder::recordedAttempts). A place it never turned away on a recorded ground, or a build that
     * was not recorded, gets the reason 'not_recorded' and no attempts: the page then says only that it was not added.
     *
     * @param  array<int, array{row: array, distance_km: float|null}>  $planned
     * @param  array<int, array<int, array<string, mixed>>>|null  $attempts  the kept build's recorded attempts, by destination id
     * @return array<int, array<string, mixed>>
     */
    private function stayNotices(array $final, array $planned, int $days, string $mode, ?array $attempts = null): array
    {
        $notices = [];
        foreach (range(1, $days - 1) as $night) {
            if (! isset($final['stays'][$night])) {
                $notices[] = ['type' => 'no_stay_for_night', 'night' => $night];
            }
        }
        if ($mode === 'near' && count(array_unique($final['stays'])) > 1) {
            $notices[] = ['type' => 'no_single_stay_covers_trip'];
        }

        return array_merge($notices, $this->unplacedNotices($final['placed'], $planned, $attempts));
    }

    /**
     * One notice for every planned destination that is not among the placed ones, so none leaves the trip unmentioned.
     *
     * @param  array<int, int>  $placedIds
     * @param  array<int, array{row: array, distance_km: float|null}>  $planned
     * @param  array<int, array<int, array<string, mixed>>>|null  $attempts
     * @return array<int, array<string, mixed>>
     */
    private function unplacedNotices(array $placedIds, array $planned, ?array $attempts): array
    {
        $notices = [];
        foreach ($planned as $entry) {
            $destination = $entry['row']['destination'];
            if (in_array($destination->id, $placedIds, true)) {
                continue;
            }
            $found = $attempts[$destination->id] ?? [];
            $notices[] = [
                'type' => 'unplaced_destination',
                'destination_id' => $destination->id,
                'name' => $destination->name,
                'reason' => $found === [] ? 'not_recorded' : $this->mainCause($found),
                'attempts' => $found,
            ];
        }

        return $notices;
    }

    /** The most telling of the recorded causes: a place that is shut says more than a day that was full. */
    private function mainCause(array $attempts): string
    {
        $order = ['closed_that_day', 'outside_opening_hours', 'would_end_after_cutoff', 'drive_budget_reached', 'day_full', 'no_sightseeing_time_that_day'];
        $causes = array_column($attempts, 'cause');

        foreach ($order as $cause) {
            if (in_array($cause, $causes, true)) {
                return $cause;
            }
        }

        return 'not_recorded';
    }

    /** Is this stay accredited on the given night? See pickStays. A stay with no record at all is not ruled out here. */
    private function accreditedOn(Accommodation $stay, \Illuminate\Support\Carbon $night): bool
    {
        $records = $stay->accreditationRecords;

        if ($records->isEmpty()) {
            return true;
        }

        return $records->contains(fn ($r) => $r->status !== 'Expired'
            && ($r->expiration_date === null || $r->expiration_date->toDateString() >= $night->toDateString() || AccreditationGrace::covers($night)));
    }

    /** How much the stop farthest from a candidate stay counts, next to the total of all stops (see pickAccommodation). */
    private const STAY_FARTHEST_WEIGHT = 2.0;

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
        $setting = $this->wantedResortSetting($preference);
        $mappedStops = $this->mappedStopCoordinates($dayStops);

        foreach ($dayStops as $stop) {
            $destination = $stop['row']['destination'];
            $suggestions = $this->apriori->suggestionsFor('destination', $destination->id, 3, 'accommodation')
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
                ->filter(fn ($rule) => $this->fitsStayPreference($rule['listing'], $wanted, $setting))
                // Past travellers pairing a stay with the FIRST stop says nothing about the other days: a stay more than
                // this far from any stop of the trip is passed over for one chosen by distance below.
                ->filter(fn ($rule) => ! $this->tooFarFromTrip($rule['listing'], $mappedStops))
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

        $query = Accommodation::where('is_accredited', true)->whereNull('archived_at')->availableForTrip();
        if ($setting !== null) {
            $query->whereIn('type', self::RESORT_TYPES)->withResortSetting($setting);
        } elseif ($wanted !== null) {
            $query->where('type', $wanted);
        }

        /*
         * Prefer a stay we can actually place on the map, and place it to
         * minimise the total distance back to it across the whole trip --
         * not the distance to the average of the stops' coordinates. The
         * two sound alike but are not the same thing: a single far-flung
         * stop (a "willing to travel far" trip that includes Dahican Beach,
         * ~68 km out on its own) drags a centroid out to a point that isn't
         * actually near anything, and the accommodation nearest to *that*
         * empty patch of map is not the one that minimises real travel. The
         * sum of distances to every stop does not have this failure mode.
         *
         * Picking by rating alone here (every listing in this catalogue is
         * tied at 0.0, so in practice "first matching row") could -- and
         * did -- land on a stay across a strait from where the day's stops
         * actually are: a traveller sequenced onto the mainland for the
         * afternoon got booked back onto Samal Island for the night, adding
         * a return ferry crossing nothing about the plan called for. Rating
         * still breaks ties among stays at similar total distance; an
         * unlocatable stay is still offered rather than none at all.
         */
        $listing = $mappedStops->isNotEmpty()
                ? (clone $query)->whereNotNull('latitude')->whereNotNull('longitude')->get()
                    ->sortBy(function (Accommodation $a) use ($mappedStops) {
                        $distances = $mappedStops->map(fn (array $stop) => $this->haversineKm(
                            (float) $a->latitude, (float) $a->longitude, $stop['lat'], $stop['lng']
                        ));

                        // The total keeps the stay central; adding the farthest stop stops it being picked where one
                        // day's stop is an hours-long drive away while the others are close.
                        return $distances->sum() + self::STAY_FARTHEST_WEIGHT * $distances->max();
                    })
                    ->first()
                : null;

        $listing ??= (clone $query)->whereNotNull('latitude')->whereNotNull('longitude')
                ->orderByDesc('rating')->first()
            ?? $query->orderByDesc('rating')->first()
            // No resort of that setting is available: a resort of any setting is still closer to the ask than a hotel.
            ?? ($setting !== null
                ? Accommodation::where('is_accredited', true)->whereNull('archived_at')->availableForTrip()
                    ->whereIn('type', self::RESORT_TYPES)->orderByDesc('rating')->first()
                : null)
            ?? Accommodation::where('is_accredited', true)->whereNull('archived_at')->availableForTrip()
                ->orderByDesc('rating')->first();

        return $listing ? ['listing' => $listing, 'rule' => null] : null;
    }

    /** What counts as a resort when a traveller asks for one by setting: an island resort is filed as a Beach Resort. */
    private const RESORT_TYPES = ['Resort', 'Beach Resort'];

    /** The resort setting the traveller asked for, if they asked for a resort and named a setting we know. */
    private function wantedResortSetting(TouristPreference $preference): ?string
    {
        return $preference->accommodation_pref === 'Resort'
            && in_array($preference->resort_setting, Accommodation::RESORT_SETTINGS, true)
            ? $preference->resort_setting
            : null;
    }

    /** Whether a listing satisfies the stated accommodation type and, for resorts, the stated setting. */
    private function fitsStayPreference(Accommodation $stay, ?string $wanted, ?string $setting): bool
    {
        if ($setting !== null) {
            return in_array($stay->type, self::RESORT_TYPES, true) && in_array($setting, $stay->resortSettingList(), true);
        }

        return $wanted === null || $stay->type === $wanted;
    }

    /** The farthest a suggested stay may be from any sightseeing stop of the trip, in km. */
    private const STAY_MAX_KM = 60.0;

    /** True when a mapped stay is over STAY_MAX_KM from some stop; a stay with no position is never ruled out. */
    private function tooFarFromTrip(Accommodation $stay, \Illuminate\Support\Collection $mappedStops): bool
    {
        if ($stay->latitude === null || $stay->longitude === null || $mappedStops->isEmpty()) {
            return false;
        }

        return $mappedStops->contains(fn (array $stop) => $this->haversineKm(
            (float) $stay->latitude, (float) $stay->longitude, $stop['lat'], $stop['lng']
        ) > self::STAY_MAX_KM);
    }

    /**
     * The coordinates of every stop that has them, or an empty collection
     * when none do -- the same "unknown means unknown, not the equator"
     * rule applied everywhere else a stop's position is needed.
     *
     * @param  array<int, array{row: array, distance_km: float|null}>  $dayStops
     * @return \Illuminate\Support\Collection<int, array{lat: float, lng: float}>
     */
    private function mappedStopCoordinates(array $dayStops): \Illuminate\Support\Collection
    {
        $mapped = collect($dayStops)
            ->map(fn (array $stop) => $stop['row']['destination'])
            ->filter(fn ($destination) => $destination->latitude !== null && $destination->longitude !== null);

        return $mapped->map(fn ($destination) => [
            'lat' => (float) $destination->latitude,
            'lng' => (float) $destination->longitude,
        ]);
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
