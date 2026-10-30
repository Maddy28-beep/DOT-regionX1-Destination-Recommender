<?php

namespace App\Services\Recommendation;

use App\Models\Itinerary;
use App\Models\TouristPreference;
use Illuminate\Support\Collection;

/**
 * Measures a built itinerary, and says whether one build is good enough to replace another.
 *
 * The stay planner builds an itinerary, learns which stops actually made it into the days, picks the stays again for
 * those stops and builds once more. A second build is only worth keeping if it is genuinely better, so both builds are
 * measured here, from their saved rows, in the same minutes the schedule itself uses.
 *
 * Two kinds of test, kept apart on purpose:
 *   - HARD constraints. A rebuild that breaks one is rejected whatever else it gains: no rule broken, no destination
 *     lost, no day left empty that was not, and the transfer rule of the traveller's distance preference.
 *   - One OPTIMISATION criterion. Everything that can trade off against something else is counted in minutes and
 *     added up: the journey to and from the stays, the cost of changing stay, the driving over the day's limit and
 *     the minutes of arriving late. The rebuild must beat the first build by the tolerance, so a small cost in one
 *     place does not sink a clearly better plan, and a tie keeps the plan that already exists.
 */
class StayPlanEvaluator
{
    /** The latest a day's last sightseeing may end (ItineraryScheduleBuilder::LAST_ACTIVITY_END). */
    private const LAST_ACTIVITY_END_MINUTES = 18 * 60;

    /** Arriving at the stay after this, dinner is not planned and the check-in is "late" (ItineraryScheduleBuilder). */
    private const LATE_AFTER_MINUTES = 19 * 60 + 30;

    public function __construct(private readonly TravelTimeEstimator $estimator) {}

    /** The minutes below which a difference is rounding noise (the estimator works in quarter hours). */
    public function tolerance(): int
    {
        return (int) config('itinerary.stays.acceptance_tolerance_minutes', 15);
    }

    /** What one change of stay costs, in minutes (config; calibrated on the audit). */
    public function transferPenalty(): int
    {
        return (int) config('itinerary.stays.transfer_penalty_minutes.far', 30);
    }

    /**
     * @param  array{lat: float, lng: float}  $origin  where the trip starts and ends (used for a day with no stop)
     * @return array<string, mixed>
     */
    public function metrics(Itinerary $itinerary, TouristPreference $preference, array $origin): array
    {
        $items = $itinerary->items()->with(['destination.region', 'accommodation.region'])->orderBy('day_number')->orderBy('sort_order')->get();
        $days = max(1, (int) $itinerary->total_days);
        $byDay = $items->groupBy('day_number');
        $budget = ItineraryScheduleBuilder::DRIVE_BUDGET_MINUTES[$preference->distance_pref] ?? ItineraryScheduleBuilder::DEFAULT_DRIVE_BUDGET_MINUTES;

        $activities = $items->where('kind', 'activity')->whereNotNull('destination_id');
        $placed = $activities->pluck('destination_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        $empty = 0;
        $drive = [];
        $excess = 0;
        foreach (range(1, $days) as $d) {
            $rows = $byDay->get($d, collect());
            if ($rows->where('kind', 'activity')->isEmpty()) {
                $empty++;
            }
            $drive[$d] = (int) $rows->where('kind', 'travel')->sum('travel_max_minutes');
            $excess += max(0, $drive[$d] - $budget);
        }

        // Late check-ins, and how late (minutes after the end of the dinner window).
        $lateCount = 0;
        $lateMinutes = 0;
        foreach ($items->where('kind', 'overnight') as $row) {
            if (str_contains((string) $row->title, 'Late check-in')) {
                $lateCount++;
                $lateMinutes += (($this->minutes($row->starts_at) - self::LATE_AFTER_MINUTES) + 1440) % 1440;
            }
        }

        // The stay of each night, in night order, and how many times it changes.
        $stayByNight = $items->where('kind', 'overnight')->whereNotNull('accommodation_id')->sortBy('day_number')
            ->mapWithKeys(fn ($r) => [(int) $r->day_number => (int) $r->accommodation_id])->all();
        $transfers = 0;
        $previous = null;
        foreach ($stayByNight as $stayId) {
            if ($previous !== null && $previous !== $stayId) {
                $transfers++;
            }
            $previous = $stayId;
        }

        return [
            'placed' => $placed,
            'empty' => $empty,
            'drive' => $drive,
            'excess' => $excess,
            'lateCount' => $lateCount,
            'lateMinutes' => $lateMinutes,
            'transfers' => $transfers,
            'stays' => $stayByNight,
            'journey' => $this->journey($items, $days, $preference, $origin),
            'violations' => $this->violations($items, $preference),
        ];
    }

    /** The one number a rebuild is judged on: journey + changes of stay + driving over the limit + minutes late. */
    public function cost(array $m): int
    {
        return $m['journey'] + $this->transferPenalty() * $m['transfers'] + $m['excess'] + $m['lateMinutes'];
    }

    /**
     * Should the rebuild $r replace the first build $f?
     *
     * @return array{0: bool, 1: string|null}  whether it should, and which condition failed when it should not
     */
    public function accepts(array $f, array $r, string $mode): array
    {
        if ($r['violations'] > $f['violations'] || $r['violations'] > 0) {
            return [false, 'H1 rule broken'];
        }
        if (array_diff($f['placed'], $r['placed']) !== []) {
            return [false, 'H2 destination lost'];
        }
        if ($r['empty'] > $f['empty']) {
            return [false, 'H3 empty day'];
        }
        if ($mode === 'near' && $r['transfers'] > $f['transfers']) {
            return [false, 'H4 more changes of stay under a strict preference'];
        }
        if ($this->cost($r) > $this->cost($f) - $this->tolerance()) {
            return [false, 'H5 not better by the tolerance'];
        }

        return [true, null];
    }

    /**
     * Is the one-stay build $one impractical next to the flexible build $flex? Only then does a Commutable trip
     * change stay. Everything here is a plain comparison of the two builds: nothing is judged against a fixed limit
     * of its own (the driving limit appears only through `excess`, which is the schedule's own budget).
     *
     * @return array{0: bool, 1: string|null}
     */
    public function oneStayIsImpractical(array $one, array $flex): array
    {
        if (array_diff($flex['placed'], $one['placed']) !== []) {
            return [true, 'the one-stay plan misses a destination the flexible plan places'];
        }
        if ($one['empty'] > $flex['empty']) {
            return [true, 'the one-stay plan leaves an extra empty day'];
        }
        if ($one['excess'] > $flex['excess'] + $this->tolerance()) {
            return [true, 'the one-stay plan drives more than the daily limit'];
        }
        if ($one['lateCount'] > $flex['lateCount']) {
            return [true, 'the one-stay plan adds a late check-in'];
        }
        if ($one['violations'] > $flex['violations']) {
            return [true, 'the one-stay plan breaks a rule'];
        }

        return [false, null];
    }

    /** A small summary safe to store on the itinerary. */
    public function summary(array $m): array
    {
        return [
            'placed' => count($m['placed']), 'empty_days' => $m['empty'], 'transfers' => $m['transfers'], 'journey_minutes' => $m['journey'],
            'excess_minutes' => $m['excess'], 'late_checkins' => $m['lateCount'], 'late_minutes' => $m['lateMinutes'], 'cost' => $this->cost($m),
        ];
    }

    /**
     * Minutes of travel between each night's last stop, the stay, and the next day's first stop.
     *
     * @param  Collection<int, \App\Models\ItineraryItem>  $items
     */
    private function journey(Collection $items, int $days, TouristPreference $preference, array $origin): int
    {
        $place = fn ($model): array => [
            'lat' => $model?->latitude === null ? null : (float) $model->latitude,
            'lng' => $model?->longitude === null ? null : (float) $model->longitude,
            'region' => $model?->region?->name,
        ];
        $here = ['lat' => (float) $origin['lat'], 'lng' => (float) $origin['lng'], 'region' => null];
        $firsts = [];
        $lasts = [];
        foreach (range(1, $days) as $d) {
            $stops = $items->where('day_number', $d)->where('kind', 'activity')->whereNotNull('destination_id')->pluck('destination')->filter()->values();
            $firsts[$d] = $stops->isNotEmpty() ? $place($stops->first()) : $here;
            $lasts[$d] = $stops->isNotEmpty() ? $place($stops->last()) : $here;
            $here = $lasts[$d];
        }

        $minutes = function (array $a, array $b) use ($preference): int {
            if ($a['lat'] === null || $b['lat'] === null) {
                return 0;
            }
            $km = 6371 * 2 * asin(min(1, sqrt(sin(deg2rad($b['lat'] - $a['lat']) / 2) ** 2
                + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin(deg2rad($b['lng'] - $a['lng']) / 2) ** 2)));

            return $this->estimator->estimate($km, $a['region'], $b['region'], $preference->transport_pref)['max_minutes'];
        };

        $total = 0;
        foreach ($items->where('kind', 'overnight')->whereNotNull('accommodation_id') as $row) {
            $n = (int) $row->day_number;
            if ($n >= $days || ! $row->accommodation) {
                continue;
            }
            $stay = $place($row->accommodation);
            $total += $minutes($lasts[$n], $stay) + $minutes($stay, $firsts[$n + 1]);
        }

        return $total;
    }

    /**
     * Rule breaks that a different stay could cause or hide: sightseeing after the 18:00 cutoff, the same
     * destination twice, and a first-day departure earlier than the arrival rule allows (the later of arrival plus
     * 30 minutes and 08:00).
     *
     * @param  Collection<int, \App\Models\ItineraryItem>  $items
     */
    private function violations(Collection $items, TouristPreference $preference): int
    {
        $count = 0;
        $activities = $items->where('kind', 'activity')->whereNotNull('destination_id');

        foreach ($activities as $row) {
            if ($row->ends_at !== null && $this->minutes($row->ends_at) > self::LAST_ACTIVITY_END_MINUTES) {
                $count++;
            }
        }

        $count += $activities->count() - $activities->pluck('destination_id')->unique()->count();

        $arrival = $preference->arrival_time;
        if (! blank($arrival)) {
            $earliest = max($this->minutes($arrival instanceof \DateTimeInterface ? $arrival->format('H:i:s') : (string) $arrival) + 30, 8 * 60);
            $first = $items->where('day_number', 1)->whereIn('kind', ['travel', 'activity'])->sortBy('sort_order')->first();
            if ($first && $first->starts_at !== null && $this->minutes($first->starts_at) < $earliest) {
                $count++;
            }
        }

        return $count;
    }

    private function minutes(mixed $time): int
    {
        if ($time instanceof \DateTimeInterface) {
            return (int) $time->format('G') * 60 + (int) $time->format('i');
        }
        $parts = explode(':', (string) $time);

        return (int) $parts[0] * 60 + (int) ($parts[1] ?? 0);
    }
}
