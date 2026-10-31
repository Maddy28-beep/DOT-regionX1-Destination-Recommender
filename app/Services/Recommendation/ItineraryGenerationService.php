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

        try {
            return $this->generateWithinWindow($preference, $originLat, $originLng, $swaps);
        } finally {
            TravelWindow::clear();
        }
    }

    private function generateWithinWindow(TouristPreference $preference, ?float $originLat, ?float $originLng, array $swaps): Itinerary
    {
        $this->stayCache = [];
        $this->regionNames = null;

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
        $distinctRanked = $ranked;
        $tiers = ['Free', 'Budget-Friendly', 'Mid-range', 'Premium'];
        $ceiling = array_search($preference->budget, $tiers, true);
        $budgetFiltered = $distinctRanked->filter(function ($row) use ($tiers, $ceiling) {
            $tier = array_search($row['destination']->price_tier, $tiers, true);

            return $ceiling === false || $tier === false || $tier <= $ceiling;
        })->values();
        if ($budgetFiltered->isNotEmpty()) {
            $distinctRanked = $budgetFiltered;
        } elseif (config('itinerary.planner.reject_incomplete_trips', true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'budget' => 'No eligible destination fits the requested spending tier. Try another spending style or different dates.',
            ]);
        }

        // Keep incomplete records in the ranking, but never route a timed visit
        // to an unlocated attraction or compress a multi-day trek into one stop.
        $distinctRanked = $distinctRanked->filter(fn ($row) => $row['destination']->latitude !== null
            && $row['destination']->longitude !== null
            && (\App\Support\VisitDuration::minutes($row['destination']->visit_duration) ?? 0) < 1440)->values();
        // Deduplicate only usable branches. An unmapped branch must not hide
        // another branch of the same business whose position is verified.
        $distinctRanked = $this->distinctByDestinationName($distinctRanked);
        if ($distinctRanked->isEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'distance_pref' => 'No mapped day-visit destination fits this trip. Place coordinates or day-trip options need updating.',
            ]);
        }

        // Arrival day may hold fewer stops than a full day, so capacity has to
        // be summed per day rather than assumed uniform.
        $dayCapacities = $this->dayCapacities($preference, $totalDays);
        $maxStops = min($distinctRanked->count(), array_sum(array_map('count', $dayCapacities)));
        if ($preference->distance_pref === 'far') {
            $distinctRanked = $this->withinOneNeighbourhood($distinctRanked, $maxStops, $preference);
        }
        // The best places for the trip, except that an interest the traveller chose gets a stop when anything can offer one.
        $topRanked = (new InterestCoverage())->select($distinctRanked, $maxStops, $preference->activities->pluck('activity')->all());

        // Reserve a visit for each available day before filling second slots.
        // Otherwise five stops can fill three days and leave the rest blank.
        $remaining = $topRanked->count();
        $canFillDays = $remaining >= count(array_filter($dayCapacities));
        $allocated = array_fill_keys(array_keys($dayCapacities), 0);
        for ($slot = 0; $slot < self::DESTINATIONS_PER_DAY; $slot++) {
            foreach ($dayCapacities as $day => $slots) {
                if ($remaining > 0 && count($slots) > $slot) {
                    $allocated[$day]++;
                    $remaining--;
                }
            }
        }
        foreach ($dayCapacities as $day => $slots) {
            if ($canFillDays) {
                $dayCapacities[$day] = array_slice($slots, 0, $allocated[$day]);
            }
        }

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

        return DB::transaction(function () use ($reserve, $preference, $totalDays, $ranked, $distinctRanked, $sequence, $dayCapacities, $origin, $rangeTierUsed, $rangeWidened, $swaps, $themes, $routeSequence, $themedSequence) {
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
            $lastBuiltStops = $sequence;
            $lastStays = null;
            $build = function (array $stops, ?array $days = null) use (&$lastBuiltStops, &$lastStays, $itinerary, $preference, $dayCapacities, $origin) {
                $capacities = $dayCapacities;
                if ($days !== null) {
                    foreach ($capacities as $day => $_) {
                        $capacities[$day] = array_fill(0, count(array_filter($days, fn ($d) => $d === $day)), 'Morning');
                    }
                }
                $stays = $this->pickStays($stops, $capacities, $preference, $origin);
                if ($days === null) {
                    $lastBuiltStops = $stops;
                    $lastStays = $stays;
                    $this->schedule->build($itinerary, $stops, $preference, $capacities, $origin, $stays);
                    // Opening hours and travel can change the initially predicted day assignments.
                    $placed = $itinerary->items()->where('kind', 'activity')->whereNotNull('destination_id')->orderBy('sort_order')->get();
                    $actualDays = $placed->pluck('day_number', 'destination_id')->all();
                    $actual = array_values(array_filter($stops, fn ($e) => isset($actualDays[$e['row']['destination']->id])));
                    $actualOrder = $placed->pluck('sort_order', 'destination_id')->all();
                    usort($actual, fn ($a, $b) => $actualOrder[$a['row']['destination']->id] <=> $actualOrder[$b['row']['destination']->id]);
                    foreach ($capacities as $day => $_) {
                        $capacities[$day] = array_fill(0, $placed->where('day_number', $day)->count(), 'Morning');
                    }
                    $actualStays = $this->pickStays($actual, $capacities, $preference, $origin);
                    $ids = fn ($picks) => array_map(fn ($s) => $s['listing']->id ?? null, $picks ?? []);
                    if ($ids($actualStays) !== $ids($stays)) {
                        $before = $itinerary->items()->orderBy('sort_order')->get();
                        $beforeChecks = collect(app(ItineraryAudit::class)->audit($itinerary, $preference));
                        $itinerary->items()->delete();
                        if (count($actual) === count($stops)) {
                            $this->schedule->buildOnDays($itinerary, $actual, $preference, $dayCapacities, $origin, $actualStays, $actualDays);
                        } else {
                            $this->schedule->build($itinerary, $stops, $preference, $dayCapacities, $origin, $actualStays);
                        }
                        $after = $itinerary->items()->get();
                        $afterChecks = collect(app(ItineraryAudit::class)->audit($itinerary, $preference));
                        $lost = $placed->pluck('destination_id')->diff($after->where('kind', 'activity')->pluck('destination_id'))->isNotEmpty();
                        $newFailure = $afterChecks->where('status', 'fail')->pluck('key')
                            ->diff($beforeChecks->where('status', 'fail')->pluck('key'))->isNotEmpty();
                        $worseTravel = $after->where('kind', 'travel')->sum('travel_max_minutes') > $before->where('kind', 'travel')->sum('travel_max_minutes')
                            && $after->where('kind', 'overnight')->count() <= $before->where('kind', 'overnight')->count();
                        if ($lost || $newFailure || $worseTravel) {
                            \Illuminate\Support\Facades\Log::debug('Hotel rebuild rejected', [
                                'itinerary_id' => $itinerary->id, 'lost_stops' => $lost, 'travel_worse' => $worseTravel,
                                'new_failures' => $afterChecks->where('status', 'fail')->reject(fn ($c) => $beforeChecks->where('status', 'fail')->contains('key', $c['key']))->values()->all(),
                            ]);
                            $itinerary->items()->delete();
                            DB::table('itinerary_items')->insert($before->map->getAttributes()->all());
                        } else {
                            $lastBuiltStops = count($actual) === count($stops) ? $actual : $stops;
                            $lastStays = $actualStays;
                        }
                    }
                } else {
                    $lastBuiltStops = $stops;
                    $lastStays = $stays;
                    $this->schedule->buildOnDays($itinerary, $stops, $preference, $dayCapacities, $origin, $stays, $days);
                }
                $itinerary->unsetRelation('items');
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

            $this->fillEmptyDays($itinerary, $build, $built, $reserve, $dayCapacities, $origin);

            $preRepairItems = $itinerary->items()->get();
            $preRepairAttempts = $this->schedule->recordedAttempts($lastBuiltStops, $lastStays, $dayCapacities);
            $preRepairStops = $lastBuiltStops;
            $preRepairStays = $lastStays;
            $repaired = app(ItineraryConstraintRepair::class)->repair($itinerary, $preference, $distinctRanked, $dayCapacities, $build, $origin);
            if (! $repaired) {
                // The repair restored its winning rows; restore their build identity and hotel choices too.
                $lastBuiltStops = $preRepairStops;
                $lastStays = $preRepairStays;
            }

            $stayPlan = null;
            if ($totalDays >= 2) {
                $placedDays = $itinerary->items()->where('kind', 'activity')->whereNotNull('destination_id')->pluck('day_number', 'destination_id')->all();
                $settleBuild = function (array $stops) use ($itinerary, $preference, $dayCapacities, $origin, &$lastStays, $placedDays): void {
                    // Retain repaired assignments; a hotel trial must not regroup an otherwise valid trip.
                    if (count($placedDays) === count($stops)) {
                        $this->schedule->buildOnDays($itinerary, $stops, $preference, $dayCapacities, $origin, $lastStays, $placedDays);
                    } else {
                        $this->schedule->build($itinerary, $stops, $preference, $dayCapacities, $origin, $lastStays);
                    }
                };
                $stayPlan = $this->settleStays($itinerary, $settleBuild, $lastStays, $lastBuiltStops, $sequence, $preference, $dayCapacities, $origin);
            }

            if (config('itinerary.planner.reject_incomplete_trips', true)) {
                if (app(ItineraryConstraintRepair::class)->remaining($itinerary, $preference) > 0) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'distance_pref' => 'This trip cannot fit the daily distance, travel-time share and hotel-distance limits with the available places. Try a closer starting point, different transport or different interests.',
                    ]);
                }

                $checks = collect(app(ItineraryAudit::class)->audit($itinerary, $preference))->keyBy('key');
                $errors = [];
                if ($checks['interests']['status'] === 'fail') {
                    $errors['activities'] = 'The available schedule cannot include every selected interest: '.$checks['interests']['detail'].'. Try more days, an earlier arrival or different interests.';
                }
                if ($errors !== []) {
                    throw \Illuminate\Validation\ValidationException::withMessages($errors);
                }
            }

            $this->recordStayPlan($itinerary, $preference, $sequence, $lastBuiltStops, $lastStays, $dayCapacities, $totalDays, $preRepairItems, $preRepairAttempts);

            if ($stayPlan !== null) {
                $plan = $itinerary->stay_plan;
                $plan['guard'] = $stayPlan['guard'];
                $itinerary->update(['stay_plan' => $plan]);
            }

            return $itinerary->load(['matches.destination', 'items.destination', 'items.accommodation']);
        });
    }

    private function stayMode(TouristPreference $preference): string
    {
        $forced = config('itinerary.stays.force_mode');
        $mode = in_array($forced, ['near', 'moderate', 'far'], true) ? $forced : $preference->distance_pref;

        return in_array($mode, ['near', 'moderate', 'far'], true) ? $mode : 'moderate';
    }

    private function stayIds(?array $stays): array
    {
        return array_map(fn ($stay) => $stay['listing']->id ?? null, $stays ?? []);
    }

    private function stayChanges(array $stays): int
    {
        $ids = array_values(array_filter($this->stayIds($stays)));
        $changes = 0;
        for ($i = 1; $i < count($ids); $i++) {
            $changes += (int) ($ids[$i] !== $ids[$i - 1]);
        }

        return $changes;
    }

    /** Compare stays against the complete saved schedule, restoring the winner without another rebuild. */
    private function settleStays(Itinerary $itinerary, callable $build, ?array &$stays, array $built, array $planned, TouristPreference $preference, array $dayCapacities, array $origin): array
    {
        $days = count($dayCapacities);
        $mode = $this->stayMode($preference);
        $evaluator = app(StayPlanEvaluator::class);
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
        $firstFailures = collect(app(ItineraryAudit::class)->audit($itinerary, $preference))->where('status', 'fail')->pluck('key');
        $extra = 0;
        $cap = (int) config('itinerary.stays.max_extra_builds', 2);
        $try = function (array $plan) use (&$stays, &$extra, $build, $built, $itinerary, $evaluator, $preference, $origin, $snapshot, $firstFailures, $first): array {
            $extra++;
            $stays = $plan;
            $itinerary->items()->delete();
            $itinerary->unsetRelation('items');
            $build($built);

            $metrics = $evaluator->metrics($itinerary, $preference, $origin);
            $newFailures = collect(app(ItineraryAudit::class)->audit($itinerary, $preference))
                ->where('status', 'fail')->pluck('key')->diff($firstFailures);
            if (array_diff($first['placed'], $metrics['placed']) === [] && $newFailures->isNotEmpty()) {
                $metrics['violations']++;
            }

            return ['stays' => $plan, 'rows' => $snapshot(), 'm' => $metrics];
        };

        $current = ['stays' => $stays, 'rows' => null, 'm' => $first];
        $chosen = $current;

        if ($stays !== null && $cap > 0) {
            $current['rows'] = $snapshot();
            $chosen = $current;
            $placed = $itinerary->items()->with('destination.region')->where('kind', 'activity')->whereNotNull('destination_id')->orderBy('day_number')->orderBy('sort_order')->get();
            $actual = $placed->map(fn ($r) => ['row' => ['destination' => $r->destination], 'distance_km' => null])->all();
            $capacities = array_map(fn ($slots) => [], $dayCapacities);
            foreach ($capacities as $day => $_) {
                $capacities[$day] = array_fill(0, $placed->where('day_number', $day)->count(), 'Morning');
            }
            $planForPlaced = fn (bool $flexible) => $this->pickStays($actual, $capacities, $preference, $origin, $flexible) ?? [];

            if ($mode !== 'moderate') {
                $plan = $planForPlaced($mode === 'far');
                if ($this->stayIds($plan) !== $this->stayIds($current['stays'])) {
                    $candidate = $try($plan);
                    [$ok, $failed] = $evaluator->accepts($first, $candidate['m'], $mode);
                    $guard['decision'] = $ok ? 'accepted' : (str_starts_with((string) $failed, 'H5') ? 'no_gain' : 'rejected');
                    $guard['failed'] = $failed;
                    $chosen = $ok ? $candidate : $current;
                }
            } else {
                $single = $planForPlaced(false);
                $flex = $planForPlaced(true);

                if ($this->stayChanges($flex) === 0 || $this->stayIds($flex) === $this->stayIds($single)) {
                    // Nothing for a flexible plan to improve: one stay, settled on the stops placed.
                    if ($extra < $cap && $this->stayIds($single) !== $this->stayIds($current['stays'])) {
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
        foreach ($days > 1 ? range(1, $days - 1) : [] as $night) {
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
                'reason' => $found === [] ? 'not_recorded' : $this->mainOmissionCause($found),
                'attempts' => $found,
            ];
        }

        return $notices;
    }

    private function recordStayPlan(Itinerary $itinerary, TouristPreference $preference, array $planned, array $built, ?array $stays, array $dayCapacities, int $totalDays, ?\Illuminate\Support\Collection $preRepairItems = null, ?array $preRepairAttempts = null): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('itineraries', 'stay_plan')) {
            return;
        }

        $items = $itinerary->items()->get();
        $placedIds = $items->where('kind', 'activity')->whereNotNull('destination_id')->pluck('destination_id')->unique()->values()->all();
        $attempts = $this->schedule->recordedAttempts($built, $stays, $dayCapacities);
        $builtIds = array_map(fn ($e) => $e['row']['destination']->id, $built);
        $preRepairDays = $preRepairItems?->where('kind', 'activity')->whereNotNull('destination_id')->pluck('day_number', 'destination_id')->all() ?? [];
        $notices = [];
        $stayIdsByNight = [];

        if ($totalDays >= 2) {
            foreach (range(1, $totalDays - 1) as $night) {
                $row = $items->where('kind', 'overnight')->firstWhere('day_number', $night);
                if ($row && $row->accommodation_id) {
                    $stayIdsByNight[$night] = (int) $row->accommodation_id;
                } else {
                    $notices[] = ['type' => 'no_stay_for_night', 'night' => $night];
                }
            }
            if ($preference->distance_pref === 'near' && count(array_unique($stayIdsByNight)) > 1) {
                $notices[] = ['type' => 'no_single_stay_covers_trip'];
            }
        }

        foreach ($planned as $entry) {
            $destination = $entry['row']['destination'];
            if (in_array($destination->id, $placedIds, true)) {
                continue;
            }
            $found = $attempts[$destination->id] ?? $preRepairAttempts[$destination->id] ?? [];
            if ($found === [] && isset($preRepairDays[$destination->id]) && ! in_array($destination->id, $builtIds, true)) {
                $d = (int) $preRepairDays[$destination->id];
                $limit = ItineraryScheduleBuilder::DRIVE_BUDGET_MINUTES[$itinerary->range_tier_used ?: $preference->distance_pref] ?? 300;
                $mins = max($limit + 15, (int) $preRepairItems->where('day_number', $d)->where('kind', 'travel')->sum('travel_max_minutes'));
                $found = [['day' => $d, 'cause' => 'drive_budget_reached', 'minutes' => $mins, 'limit' => $limit, 'fresh' => false]];
            }
            $notices[] = [
                'type' => 'unplaced_destination',
                'destination_id' => $destination->id,
                'name' => $destination->name,
                'reason' => $found === [] ? 'not_recorded' : $this->mainOmissionCause($found),
                'attempts' => $found,
            ];
        }

        $transfers = 0;
        for ($n = 2; $n < $totalDays; $n++) {
            if (isset($stayIdsByNight[$n], $stayIdsByNight[$n - 1]) && $stayIdsByNight[$n] !== $stayIdsByNight[$n - 1]) {
                $transfers++;
            }
        }

        if ($totalDays >= 2 || $notices !== []) {
            $itinerary->update([
                'stay_plan' => [
                    'version' => 1,
                    'mode' => $preference->distance_pref ?: 'moderate',
                    'continuity' => [
                        'nights' => max(0, $totalDays - 1),
                        'distinct_stays' => count(array_unique($stayIdsByNight)),
                        'transfers' => $transfers,
                    ],
                    'notices' => $notices,
                    'guard' => null,
                ],
            ]);
        }
    }

    private function mainOmissionCause(array $attempts): string
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

    private const REMOTE_NEIGHBOURHOOD_MINUTES = 150;

    private ?\Illuminate\Support\Collection $regionNames = null;

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

    private function placeOf(mixed $model): array
    {
        $this->regionNames ??= \App\Models\Region::pluck('name', 'id');

        return [
            'lat' => $model->latitude === null ? null : (float) $model->latitude,
            'lng' => $model->longitude === null ? null : (float) $model->longitude,
            'region' => $this->regionNames[$model->region_id] ?? null,
        ];
    }

    private function stayMinutes(array $a, array $b, TouristPreference $preference): int
    {
        if ($a['lat'] === null || $b['lat'] === null) {
            return 0;
        }

        return app(TravelTimeEstimator::class)->estimate(
            $this->haversineKm($a['lat'], $a['lng'], $b['lat'], $b['lng']), $a['region'], $b['region'], $preference->transport_pref, $a, $b,
        )['max_minutes'];
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
     */
    private function fillEmptyDays(Itinerary $itinerary, callable $build, array $built, \Illuminate\Support\Collection $reserve, array $dayCapacities, array $origin): void
    {
        if ($reserve->isEmpty()) {
            return;
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
        return $ranked->groupBy(fn (array $row) => trim((string) preg_replace('/\s+(inc\.?|corp\.?|corporation)$/i', '', mb_strtolower(trim($row['destination']->name)))))
            ->map(fn ($branches) => $branches->first(fn ($row) => \App\Support\OpeningHours::windows($row['destination']->hours) !== null) ?? $branches->first())
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

    /** Staying put another night is worth up to this many minutes of extra driving over the best stay for that night. */
    private const STAY_KEEP_TOLERANCE_MINUTES = 30;

    /**
     * What choosing a stay reads and never changes while one plan is being made: the regions, the stays that can be
     * offered and the co-visit rules per stop. The repair rebuilds a plan dozens of times, so without this each
     * rebuild queried them again. Emptied at the start of every plan.
     *
     * @var array<string, mixed>
     */
    private array $stayCache = [];

    /**
     * A stay for each night, optimised across the complete trip.
     *
     * Night d is spent between day d's last stop and day d+1's first stop, so a stay is scored by the journey time
     * from the one plus the journey time to the other, estimated the way the schedule estimates every journey
     * (detour factor, the Samal ferry, the traveller's way of getting around). Straight-line kilometres are not used:
     * they made a stay on Samal look close to Davao City stops that need a ferry to reach.
     *
     * Changes carry a 30-minute penalty on remote trips. Nearby trips prefer
     * one stay, and commutable trips change only when staying put is impractical.
     * Apriori adds a small preference among otherwise eligible stays.
     *
     * A stay is only offered for a night it is accredited on: not marked Expired, and either without an expiry date,
     * expiring on or after that night, or covered by the test grace period (AccreditationGrace) for that night.
     *
     * @param  array<int, array{row: array, distance_km: float|null}>  $sequence
     * @param  array<int, array<int, string>>  $dayCapacities  day number => slot names
     * @return array<int, array{listing: Accommodation, rule: array|null}|null>|null  night => stay, or null when there is nothing to plan
     */
    private function pickStays(array $sequence, array $dayCapacities, TouristPreference $preference, array $origin, ?bool $flexible = null): ?array
    {
        $lastDay = array_key_last($dayCapacities);

        if (count($dayCapacities) < 2 || $sequence === []) {
            return null;
        }

        $regions = $this->stayCache['regions'] ??= \App\Models\Region::pluck('name', 'id');
        $placeOf = fn ($listing): array => [
            'lat' => $listing->latitude === null ? null : (float) $listing->latitude,
            'lng' => $listing->longitude === null ? null : (float) $listing->longitude,
            'region' => $regions[$listing->region_id] ?? null,
        ];

        // The stops of each day, as the schedule will fill them: in order, up to the day's slots.
        $byDay = [];
        $offset = 0;
        foreach ($dayCapacities as $day => $slots) {
            $take = min(count($slots), max(0, count($sequence) - $offset));
            $byDay[$day] = array_map(fn (array $e) => $placeOf($e['row']['destination']), array_slice($sequence, $offset, $take));
            $offset += $take;
        }

        $here = ['lat' => $origin['lat'], 'lng' => $origin['lng'], 'region' => null];
        $anchors = [];
        foreach ($byDay as $day => $places) {
            $first = $places[0] ?? $here;
            $last = $places !== [] ? $places[array_key_last($places)] : $here;
            $anchors[$day] = ['first' => $first, 'last' => $last];
            $here = $last;
        }

        $wanted = $preference->accommodation_pref;
        $wanted = ($wanted && $wanted !== 'Any') ? $wanted : null;
        $setting = $this->wantedResortSetting($preference);

        $query = Accommodation::where('is_accredited', true)->whereNull('archived_at')->availableForTrip()
            ->whereNotNull('latitude')->whereNotNull('longitude');
        if ($setting !== null) {
            $query->whereIn('type', self::RESORT_TYPES)->withResortSetting($setting);
        } elseif ($wanted === 'Resort') {
            $query->whereIn('type', self::RESORT_TYPES);
        } elseif ($wanted !== null) {
            $query->where('type', $wanted);
        }
        // The same for every trial of one plan, so it is read once (see $stayCache).
        $candidates = $this->stayCache['candidates'] ??= $query->with('accreditationRecords')->orderBy('id')->get()
            ->filter(function ($stay) use ($preference) {
                $tiers = ['Free', 'Budget-Friendly', 'Mid-range', 'Premium'];
                $price = array_search($stay->price_tier, $tiers, true);
                $ceiling = array_search($preference->budget, $tiers, true);

                return $price === false || $ceiling === false || $price <= $ceiling;
            })->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        // Apriori: stays that past travellers paired with one of this trip's stops, strongest rule per stay.
        $suggested = [];
        foreach ($sequence as $stop) {
            $destination = $stop['row']['destination'];
            foreach ($this->stayCache['apriori'][$destination->id] ??= $this->apriori->suggestionsFor('destination', $destination->id, 3, 'accommodation') as $rule) {
                if ($this->fitsStayPreference($rule['listing'], $wanted, $setting)
                    && (! isset($suggested[$rule['listing_id']]) || $rule['confidence'] > $suggested[$rule['listing_id']]['confidence'])) {
                    $suggested[$rule['listing_id']] = $rule + ['basis' => $destination->name];
                }
            }
        }

        $estimator = app(TravelTimeEstimator::class);
        // A stop with no coordinates cannot be measured, so its journey adds nothing to the choice of stay
        // (the schedule still says "Travel time varies" for it) instead of failing the whole plan.
        $minutes = fn (array $a, array $b): int => in_array(null, [$a['lat'], $a['lng'], $b['lat'], $b['lng']], true)
            ? 0
            : $estimator->estimate(
                $this->haversineKm($a['lat'], $a['lng'], $b['lat'], $b['lng']), $a['region'], $b['region'], $preference->transport_pref, $a, $b,
            )['max_minutes'];

        $tripStart = $preference->travelWindow()[0];
        $nights = [];
        for ($night = 1; $night < $lastDay; $night++) {
            $date = $tripStart->copy()->addDays($night - 1);
            $breakfastDate = $date->copy()->addDay();
            $breakfastCosts = $this->stayCache['breakfast'][$breakfastDate->toDateString()] ??=
                $this->schedule->breakfastTransferCosts($candidates, $preference, $breakfastDate);
            $a = $anchors[$night]['last'];
            $b = $anchors[$night + 1]['first'];
            $nights[$night] = [];
            foreach ($candidates as $stay) {
                if (! $this->accreditedOn($stay, $date) || ! collect($byDay[$night])->every(fn ($stop) =>
                    $stop['lat'] !== null && $stop['lng'] !== null
                    && $this->haversineKm((float) $stay->latitude, (float) $stay->longitude, $stop['lat'], $stop['lng']) <= ItineraryAudit::MAX_STAY_KM)) {
                    continue;
                }
                $rule = $suggested[$stay->id] ?? null;
                $nights[$night][$stay->id] = ['listing' => $stay,
                    'cost' => $minutes($a, $placeOf($stay)) + $minutes($placeOf($stay), $b) + ($breakfastCosts[$stay->id] ?? 0),
                    'rule' => $rule ? array_intersect_key($rule, array_flip(['basis', 'support', 'confidence', 'co_count'])) : null];
            }
        }
        $planner = app(StayContinuityPlanner::class);
        $mode = $this->stayMode($preference);
        $stays = $planner->plan($nights, ($flexible ?? ($mode === 'far')) ? (int) config('itinerary.stays.transfer_penalty_minutes.far', 30) : 1000000);
        // Commutable stays put unless the best continuous plan exceeds the
        // daily driving allowance and changing hotels actually reduces it.
        if ($mode === 'moderate' && $flexible === null) {
            $flexible = $planner->plan($nights, self::STAY_KEEP_TOLERANCE_MINUTES);
            $cost = fn ($plan) => array_sum(array_map(fn ($night) => isset($plan[$night]['listing'])
                ? $nights[$night][$plan[$night]['listing']->id]['cost'] : 0, array_keys($nights)));
            $long = fn ($plan) => collect(array_keys($nights))->contains(fn ($night) => isset($plan[$night]['listing'])
                && $nights[$night][$plan[$night]['listing']->id]['cost'] > ItineraryScheduleBuilder::DRIVE_BUDGET_MINUTES['moderate']);
            if ($long($stays) && $cost($flexible) + self::STAY_KEEP_TOLERANCE_MINUTES < $cost($stays)) {
                $stays = $flexible;
            }
        }
        return array_filter($stays) === [] ? null : $stays;
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
        } elseif ($wanted === 'Resort') {
            $query->whereIn('type', self::RESORT_TYPES);
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
            if ($setting === 'Beach') {
                return $stay->type === 'Beach Resort'
                    || (in_array($stay->type, self::RESORT_TYPES, true)
                        && (in_array('Beach', $stay->resortSettingList(), true) || stripos((string) $stay->name, 'beach') !== false));
            }

            return in_array($stay->type, self::RESORT_TYPES, true) && in_array($setting, $stay->resortSettingList(), true);
        }

        if ($wanted === 'Resort') {
            return in_array($stay->type, self::RESORT_TYPES, true);
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
