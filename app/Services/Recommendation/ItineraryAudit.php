<?php

namespace App\Services\Recommendation;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\TouristPreference;
use App\Support\OpeningHours;

/**
 * Checks a generated itinerary against rules a traveller would call "accurate", using only what the plan and the
 * catalogue store. It judges nothing it cannot see: a place with no readable hours is "unknown", not a pass.
 *
 * Each check comes back as ['key', 'label', 'status' => pass|fail|unknown, 'detail']. A plan is clean when no check
 * fails. The limits below are the standard a plan is held to; they are the numbers to quote and to tune.
 */
final class ItineraryAudit
{
    /**
     * A day's driving is held to a round trip to the edge of the trip's own reach: twice the radius of the distance the
     * traveller chose (5 km close, 15 to 50 km commutable by transport mode), plus slack for moving between stops.
     * A flat limit would contradict the choice itself, since one stop 50 km out is already a 100 km day. A remote trip
     * has no radius, so it is held to REMOTE_DAY_KM and, above all, to the travel-share check.
     */
    public const DAY_KM_SLACK = 10;

    public const REMOTE_DAY_KM = 150;

    /** A day's journeys take no more than this share of the time from its first to its last item. */
    public const MAX_TRAVEL_SHARE = 0.40;

    /** The stay is no further than this from any stop of the day it sleeps after. */
    public const MAX_STAY_KM = 40;

    /** A lunch starts, and a dinner starts, inside these windows (minutes after midnight). */
    public const LUNCH_START = [11 * 60, 13 * 60 + 30];

    public const DINNER_START = [17 * 60 + 30, 20 * 60];

    /** Arrival from this hour on, a first day with nothing to see is expected, not a fault. */
    private const LATE_ARRIVAL_HOUR = 15;

    private const BUDGET_ORDER = ['Free' => 0, 'Budget-Friendly' => 1, 'Mid-range' => 2, 'Premium' => 3];

    /** @return array<int, array{key: string, label: string, status: string, detail: string}> */
    public function audit(Itinerary $itinerary, TouristPreference $preference): array
    {
        $items = $itinerary->items()->with(['destination.tags', 'accommodation'])
            ->orderBy('day_number')->orderBy('sort_order')->get();
        $start = $preference->travelWindow()[0];
        $activities = $items->where('kind', 'activity')->filter(fn (ItineraryItem $i) => $i->destination !== null);

        return [
            $this->startHonoured($preference),
            $this->stopsLocated($activities),
            $this->journeysMeasured($items),
            $this->openAtVisit($activities, $start),
            $this->dayDistance($items, $itinerary, $preference),
            $this->travelShare($items, $preference),
            $this->interestCoverage($itinerary, $items, $activities, $preference),
            $this->everyDayHasAStop($items, $activities, $itinerary, $preference),
            $this->arrivalRespected($activities, $preference),
            $this->mealTimes($items),
            $this->stayClose($items, $activities),
            $this->budgetFit($activities, $preference),
        ];
    }

    /** True when no check failed. */
    public function clean(array $checks): bool
    {
        return collect($checks)->doesntContain('status', 'fail');
    }

    private function check(string $key, string $label, string $status, string $detail = ''): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }

    private function startHonoured(TouristPreference $p): array
    {
        $label = 'The start point the traveller gave is the one used';

        return filled($p->origin_label) && $p->origin() === null
            ? $this->check('start', $label, 'fail', "'{$p->origin_label}' could not be located, so the plan starts from the city centre")
            : $this->check('start', $label, 'pass');
    }

    private function stopsLocated($activities): array
    {
        $label = 'Every stop has coordinates';
        $missing = $activities->filter(fn ($i) => $i->destination->latitude === null || $i->destination->longitude === null)
            ->map(fn ($i) => $i->destination->name)->unique()->values();

        return $missing->isEmpty() ? $this->check('located', $label, 'pass')
            : $this->check('located', $label, 'fail', $missing->implode(', '));
    }

    private function journeysMeasured($items): array
    {
        $label = 'Every journey has a distance and a time';
        $unmeasured = $items->where('kind', 'travel')->filter(fn ($i) => $i->distance_km === null);

        return $unmeasured->isEmpty() ? $this->check('journeys', $label, 'pass')
            : $this->check('journeys', $label, 'fail', $unmeasured->count().' journey(s) show "Travel time varies": '
                .$unmeasured->map(fn ($i) => (string) $i->title)->unique()->implode('; '));
    }

    private function openAtVisit($activities, $start): array
    {
        $label = 'Every stop is open when it is visited';
        $problems = [];
        $unknown = [];

        foreach ($activities as $item) {
            $date = $start->copy()->addDays($item->day_number - 1);
            $windows = OpeningHours::windowsOn($item->destination->hours, $date);
            $from = $this->minutes($item->starts_at);
            $to = $this->minutes($item->ends_at);

            if ($windows === null) {
                $unknown[$item->destination->name] = true;

                continue;
            }
            if ($from === null || $to === null) {
                continue;
            }
            $inside = collect($windows)->contains(fn ($w) => $from >= $w[0] && $to <= $w[1]);
            if (! $inside) {
                $problems[] = sprintf('%s day %d %s–%s (hours %s)', $item->destination->name, $item->day_number,
                    $this->clock($from), $this->clock($to), $item->destination->hours ?: 'closed that day');
            }
        }

        if ($problems) {
            return $this->check('open', $label, 'fail', implode('; ', $problems));
        }

        return $unknown
            ? $this->check('open', $label, 'unknown', 'no readable hours for '.implode(', ', array_keys($unknown)))
            : $this->check('open', $label, 'pass');
    }

    /**
     * The most a day may drive on this trip, in km. A trip that ran short of places and was widened is held to the
     * tier it was actually built with (the itinerary records it), not the one it asked for: the traveller was told.
     */
    public function dayKmLimit(TouristPreference $preference, ?string $tierUsed = null): int
    {
        $radius = ContentBasedRecommendationService::radiusKm($tierUsed ?: $preference->distance_pref, $preference->transport_pref);

        return $radius === null ? self::REMOTE_DAY_KM : (int) (2 * $radius + self::DAY_KM_SLACK);
    }

    private function dayDistance($items, Itinerary $itinerary, TouristPreference $preference): array
    {
        $limit = $this->dayKmLimit($preference, $itinerary->range_tier_used);
        $label = "No day drives more than {$limit} km (a round trip to the edge of the trip's reach)";
        $over = [];
        foreach ($items->where('kind', 'travel')->groupBy('day_number') as $day => $legs) {
            $km = (float) $legs->sum(fn ($l) => (float) $l->distance_km);
            if ($km > $limit) {
                $over[] = sprintf('day %d is %.0f km', $day, $km);
            }
        }

        return $over ? $this->check('day_km', $label, 'fail', implode('; ', $over)) : $this->check('day_km', $label, 'pass');
    }

    private function travelShare($items, TouristPreference $preference): array
    {
        $label = 'No day spends more than '.(int) (self::MAX_TRAVEL_SHARE * 100).'% of its time travelling';
        $over = [];

        foreach ($items->groupBy('day_number') as $day => $rows) {
            // A deadline-driven transfer-only departure day is expected to be mostly travelling.
            if (filled($preference->departure_time) && $rows->contains('kind', 'departure')
                && $rows->where('kind', 'activity')->whereNotNull('destination_id')->isEmpty()) {
                continue;
            }
            $times = $rows->flatMap(fn ($r) => [$this->minutes($r->starts_at), $this->minutes($r->ends_at)])->filter(fn ($m) => $m !== null);
            $span = $times->isEmpty() ? 0 : $times->max() - $times->min();
            $travel = (int) $rows->where('kind', 'travel')->sum('travel_max_minutes');
            if ($span > 0 && $travel / $span > self::MAX_TRAVEL_SHARE) {
                $over[] = sprintf('day %d is %d%% (%d of %d min)', $day, round($travel / $span * 100), $travel, $span);
            }
        }

        return $over ? $this->check('travel_share', $label, 'fail', implode('; ', $over)) : $this->check('travel_share', $label, 'pass');
    }

    private function interestCoverage(Itinerary $itinerary, $items, $activities, TouristPreference $preference): array
    {
        $label = 'Every interest the traveller chose has a stop';
        $wanted = $preference->activities->pluck('activity')->filter()->values();
        if ($wanted->isEmpty()) {
            return $this->check('interests', $label, 'pass');
        }

        $souvenirStop = $items->where('kind', 'activity')->whereNotNull('souvenir_center_id')->isNotEmpty();

        $uncovered = $wanted->reject(fn (string $interest) => ($interest === 'Shopping & Souvenirs' && $souvenirStop)
            || $activities->contains(fn ($i) => InterestCoverage::covers($i->destination, $interest)));

        if ($uncovered->isEmpty()) {
            return $this->check('interests', $label, 'pass');
        }

        // An interest nothing inside the trip's own range offers is a limit of the catalogue and the range the traveller
        // chose, not a miss by the planner, so it is "unknown", not a failure. Remote has no range, so it always counts.
        $outOfRange = $this->interestsNothingInRangeOffers($uncovered, $itinerary, $preference);
        $missed = $uncovered->reject(fn ($i) => $outOfRange->contains($i));

        if ($missed->isNotEmpty()) {
            return $this->check('interests', $label, 'fail', 'no stop for '.$missed->implode(', '));
        }

        return $this->check('interests', $label, 'unknown', 'nothing within the trip\'s range offers '.$outOfRange->implode(', '));
    }

    /** The interests, of those given, that no place within the trip's range (from where it starts) could cover. */
    private function interestsNothingInRangeOffers($interests, Itinerary $itinerary, TouristPreference $preference)
    {
        $radius = ContentBasedRecommendationService::radiusKm($itinerary->range_tier_used ?: $preference->distance_pref, $preference->transport_pref);
        if ($radius === null) {
            return collect();
        }

        $origin = $preference->origin() ?? ['lat' => ContentBasedRecommendationService::DEFAULT_ORIGIN_LAT, 'lng' => ContentBasedRecommendationService::DEFAULT_ORIGIN_LNG];
        [$from, $to] = $preference->travelWindow();

        $inRange = Destination::query()->where('is_accredited', true)->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('itinerary_role')->orWhere('itinerary_role', '!=', 'excluded'))
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->availableDuring($from, $to)->with('tags')->get()
            ->filter(fn (Destination $d) => $this->km($origin['lat'], $origin['lng'], (float) $d->latitude, (float) $d->longitude) <= $radius);

        return $interests->filter(fn (string $interest) => ! $inRange->contains(fn (Destination $d) => InterestCoverage::covers($d, $interest)))->values();
    }

    private function everyDayHasAStop($items, $activities, Itinerary $itinerary, TouristPreference $preference): array
    {
        $label = 'Every day has at least one stop';
        $late = $preference->arrival_time && (int) substr((string) $preference->arrival_time, 0, 2) >= self::LATE_ARRIVAL_HOUR;
        $empty = [];

        foreach (range(1, max(1, (int) $itinerary->total_days)) as $day) {
            if ($day === (int) $itinerary->total_days && filled($preference->departure_time)
                && substr((string) $preference->departure_time, 0, 5) <= ItineraryScheduleBuilder::EARLIEST_DEPARTURE) {
                continue;
            }
            if ($day === 1 && $late) {
                continue;
            }
            if ($activities->where('day_number', $day)->isEmpty()) {
                $empty[] = "day {$day}";
            }
        }

        return $empty ? $this->check('stops_per_day', $label, 'fail', implode(', ', $empty).' has nothing to see')
            : $this->check('stops_per_day', $label, 'pass');
    }

    private function arrivalRespected($activities, TouristPreference $preference): array
    {
        $label = 'Nothing is planned before the traveller has arrived';
        $arrival = $this->minutes($preference->arrival_time);
        $first = $activities->where('day_number', 1)->map(fn ($i) => $this->minutes($i->starts_at))->filter(fn ($m) => $m !== null)->min();

        if ($arrival === null || $first === null) {
            return $this->check('arrival', $label, 'pass');
        }

        return $first < $arrival + ItineraryScheduleBuilder::ARRIVAL_PREPARATION_MINUTES
            ? $this->check('arrival', $label, 'fail', sprintf('arrives %s, first stop %s', $this->clock($arrival), $this->clock($first)))
            : $this->check('arrival', $label, 'pass');
    }

    private function mealTimes($items): array
    {
        $label = 'Lunch and dinner fall at mealtimes';
        $off = [];

        foreach ($items->where('kind', 'meal') as $meal) {
            $start = $this->minutes($meal->starts_at);
            $title = (string) $meal->title;
            $window = str_starts_with($title, 'Lunch') ? self::LUNCH_START : (str_starts_with($title, 'Dinner') ? self::DINNER_START : null);
            if ($window && $start !== null && ($start < $window[0] || $start > $window[1])) {
                $off[] = sprintf('day %d %s at %s', $meal->day_number, strtok($title, ' —'), $this->clock($start));
            }
        }

        return $off ? $this->check('meals', $label, 'fail', implode('; ', $off)) : $this->check('meals', $label, 'pass');
    }

    private function stayClose($items, $activities): array
    {
        $label = 'The stay is within '.self::MAX_STAY_KM.' km of the day\'s stops';
        $far = [];
        $unknown = false;

        foreach ($items->where('kind', 'overnight') as $night) {
            $stay = $night->accommodation;
            if (! $stay || $stay->latitude === null) {
                $unknown = true;

                continue;
            }
            foreach ($activities->where('day_number', $night->day_number) as $stop) {
                $d = $stop->destination;
                if ($d->latitude === null) {
                    continue;
                }
                $km = $this->km((float) $stay->latitude, (float) $stay->longitude, (float) $d->latitude, (float) $d->longitude);
                if ($km > self::MAX_STAY_KM) {
                    $far[] = sprintf('day %d: %s is %.0f km from %s', $night->day_number, $stay->name, $km, $d->name);
                }
            }
        }

        if ($far) {
            return $this->check('stay', $label, 'fail', implode('; ', $far));
        }

        return $unknown ? $this->check('stay', $label, 'unknown', 'a stay has no coordinates') : $this->check('stay', $label, 'pass');
    }

    private function budgetFit($activities, TouristPreference $preference): array
    {
        $label = 'No stop is above the traveller\'s budget';
        $limit = self::BUDGET_ORDER[$preference->budget] ?? null;
        if ($limit === null) {
            return $this->check('budget', $label, 'pass');
        }

        $over = $activities->filter(fn ($i) => (self::BUDGET_ORDER[$i->destination->price_tier] ?? -1) > $limit)
            ->map(fn ($i) => "{$i->destination->name} ({$i->destination->price_tier})")->unique()->values();

        return $over->isEmpty() ? $this->check('budget', $label, 'pass') : $this->check('budget', $label, 'fail', $over->implode(', '));
    }

    private function minutes(mixed $time): ?int
    {
        if ($time === null || $time === '') {
            return null;
        }
        $parts = explode(':', substr((string) $time, 0, 5));

        return count($parts) === 2 ? ((int) $parts[0]) * 60 + (int) $parts[1] : null;
    }

    private function clock(int $minutes): string
    {
        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * asin(min(1, sqrt($a)));
    }
}
