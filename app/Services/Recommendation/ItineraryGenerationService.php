<?php

namespace App\Services\Recommendation;

use App\Exceptions\NoDestinationsAvailable;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Support\AccreditationGrace;
use App\Support\OpeningHours;
use App\Support\TravelWindow;
use App\Support\VisitDuration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

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
            throw new NoDestinationsAvailable;
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
            throw ValidationException::withMessages([
                'budget' => 'No eligible destination fits the requested spending tier. Try another spending style or different dates.',
            ]);
        }

        // Keep incomplete records in the ranking, but never route a timed visit
        // to an unlocated attraction or compress a multi-day trek into one stop.
        $distinctRanked = $distinctRanked->filter(fn ($row) => $row['destination']->latitude !== null
            && $row['destination']->longitude !== null
            && (VisitDuration::minutes($row['destination']->visit_duration) ?? 0) < 1440)->values();
        // Deduplicate only usable branches. An unmapped branch must not hide
        // another branch of the same business whose position is verified.
        $distinctRanked = $this->distinctByDestinationName($distinctRanked);
        if ($distinctRanked->isEmpty()) {
            throw ValidationException::withMessages([
                'distance_pref' => 'No mapped day-visit destination fits this trip. Place coordinates or day-trip options need updating.',
            ]);
        }

        // Arrival day may hold fewer stops than a full day, so capacity has to
        // be summed per day rather than assumed uniform.
        $dayCapacities = $this->dayCapacities($preference, $totalDays);
        $maxStops = min($distinctRanked->count(), array_sum(array_map('count', $dayCapacities)));
        if ($preference->distance_pref === 'far') {
            $distinctRanked = $this->withinOneNeighbourhood($distinctRanked, $maxStops, $preference, $originLat, $originLng);
        }
        // The best places for the trip, except that an interest the traveller chose gets a stop when anything can offer one.
        $topRanked = (new InterestCoverage)->select($distinctRanked, $maxStops, $preference->activities->pluck('activity')->all());

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
                            Log::debug('Hotel rebuild rejected', [
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
                $routeCost = $preference->distance_pref === 'far' ? $this->feasibilityCost($itinerary) : 0;
                $itinerary->items()->delete();
                $itinerary->unsetRelation('items');

                $build($themedSequence);
                $built = $themedSequence;

                if ($placed() < $routePlaced || ($preference->distance_pref === 'far' && $placed() === $routePlaced && $this->feasibilityCost($itinerary) > $routeCost)) {
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

            // Remote return/arrival trials keep their proposed stays; constraint repair below remains authoritative.
            $remoteBuild = function (array $stops) use (&$lastBuiltStops, &$lastStays, $itinerary, $preference, $dayCapacities, $origin): void {
                $lastBuiltStops = $stops;
                $this->schedule->build($itinerary, $stops, $preference, $dayCapacities, $origin, $lastStays);
                $itinerary->unsetRelation('items');
            };
            if ($totalDays >= 2 && $this->stayMode($preference) === 'far') {
                $this->settleEmptyLastDay($itinerary, $remoteBuild, $lastStays, $built, $reserve, $preference, $dayCapacities, $origin);
            }
            $built = $this->fillEmptyDays($itinerary, $remoteBuild, $built, $reserve, $dayCapacities, $origin, $preference, $lastStays);

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
                if (app(ItineraryConstraintRepair::class)->remaining($itinerary, $preference) > 0
                    || $itinerary->items()->where('kind', 'activity')->whereNotNull('destination_id')->doesntExist()) {
                    throw ValidationException::withMessages([
                        'distance_pref' => 'This trip cannot fit the daily distance, travel-time share and hotel-distance limits with the available places. Try a closer starting point, different transport or different interests.',
                    ]);
                }

                $checks = collect(app(ItineraryAudit::class)->audit($itinerary, $preference))->keyBy('key');
                $errors = [];
                if ($checks['interests']['status'] === 'fail') {
                    $errors['activities'] = 'The available schedule cannot include every selected interest: '.$checks['interests']['detail'].'. Try more days, an earlier arrival or different interests.';
                }
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
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
        $eligible = $this->eligibleStays($preference, $days - 1);
        $placedRows = $itinerary->items()->with('destination')->where('kind', 'activity')->whereNotNull('destination_id')->get();
        foreach ($eligible as $night => $candidates) {
            $eligible[$night] = $candidates->filter(fn ($stay) => $placedRows->where('day_number', $night)->every(fn ($row) => $row->destination->latitude !== null && $row->destination->longitude !== null
                && $this->haversineKm((float) $stay->latitude, (float) $stay->longitude, (float) $row->destination->latitude, (float) $row->destination->longitude) <= ItineraryAudit::MAX_STAY_KM))->values();
        }
        $first = $evaluator->metrics($itinerary, $preference, $origin);
        $guard = ['decision' => 'not_needed', 'failed' => null, 'extra_builds' => 0, 'first' => $evaluator->summary($first), 'final' => null];

        $snapshot = fn (): array => $itinerary->items()->orderBy('id')->get()->map(fn ($r) => $r->getAttributes())->all();
        $restore = function (array $rows) use ($itinerary): void {
            $itinerary->items()->delete();
            $itinerary->unsetRelation('items');
            foreach (array_chunk($rows, 50) as $chunk) {
                ItineraryItem::insert($chunk);
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
            $newFailures = collect(app(ItineraryAudit::class)->audit($itinerary, $preference))->where('status', 'fail')->pluck('key')->diff($firstFailures);
            if (array_diff($first['placed'], $metrics['placed']) === [] && $newFailures->isNotEmpty()) {
                $metrics['violations']++;
            }

            return ['stays' => $plan, 'rows' => $snapshot(), 'm' => $metrics];
        };

        $current = ['stays' => $stays, 'rows' => null, 'm' => $first];
        $chosen = $current;

        if ($stays !== null && $cap > 0 && array_filter($eligible, fn ($s) => $s->isNotEmpty()) !== []) {
            $current['rows'] = $snapshot();
            $chosen = $current;
            $guardLastNight = $mode === 'far' && (
                ($first['drive'][$days] ?? 0) > ItineraryScheduleBuilder::MAX_DAILY_DRIVE_MINUTES
                || $itinerary->items()->where('day_number', $days)->where('kind', 'activity')->whereNotNull('destination_id')->doesntExist()
            );
            $anchors = $this->placedAnchors($itinerary, $days, $origin, $guardLastNight);
            $suggested = $this->suggestedStays($built, $preference);

            // Stage C2: a Remote trip whose arrival day holds no sightseeing and whose first night is a late check-in.
            $firstDayEmpty = $itinerary->items()->where('day_number', 1)->where('kind', 'activity')->whereNotNull('destination_id')->doesntExist();
            $nightOneLate = $mode === 'far' && $firstDayEmpty
                && $itinerary->items()->where('day_number', 1)->where('kind', 'overnight')->where('title', 'like', '%Late check-in%')->exists();
            $lateness = $nightOneLate ? $this->firstNightLateness($preference, $origin) : null;

            if ($mode !== 'moderate') {
                $plan = $this->planStays($anchors, $eligible, $preference, $suggested, $mode === 'far', $guardLastNight ? $origin : null, $lateness);
                if ($this->stayIds($plan) !== $this->stayIds($current['stays'])) {
                    $candidate = $try($plan);
                    [$ok, $failed] = $evaluator->accepts($first, $candidate['m'], $mode);
                    $guard['decision'] = $ok ? 'accepted' : (str_starts_with((string) $failed, 'H5') ? 'no_gain' : 'rejected');
                    // A late check-in the new stay removes is worth more than the cost comparison alone shows, as long
                    // as nothing else is worse (see StayPlanEvaluator::acceptsLateRepair).
                    if (! $ok && $lateness !== null && str_starts_with((string) $failed, 'H5')) {
                        [$repaired, $why] = $evaluator->acceptsLateRepair($first, $candidate['m']);
                        if ($repaired) {
                            $ok = true;
                            $failed = null;
                            $guard['decision'] = 'accepted_late_repair';
                        }
                    }
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
        if (! Schema::hasColumn('itineraries', 'stay_plan')) {
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
        $order = ['closed_that_day', 'outside_opening_hours', 'would_end_after_cutoff', 'would_return_after_2000', 'drive_budget_reached', 'day_full', 'no_sightseeing_time_that_day'];
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

    private function withinOneNeighbourhood(\Illuminate\Support\Collection $ranked, int $stops, TouristPreference $preference, float $originLat, float $originLng): \Illuminate\Support\Collection
    {
        $rows = $ranked->values()->all();
        $minutes = fn (Destination $a, Destination $b): int => $this->stayMinutes($this->placeOf($a), $this->placeOf($b), $preference);
        $mapped = fn (array $row): bool => $row['destination']->latitude !== null && $row['destination']->longitude !== null;

        $start = ['lat' => $originLat, 'lng' => $originLng, 'region' => null];
        $interests = $preference->activities->pluck('activity')->filter()->unique()->values()->all();
        $covers = [];

        $seed = null;
        $bestScore = -INF;
        foreach ($rows as $candidate) {
            if (! $mapped($candidate)) {
                continue;
            }
            $members = [];
            foreach ($rows as $row) {
                if ($mapped($row) && $minutes($candidate['destination'], $row['destination']) <= self::REMOTE_NEIGHBOURHOOD_MINUTES) {
                    $members[] = $row;
                }
            }
            // Stable for equal scores, so the ranking's order decides which of them the trip takes.
            usort($members, fn (array $x, array $y) => $y['drs'] <=> $x['drs']);
            $best = array_slice($members, 0, $stops);
            $fit = $this->interestFit($best, $stops, $interests, $covers);
            $hours = $this->stayMinutes($start, $this->placeOf($candidate['destination']), $preference) / 60;
            $score = array_sum(array_column($best, 'drs')) - $this->originPenaltyPerHour($stops, $fit) * $hours;
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
        $this->regionNames ??= Region::pluck('name', 'id');

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
    private function fillEmptyDays(Itinerary $itinerary, callable $build, array $built, \Illuminate\Support\Collection $reserve, array $dayCapacities, array $origin, ?TouristPreference $preference = null, ?array &$stays = null): array
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
        $lastDay = array_key_last($dayCapacities);
        $originPlace = ['lat' => $origin['lat'], 'lng' => $origin['lng'], 'region' => null];
        $evaluator = app(StayPlanEvaluator::class);

        foreach ($emptyDays() as $day) {
            if ($tried++ >= self::FILL_DAYS_TRIED) {
                break;
            }

            $byDay = $stopsByDay();
            $before = $byDay->filter(fn ($rows, $d) => $d < $day)->flatten(1);
            $lastDest = $before->isNotEmpty() ? Destination::find($before->last()->destination_id) : null;
            $startsAt = $lastDest !== null && $lastDest->latitude !== null
                ? [(float) $lastDest->latitude, (float) $lastDest->longitude]
                : [(float) $origin['lat'], (float) $origin['lng']];
            $startsAtPlace = $lastDest !== null && $lastDest->latitude !== null
                ? $this->placeOf($lastDest)
                : $originPlace;
            $inTrip = array_map(fn (array $e) => $e['row']['destination']->id, $built);

            $candidates = $reserve
                ->reject(fn (array $row) => in_array($row['destination']->id, $inTrip, true) || $row['destination']->latitude === null)
                ->when($day === $lastDay && $preference !== null && $this->stayMode($preference) === 'far', fn ($c) => $c->filter(
                    fn (array $row) => $this->stayMinutes($this->placeOf($row['destination']), $originPlace, $preference) < ItineraryScheduleBuilder::MAX_DAILY_DRIVE_MINUTES
                ))
                ->sortBy(function (array $row) use ($day, $lastDay, $preference, $startsAt, $startsAtPlace, $originPlace) {
                    $dist = $this->haversineKm($startsAt[0], $startsAt[1], (float) $row['destination']->latitude, (float) $row['destination']->longitude);
                    if ($day === $lastDay && $preference !== null && $this->stayMode($preference) === 'far') {
                        $place = $this->placeOf($row['destination']);
                        $mins = $this->stayMinutes($startsAtPlace, $place, $preference) + $this->stayMinutes($place, $originPlace, $preference);

                        return $mins * 1000 + $dist;
                    }

                    return $dist;
                })
                ->take(self::FILL_PLACES_TRIED);

            $stopsBefore = $placed();
            $emptyBefore = count($emptyDays());
            $savedStays = $stays;
            $beforeMetrics = ($day === $lastDay && $preference !== null && $stays !== null)
                ? $evaluator->metrics($itinerary, $preference, $origin)
                : null;

            foreach ($candidates as $row) {
                $trial = $built;
                array_splice($trial, $before->unique('destination_id')->count(), 0, [['row' => $row, 'distance_km' => null]]);

                $stays = $savedStays;
                $rebuild($trial);

                if ($placed() >= $stopsBefore && count($emptyDays()) < $emptyBefore) {
                    $built = $trial;

                    continue 2;
                }

                if ($day === $lastDay && $preference !== null && $savedStays !== null && $this->stayMode($preference) === 'far' && count($dayCapacities) >= 2) {
                    $eligible = $this->eligibleStays($preference, count($dayCapacities) - 1);
                    if (array_filter($eligible, fn ($s) => $s->isNotEmpty()) !== []) {
                        $destModels = Destination::whereIn('id', $before->pluck('destination_id')->unique())->get()->keyBy('id');
                        $trialByDay = [];
                        foreach (range(1, $lastDay - 1) as $dNum) {
                            $trialByDay[$dNum] = ($byDay->get($dNum) ?? collect())->map(fn ($r) => $this->placeOf($destModels[$r->destination_id]))->values()->all();
                        }
                        $trialByDay[$lastDay] = [$this->placeOf($row['destination'])];
                        $anchors = $this->anchorsFromDays($trialByDay, $origin, true);
                        $suggested = $this->suggestedStays($trial, $preference);
                        $fullPlan = $this->planStays($anchors, $eligible, $preference, $suggested, true, $origin);
                        $lastNightOnly = $savedStays;
                        $lastNightOnly[$lastDay - 1] = $fullPlan[$lastDay - 1] ?? $savedStays[$lastDay - 1];

                        foreach ([$lastNightOnly, $fullPlan] as $candStays) {
                            if ($this->stayIds($candStays) === $this->stayIds($savedStays)) {
                                continue;
                            }
                            $stays = $candStays;
                            $rebuild($trial);
                            $candMetrics = $evaluator->metrics($itinerary, $preference, $origin);
                            if ($placed() >= $stopsBefore
                                && count($emptyDays()) < $emptyBefore
                                && $candMetrics['violations'] === 0
                                && $candMetrics['lateCount'] <= $beforeMetrics['lateCount']) {
                                $built = $trial;

                                continue 3;
                            }
                        }
                    }
                }
            }

            $stays = $savedStays;
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
     * @param  Collection<int, array{destination: Destination, pm: float, rs: float, ps: float, ds: float, as: float, drs: float}>  $ranked
     * @return Collection<int, array{destination: Destination, pm: float, rs: float, ps: float, ds: float, as: float, drs: float}>
     */
    private function distinctByDestinationName(\Illuminate\Support\Collection $ranked): \Illuminate\Support\Collection
    {
        return $ranked->groupBy(fn (array $row) => trim((string) preg_replace('/\s+(inc\.?|corp\.?|corporation)$/i', '', mb_strtolower(trim($row['destination']->name)))))
            ->map(fn ($branches) => $branches->first(fn ($row) => OpeningHours::windows($row['destination']->hours) !== null) ?? $branches->first())
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

        // Stage C1: a Remote trip with a night to plan, landing so late that not even the shortest proper visit fits
        // before the sightseeing cutoff (the day starts at arrival + 30 minutes, at the earliest 08:00), has no
        // sightseeing on its arrival day. Say so up front, so Pass-0 stays are planned around an arrival day that
        // is only a journey to the stay, and a stop is not counted against a slot that can never be used.
        if ($preference->distance_pref === 'far' && $totalDays >= 2 && ! $this->schedule->arrivalDayHasRoomForAVisit($preference)) {
            $capacities[1] = [];
        }

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
     * @return array<int, array{listing: Accommodation, rule: array|null}|null>|null night => stay, or null when there is nothing to plan
     */
    private function pickStays(array $sequence, array $dayCapacities, TouristPreference $preference, array $origin, ?bool $flexible = null): ?array
    {
        $lastDay = array_key_last($dayCapacities);

        if (count($dayCapacities) < 2 || $sequence === []) {
            return null;
        }

        $regions = $this->stayCache['regions'] ??= Region::pluck('name', 'id');
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
        $lateness = ($this->stayMode($preference) === 'far' && ($dayCapacities[1] ?? []) === []) ? $this->firstNightLateness($preference, $origin) : null;
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
                if (! $this->accreditedOn($stay, $date) || ! collect($byDay[$night])->every(fn ($stop) => $stop['lat'] !== null && $stop['lng'] !== null
                    && $this->haversineKm((float) $stay->latitude, (float) $stay->longitude, $stop['lat'], $stop['lng']) <= ItineraryAudit::MAX_STAY_KM)) {
                    continue;
                }
                $rule = $suggested[$stay->id] ?? null;
                $nights[$night][$stay->id] = ['listing' => $stay,
                    'cost' => $minutes($a, $placeOf($stay)) + $minutes($placeOf($stay), $b) + ($breakfastCosts[$stay->id] ?? 0) + ($night === 1 && $lateness !== null && ($late = $lateness($stay)) > 0 ? $late + (int) config('itinerary.stays.transfer_penalty_minutes.far', 30) : 0),
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
    private function accreditedOn(Accommodation $stay, Carbon $night): bool
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

    private function feasibilityCost(Itinerary $itinerary): int
    {
        $items = $itinerary->items()->get();
        $late = $items->where('kind', 'overnight')->filter(fn ($i) => str_contains(strtolower((string) $i->title), 'late check-in'))->count();
        $byDay = $items->groupBy('day_number');
        $empty = 0;
        $over = 0;
        for ($d = 1; $d <= (int) $itinerary->total_days; $d++) {
            $rows = $byDay->get($d, collect());
            if ($rows->where('kind', 'activity')->whereNotNull('destination_id')->isEmpty()) {
                $empty++;
            }
            if ((int) $rows->where('kind', 'travel')->sum('travel_max_minutes') > ItineraryScheduleBuilder::MAX_DAILY_DRIVE_MINUTES) {
                $over++;
            }
        }

        return $late + $empty + $over;
    }

    private function interestFit(array $best, int $stops, array $interests, array &$covers): float
    {
        if ($interests === []) {
            return self::REMOTE_ORIGIN_NEUTRAL_FIT;
        }

        $due = max(1, (int) ceil($stops / 2));
        $fits = array_map(function (string $interest) use ($best, $due, &$covers): float {
            $covering = 0;
            foreach ($best as $row) {
                $key = $row['destination']->id.'|'.$interest;
                $covers[$key] ??= InterestCoverage::covers($row['destination'], $interest);
                $covering += $covers[$key] ? 1 : 0;
            }

            return min(1.0, $covering / $due);
        }, $interests);

        return array_sum($fits) / count($fits);
    }

    private function originPenaltyPerHour(int $stops, float $fit): float
    {
        $factor = self::REMOTE_ORIGIN_STOP_FACTOR[min(max(1, $stops), count(self::REMOTE_ORIGIN_STOP_FACTOR)) - 1];

        return self::REMOTE_ORIGIN_PENALTY_PER_HOUR * $factor * (1 - self::REMOTE_ORIGIN_INTEREST_RELIEF * $fit);
    }

    private function settleEmptyLastDay(Itinerary $itinerary, callable $build, ?array &$stays, array $built, \Illuminate\Support\Collection $reserve, TouristPreference $preference, array $dayCapacities, array $origin): void
    {
        $days = count($dayCapacities);
        if ($days < 2 || $stays === null) {
            return;
        }

        $rows = $itinerary->items()->where('kind', 'activity')->whereNotNull('destination_id')->orderBy('day_number')->orderBy('sort_order')->get();
        if ($rows->where('day_number', $days)->isNotEmpty()) {
            return;
        }

        $eligible = $this->eligibleStays($preference, $days - 1);
        if (array_filter($eligible, fn ($s) => $s->isNotEmpty()) === []) {
            return;
        }

        $evaluator = app(StayPlanEvaluator::class);
        $snapshot = fn (): array => $itinerary->items()->orderBy('id')->get()->map(fn ($r) => $r->getAttributes())->all();
        $restore = function (array $saved) use ($itinerary): void {
            $itinerary->items()->delete();
            $itinerary->unsetRelation('items');
            foreach (array_chunk($saved, 50) as $chunk) {
                ItineraryItem::insert($chunk);
            }
        };

        $initialRows = $snapshot();
        $initialStays = $stays;
        $initialMetrics = $evaluator->metrics($itinerary, $preference, $origin);

        $destinations = Destination::whereIn('id', $rows->pluck('destination_id')->unique())->get()->keyBy('id');
        $byDay = [];
        foreach (range(1, $days - 1) as $day) {
            $byDay[$day] = $rows->where('day_number', $day)->map(fn ($r) => $this->placeOf($destinations[$r->destination_id]))->values()->all();
        }

        $originPlace = ['lat' => $origin['lat'], 'lng' => $origin['lng'], 'region' => null];
        $placedIds = $initialMetrics['placed'];
        $reachableUnplaced = [];
        foreach ($built as $entry) {
            $dest = $entry['row']['destination'];
            if (! in_array((int) $dest->id, $placedIds, true) && $dest->latitude !== null) {
                $toOrig = $this->stayMinutes($this->placeOf($dest), $originPlace, $preference);
                if ($toOrig < ItineraryScheduleBuilder::MAX_DAILY_DRIVE_MINUTES) {
                    $reachableUnplaced[] = ['place' => $this->placeOf($dest), 'to_orig' => $toOrig];
                }
            }
        }
        usort($reachableUnplaced, fn (array $a, array $b): int => $a['to_orig'] <=> $b['to_orig']);

        $trials = [];
        if ($reachableUnplaced !== []) {
            $trials[] = [$reachableUnplaced[0]['place']];
        }
        $inTrip = array_map(fn (array $e) => (int) $e['row']['destination']->id, $built);
        $reachableReserve = $reserve
            ->reject(fn (array $row) => in_array((int) $row['destination']->id, $inTrip, true) || $row['destination']->latitude === null)
            ->map(fn (array $row) => [
                'place' => $this->placeOf($row['destination']),
                'to_orig' => $this->stayMinutes($this->placeOf($row['destination']), $originPlace, $preference),
            ])
            ->filter(fn (array $item) => $item['to_orig'] < ItineraryScheduleBuilder::MAX_DAILY_DRIVE_MINUTES)
            ->sortBy('to_orig')
            ->values();
        if ($reachableReserve->isNotEmpty()) {
            $trials[] = [$reachableReserve->first()['place']];
        }
        $trials[] = [];

        $suggested = $this->suggestedStays($built, $preference);
        $triedStayIds = [$this->stayIds($initialStays)];
        $bestFallback = null;

        foreach ($trials as $lastDayPlaces) {
            $trialByDay = $byDay + [$days => $lastDayPlaces];
            $anchors = $this->anchorsFromDays($trialByDay, $origin, true);
            $fullPlan = $this->planStays($anchors, $eligible, $preference, $suggested, true, $origin);
            $lastNightOnly = $initialStays;
            $lastNightOnly[$days - 1] = $fullPlan[$days - 1] ?? $initialStays[$days - 1];

            foreach ([$fullPlan, $lastNightOnly] as $candidateStays) {
                $ids = $this->stayIds($candidateStays);
                if (in_array($ids, $triedStayIds, true)) {
                    continue;
                }
                $triedStayIds[] = $ids;

                $stays = $candidateStays;
                $itinerary->items()->delete();
                $itinerary->unsetRelation('items');
                $build($built);

                $candMetrics = $evaluator->metrics($itinerary, $preference, $origin);
                $noStopLost = count($candMetrics['placed']) >= count($initialMetrics['placed']);
                $noRuleBroken = $candMetrics['violations'] === 0 && $candMetrics['lateCount'] <= $initialMetrics['lateCount'];
                $filledLastDay = $candMetrics['empty'] < $initialMetrics['empty'] || count($candMetrics['placed']) > count($initialMetrics['placed']);

                if ($noStopLost && $noRuleBroken && $filledLastDay) {
                    return;
                }

                $reducedLate = $candMetrics['lateCount'] < $initialMetrics['lateCount'];
                $reducedExcess = $candMetrics['excess'] < $initialMetrics['excess'];
                $lastNightStay = $candidateStays[$days - 1]['listing'] ?? null;
                $prevLastNightStay = $initialStays[$days - 1]['listing'] ?? null;
                $improvedLastNight = $lastNightStay !== null
                    && $prevLastNightStay !== null
                    && $this->stayMinutes($this->placeOf($lastNightStay), $originPlace, $preference) < $this->stayMinutes($this->placeOf($prevLastNightStay), $originPlace, $preference)
                    && $candMetrics['excess'] <= $initialMetrics['excess'];

                if ($noStopLost && $noRuleBroken && ($reducedLate || $reducedExcess || $improvedLastNight) && $bestFallback === null) {
                    $bestFallback = ['stays' => $candidateStays, 'rows' => $snapshot()];
                }
            }
        }

        if ($bestFallback !== null) {
            $stays = $bestFallback['stays'];
            $restore($bestFallback['rows']);

            return;
        }

        $stays = $initialStays;
        $restore($initialRows);
    }

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

    private function placedAnchors(Itinerary $itinerary, int $days, array $origin, bool $emptyLastDayToOrigin = false): array
    {
        $rows = $itinerary->items()->where('kind', 'activity')->whereNotNull('destination_id')->orderBy('day_number')->orderBy('sort_order')->get();
        $destinations = Destination::whereIn('id', $rows->pluck('destination_id')->unique())->get()->keyBy('id');
        $byDay = [];
        foreach (range(1, $days) as $day) {
            $byDay[$day] = $rows->where('day_number', $day)->map(fn ($r) => $this->placeOf($destinations[$r->destination_id]))->values()->all();
        }

        return $this->anchorsFromDays($byDay, $origin, $emptyLastDayToOrigin);
    }

    private function anchorsFromDays(array $byDay, array $origin, bool $emptyLastDayToOrigin = false): array
    {
        $originPlace = ['lat' => $origin['lat'], 'lng' => $origin['lng'], 'region' => null];
        $here = $originPlace;
        $lastDay = array_key_last($byDay);
        $anchors = [];
        foreach ($byDay as $day => $places) {
            $fallback = ($emptyLastDayToOrigin && $day === $lastDay && count($byDay) >= 2) ? $originPlace : $here;
            $first = $places[0] ?? $fallback;
            $last = $places !== [] ? $places[array_key_last($places)] : $fallback;
            $anchors[$day] = ['first' => $first, 'last' => $last];
            $here = $last;
        }

        return $anchors;
    }

    private function statedStay(TouristPreference $preference): array
    {
        $wanted = $preference->accommodation_pref;

        return [($wanted && $wanted !== 'Any') ? $wanted : null, $this->wantedResortSetting($preference)];
    }

    private function eligibleStays(TouristPreference $preference, int $nights): array
    {
        if ($nights < 1) {
            return [];
        }
        [$wanted, $setting] = $this->statedStay($preference);
        $query = Accommodation::where('is_accredited', true)->whereNull('archived_at')->availableForTrip()->whereNotNull('latitude')->whereNotNull('longitude');
        if ($setting !== null) {
            $query->whereIn('type', self::RESORT_TYPES)->withResortSetting($setting);
        } elseif ($wanted === 'Resort') {
            $query->whereIn('type', self::RESORT_TYPES);
        } elseif ($wanted !== null) {
            $query->where('type', $wanted);
        }
        $candidates = $query->with('accreditationRecords')->orderBy('id')->get()->filter(function ($stay) use ($preference) {
            $tiers = ['Free', 'Budget-Friendly', 'Mid-range', 'Premium'];
            $price = array_search($stay->price_tier, $tiers, true);
            $ceiling = array_search($preference->budget, $tiers, true);

            return $price === false || $ceiling === false || $price <= $ceiling;
        })->values();
        $start = $preference->travelWindow()[0];
        $result = [];
        foreach (range(1, $nights) as $night) {
            $result[$night] = $candidates->filter(fn ($stay) => $this->accreditedOn($stay, $start->copy()->addDays($night - 1)))->values();
        }

        return $result;
    }

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

    private function planStays(array $anchors, array $eligible, TouristPreference $preference, array $suggested, bool $flexible, ?array $origin = null, ?callable $firstNightLate = null): array
    {
        $penalty = $flexible ? (int) config('itinerary.stays.transfer_penalty_minutes.far', 30) : self::STRICT_STAY_PENALTY;
        $band = (int) config('itinerary.stays.apriori_band_minutes', 15);
        $lastNight = array_key_last($eligible);
        $originPlace = $origin !== null ? ['lat' => $origin['lat'], 'lng' => $origin['lng'], 'region' => null] : null;

        $costs = [];
        $breakfast = [];
        $tripStart = $preference->travelWindow()[0];
        $cost = function (int $night, Accommodation $stay) use (&$costs, $anchors, $preference, $flexible, $lastNight, $originPlace, $firstNightLate, &$breakfast, $eligible, $tripStart): int {
            if (isset($costs[$night][$stay->id])) {
                return $costs[$night][$stay->id];
            }
            $place = $this->placeOf($stay);
            $date = $tripStart->copy()->addDays($night);
            $breakfast[$night] ??= $this->schedule->breakfastTransferCosts($eligible[$night], $preference, $date);
            $c = $this->stayMinutes($anchors[$night]['last'], $place, $preference)
                + $this->stayMinutes($place, $anchors[$night + 1]['first'], $preference) + ($breakfast[$night][$stay->id] ?? 0);
            if ($flexible && $originPlace !== null && $night === $lastNight && $this->stayMode($preference) === 'far') {
                $finalDayDrive = $this->stayMinutes($place, $anchors[$night + 1]['first'], $preference)
                    + $this->stayMinutes($anchors[$night + 1]['first'], $originPlace, $preference);
                if ($finalDayDrive > ItineraryScheduleBuilder::MAX_DAILY_DRIVE_MINUTES) {
                    $c += self::STRICT_STAY_PENALTY + ($finalDayDrive - ItineraryScheduleBuilder::MAX_DAILY_DRIVE_MINUTES);
                }
            }

            // Stage C2: on an arrival day with no sightseeing, a stay the traveller would only reach after the latest dinner
            // time costs the minutes it is late plus one change of stay -- a late check-in is worth a transfer, but
            // a transfer is not worth a few minutes.
            if ($firstNightLate !== null && $night === 1) {
                $late = $firstNightLate($stay);
                if ($late > 0) {
                    $c += $late + (int) config('itinerary.stays.transfer_penalty_minutes.far', 30);
                }
            }

            return $costs[$night][$stay->id] = $c;
        };
        // A plan is compared by total minutes, then by fewer changes of stay, then by changing later rather than earlier
        // (the traveller keeps the stay they have as long as it costs nothing extra), then by rating and id.
        $order = fn (array $x, array $y): int => [...$x['key'], -(float) $x['stay']->rating, $x['stay']->id] <=> [...$y['key'], -(float) $y['stay']->rating, $y['stay']->id];
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

    private function firstNightLateness(TouristPreference $preference, array $origin): callable
    {
        return function (Accommodation $stay) use ($preference, $origin): int {
            $checkIn = $this->schedule->projectArrivalDayCheckIn($preference, $origin, $stay);
            $limit = Carbon::parse(ItineraryScheduleBuilder::latestDinnerStart());

            return max(0, (int) round(($checkIn->getTimestamp() - $limit->getTimestamp()) / 60));
        };
    }

    private function mainCause(array $attempts): string
    {
        $order = ['closed_that_day', 'outside_opening_hours', 'would_end_after_cutoff', 'would_return_after_2000', 'drive_budget_reached', 'day_full', 'no_sightseeing_time_that_day'];
        $causes = array_column($attempts, 'cause');

        foreach ($order as $cause) {
            if (in_array($cause, $causes, true)) {
                return $cause;
            }
        }

        return 'not_recorded';
    }

    private const REMOTE_ORIGIN_PENALTY_PER_HOUR = 2.0;

    private const REMOTE_ORIGIN_INTEREST_RELIEF = 0.85;

    private const REMOTE_ORIGIN_NEUTRAL_FIT = 0.5;

    private const REMOTE_ORIGIN_STOP_FACTOR = [2.0, 2.0, 1.5, 1.0, 0.9, 0.8, 0.7, 0.6];

    private const STRICT_STAY_PENALTY = 1000000;
}
