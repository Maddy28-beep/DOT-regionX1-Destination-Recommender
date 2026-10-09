<?php

namespace App\Services\Recommendation;

use App\Models\Accommodation;
use App\Support\OpeningHours;
use App\Models\Itinerary;
use App\Models\TouristPreference;
use Illuminate\Support\Carbon;

/**
 * Turns an ordered list of stops into an hour-by-hour schedule.
 *
 * The generator decides WHICH places to visit and in WHAT ORDER (content-based
 * ranking, then nearest-neighbour sequencing). This class decides WHEN each of
 * them happens, and writes the rows in between that a real day contains:
 * setting off, the journey and how long it takes, being on site, lunch, getting
 * back, dinner, the night.
 *
 * It works as a clock simulation rather than a set of fixed day templates. A
 * cursor starts at the traveller's arrival time on day 1 and at breakfast on
 * every day after, and each stop pushes it forward by its journey and its
 * visit. Meals drop in when the clock passes their window. Doing it this way
 * means a 3pm arrival, a two-day trip and a seven-day trip all fall out of the
 * same logic instead of needing a template each.
 *
 * Every row records what it is (`kind`), so the schedule can print a real
 * distance against a journey and "On-site Activity" or "Within Accommodation"
 * against everything else.
 */
class ItineraryScheduleBuilder
{
    /** Non-arrival days start with breakfast at this hour. */
    private const BREAKFAST = '07:00';

    /** ...and set off an hour later. */
    private const DEPART_AFTER_BREAKFAST = '08:00';

    /** How long a traveller spends at one place, in minutes. */
    private const VISIT_MINUTES = 150;

    /** A shorter visit, used where the day has to fit more in. */
    private const SHORT_VISIT_MINUTES = 90;

    /** Lunch is taken at the first opportunity inside this window. */
    private const LUNCH_FROM = '11:30';

    /** Past this, a meal is not lunch any more and is skipped rather than faked. */
    private const LUNCH_LATEST = '14:00';

    private const MEAL_MINUTES = 60;

    /**
     * A day's sightseeing has to be finished by this hour.
     *
     * Without a cutoff the clock simply kept accumulating: a far-flung second
     * stop with a four-hour drive produced souvenir shopping at 1:45 AM and a
     * departure at 3:30 AM. A stop that cannot finish by the cutoff is pushed
     * to the next day instead, and a plan with fewer stops is worth more than
     * one that is impossible.
     */
    private const LAST_ACTIVITY_END = '18:00';

    /**
     * How long a day may spend on the road, on the slow end of the travel estimates, by how far the traveller
     * said they are willing to go. The cutoff above only stops a day from running late; it does not stop one
     * from being mostly driving, which is how a two-day trip ended up with three legs of one to three hours
     * each on the same day.
     *
     * The count includes the journey back to where the day ends (the accommodation, or the departure point
     * on the last day). A day's first stop is always allowed, so a far-flung stop still gets a day of its own
     * instead of never being scheduled.
     */
    private const DRIVE_BUDGET_MINUTES = ['near' => 180, 'moderate' => 240, 'far' => 360];

    /** Used when the traveller's distance preference is missing or unknown. */
    private const DEFAULT_DRIVE_BUDGET_MINUTES = 240;

    /**
     * The souvenir stop is a short extra at the end of the trip, not a second excursion: it has to be near
     * the last stop, and the visit has to be over by this hour (shops list no hours of their own).
     */
    private const SOUVENIR_MAX_KM = 20;

    private const SOUVENIR_LATEST_END = '19:00';

    private const DINNER = '18:30';

    private const OVERNIGHT = '20:00';

    /** Buffer between finishing at a place and setting off from it. */
    private const DEPARTURE_BUFFER_MINUTES = 15;

    /**
     * Allowance for a leg whose distance cannot be computed.
     *
     * Most of the catalogue has no coordinates -- the DOT accreditation import
     * carried names and addresses, not positions, so 202 of 222 accommodations
     * and 17 of 25 destinations are missing them. Treating a missing
     * coordinate as 0,0 put the traveller in the Gulf of Guinea and produced
     * an 18,000 km transfer that swallowed the rest of the trip. A leg we
     * cannot measure gets an honest "travel time varies" and a sane allowance
     * instead of a fabricated distance.
     */
    private const UNKNOWN_TRAVEL_MINUTES = 45;

    /**
     * Titles for the on-site row, by destination type. Their example reads
     * "Island hopping and beach activities" rather than the bare place name,
     * because what you go to do is the useful part of a schedule line.
     */
    private const ACTIVITY_BY_TYPE = [
        'Beach & Leisure' => 'Island hopping and beach activities',
        'Beach & Surfing' => 'Beach and surfing activities',
        'Nature & Adventure' => 'Nature and adventure activities',
        'Nature & Leisure' => 'Nature walks and leisure activities',
        'Adventure & Hiking' => 'Hiking and outdoor adventure',
        'Wildlife' => 'Wildlife exploration',
        'Cultural Heritage' => 'Cultural and heritage tour',
        'Farm Tourism' => 'Farm tour and tasting experience',
        'Wellness & Spa' => 'Wellness and spa session',
        'Sports & Recreation' => 'Sports and recreation activities',
        'Events & Conventions' => 'Events and convention visit',
    ];

    public function __construct(
        private readonly TravelTimeEstimator $travel,
        private readonly AprioriService $apriori,
    ) {}

    /**
     * @param  array<int, array<int, string>>  $dayCapacities  day number => slot names it can hold
     * @param  array<int, array{row: array, distance_km: float|null}>  $sequence
     * @param  array{lat: float, lng: float, label: string}  $origin
     */
    public function build(
        Itinerary $itinerary,
        array $sequence,
        TouristPreference $preference,
        array $dayCapacities,
        array $origin,
        ?array $stay,
    ): void {
        $accommodation = $stay['listing'] ?? null;
        $stayRule = $stay['rule'] ?? null;
        $sortOrder = 0;
        $lastDay = array_key_last($dayCapacities);

        /*
         * A queue, not a fixed per-day slice. A stop that cannot be reached and
         * visited before the day's cutoff stays at the front of the queue and
         * is tried again tomorrow, rather than being crammed into tonight. That
         * is what stops a distant stop dragging the day past midnight.
         */
        $queue = $sequence;

        // Where the traveller physically is. Starts at their baseline and moves
        // with them, which is what makes each journey estimate honest rather
        // than always measured from the same point.
        $here = $origin;
        $lastDestination = null;

        foreach ($dayCapacities as $dayNumber => $slots) {
            $isLastDay = $dayNumber === $lastDay;
            $capacity = count($slots);

            $clock = $this->openDay($itinerary, $dayNumber, $sortOrder, $preference, $origin, $accommodation);
            $lunchTaken = false;
            $scheduled = 0;

            // Road time so far today, and where the day has to end (for the return journey).
            $driven = 0;
            $dayEnd = $isLastDay ? $origin : ($accommodation ? $this->place($accommodation) : null);

            while ($scheduled < $capacity && $queue !== []) {
                $destination = $queue[0]['row']['destination'];
                $there = $this->place($destination);

                // The last stop of the final day is trimmed so there is time to
                // shop, eat and still reach the departure point.
                $visitMinutes = ($isLastDay && ($scheduled === $capacity - 1 || count($queue) === 1))
                    ? self::SHORT_VISIT_MINUTES
                    : self::VISIT_MINUTES;

                $visit = $this->planVisit($clock, $here, $there, $visitMinutes, $lunchTaken, $destination);

                if ($visit === null) {
                    // Nothing was done today, so this stop is not just late for today -- if it cannot be done
                    // even from a fresh morning (it has closed before anyone could get there, or it is too far
                    // to visit in a day) it never will be. Drop it, so it cannot sit at the front of the queue
                    // and keep every stop behind it from being scheduled.
                    $freshMorning = Carbon::parse(self::DEPART_AFTER_BREAKFAST);

                    if ($scheduled === 0 && $this->planVisit($freshMorning, $here, $there, $visitMinutes, false, $destination) === null) {
                        array_shift($queue);

                        continue;
                    }

                    break;
                }

                // Too much driving for one day: leave this stop (and everything after it) for tomorrow.
                $leg = $this->estimateLeg($here, $there)['max_minutes'];
                $back = $dayEnd ? $this->estimateLeg($there, $dayEnd)['max_minutes'] : 0;

                if ($scheduled > 0 && $driven + $leg + $back > $this->driveBudget($preference)) {
                    break;
                }

                array_shift($queue);

                $clock = $this->addTravel($itinerary, $dayNumber, $sortOrder, $clock, $here, $there);
                $driven += $leg;
                $here = $there;

                // Checked on arrival as well as after the visit: a stop that
                // starts at noon and runs to half past two would otherwise
                // straddle the whole lunch window and skip the meal entirely.
                if (! $lunchTaken && $this->lunchIsDue($clock)) {
                    $clock = $this->addLunch($itinerary, $dayNumber, $sortOrder, $clock, $destination);
                    $lunchTaken = true;
                }

                // A place that is not open yet on arrival: the visit starts when it opens.
                if ($visit['starts_at'] !== null && $visit['starts_at']->greaterThan($clock)) {
                    $clock = $visit['starts_at']->copy();
                }

                $clock = $this->addActivity($itinerary, $dayNumber, $sortOrder, $clock, $destination, $visit['minutes']);
                $lastDestination = $destination;
                $scheduled++;

                if (! $lunchTaken && $this->lunchIsDue($clock)) {
                    $clock = $this->addLunch($itinerary, $dayNumber, $sortOrder, $clock, $destination);
                    $lunchTaken = true;
                }
            }

            if ($isLastDay) {
                $this->closeTripDay($itinerary, $dayNumber, $sortOrder, $clock, $here, $origin, $lunchTaken, $lastDestination);
            } else {
                $this->closeOvernightDay($itinerary, $dayNumber, $sortOrder, $clock, $here, $accommodation, $stayRule);
                $here = $accommodation ? $this->place($accommodation) : $here;
            }
        }
    }

    /**
     * A leg's distance and duration, or a plain time allowance when either end
     * has no coordinates.
     *
     * @return array{distance_km: float|null, min_minutes: int, max_minutes: int}
     */
    private function estimateLeg(array $from, array $to): array
    {
        if (! $this->measurable($from, $to)) {
            return [
                'distance_km' => null,
                'min_minutes' => self::UNKNOWN_TRAVEL_MINUTES,
                'max_minutes' => self::UNKNOWN_TRAVEL_MINUTES,
            ];
        }

        return $this->travel->estimate(
            $this->haversineKm($from['lat'], $from['lng'], $to['lat'], $to['lng']),
            $from['region'] ?? null,
            $to['region'] ?? null,
        );
    }

    /**
     * Can this stop be reached and visited today, and for how long?
     *
     * It has to be over by the day's cutoff, and -- where the place's hours are a plain daily schedule -- it
     * has to happen while the place is open: the visit waits for opening time if the traveller arrives early,
     * is cut short to end at closing time, and is left for another day if less than a short visit would fit.
     * Hours that cannot be read ("event-dependent", "Mon-Sat ...") put no limit on the visit, as before.
     *
     * Mirrors what build() then does (slow travel estimate, lunch on arrival if it is due, quarter-hour
     * rounding), so a stop that passes here is scheduled exactly as checked.
     *
     * @return array{minutes: int, starts_at: Carbon|null}|null  null when the stop cannot be done today;
     *                                                           starts_at is set only when the visit has to wait for opening
     */
    private function planVisit(Carbon $clock, array $from, array $to, int $visitMinutes, bool $lunchTaken, $destination): ?array
    {
        $estimate = $this->estimateLeg($from, $to);
        $arrival = $clock->copy()->addMinutes($estimate['max_minutes']);

        // Charge for lunch only when lunch would genuinely be taken on arrival.
        // Charging for it unconditionally made a mid-afternoon arrival look an
        // hour longer than it is and cost the traveller their only stop.
        if (! $lunchTaken && $this->lunchIsDue($arrival)) {
            $arrival = $this->toQuarterHour($arrival)->addMinutes(self::MEAL_MINUTES);
        }

        $start = $this->toQuarterHour($arrival);

        // The sightseeing cutoff, on the full visit as it always was.
        if ($start->copy()->addMinutes($visitMinutes)->greaterThan(Carbon::parse(self::LAST_ACTIVITY_END))) {
            return null;
        }

        $windows = OpeningHours::windows($destination->hours ?? null);

        if ($windows === null) {
            return ['minutes' => $visitMinutes, 'starts_at' => null];
        }

        $midnight = $start->copy()->startOfDay();
        $startMinute = $start->hour * 60 + $start->minute;
        $enough = min($visitMinutes, self::SHORT_VISIT_MINUTES);

        foreach ($windows as [$open, $close]) {
            if ($close <= $startMinute) {
                continue; // already closed by the time we would get there
            }

            $begin = max($startMinute, $open);
            $minutes = min($visitMinutes, $close - $begin);

            if ($minutes >= $enough) {
                return [
                    'minutes' => $minutes,
                    'starts_at' => $begin > $startMinute ? $midnight->copy()->addMinutes($begin) : null,
                ];
            }
        }

        return null;
    }

    /** Minutes a day may spend driving, by the traveller's distance preference. */
    private function driveBudget(TouristPreference $preference): int
    {
        return self::DRIVE_BUDGET_MINUTES[$preference->distance_pref] ?? self::DEFAULT_DRIVE_BUDGET_MINUTES;
    }

    /** Arrival day opens where the traveller lands; every other day opens with breakfast. */
    private function openDay(
        Itinerary $itinerary,
        int $dayNumber,
        int &$sortOrder,
        TouristPreference $preference,
        array $origin,
        ?Accommodation $accommodation,
    ): Carbon {
        if ($dayNumber === 1) {
            $arrival = $this->arrivalTime($preference);

            $this->row($itinerary, $dayNumber, $sortOrder, [
                'kind' => 'baseline',
                'title' => 'Arrival — '.$origin['label'],
                'starts_at' => $arrival,
            ]);

            return $arrival->copy()->addMinutes(30);
        }

        $this->row($itinerary, $dayNumber, $sortOrder, [
            'kind' => 'meal',
            'title' => 'Breakfast — '.($accommodation?->name ?? 'your accommodation'),
            'starts_at' => Carbon::parse(self::BREAKFAST),
            'accommodation_id' => $accommodation?->id,
        ]);

        return Carbon::parse(self::DEPART_AFTER_BREAKFAST);
    }

    /** A journey row, returning the clock at arrival. */
    private function addTravel(Itinerary $itinerary, int $dayNumber, int &$sortOrder, Carbon $clock, array $from, array $to): Carbon
    {
        $estimate = $this->estimateLeg($from, $to);

        $this->row($itinerary, $dayNumber, $sortOrder, [
            'kind' => 'travel',
            'title' => 'Travel to '.$to['label'],
            'starts_at' => $clock->copy(),
            'distance_km' => $estimate['distance_km'],
            'travel_min_minutes' => $estimate['min_minutes'],
            'travel_max_minutes' => $estimate['max_minutes'],
        ]);

        // The plan advances on the slower figure. Building a day on the
        // optimistic one guarantees it runs late by mid-afternoon.
        return $clock->copy()->addMinutes($estimate['max_minutes']);
    }

    /** The on-site row, returning the clock once the visit is done. */
    private function addActivity(Itinerary $itinerary, int $dayNumber, int &$sortOrder, Carbon $clock, $destination, int $minutes): Carbon
    {
        $start = $this->toQuarterHour($clock);
        $end = $start->copy()->addMinutes($minutes);

        $this->row($itinerary, $dayNumber, $sortOrder, [
            'kind' => 'activity',
            'title' => self::ACTIVITY_BY_TYPE[$destination->type] ?? ('Explore '.$destination->name),
            'starts_at' => $start,
            'ends_at' => $end,
            'destination_id' => $destination->id,
        ]);

        return $end;
    }

    /**
     * Lunch, at a nearby DOT-accredited restaurant where Apriori knows one and
     * at the destination itself otherwise -- which is the truthful answer for
     * a resort or park with its own restaurant.
     */
    private function addLunch(Itinerary $itinerary, int $dayNumber, int &$sortOrder, Carbon $clock, $destination): Carbon
    {
        $suggestion = $this->nearbyRestaurant($destination);
        $restaurant = $suggestion['listing'] ?? null;
        $start = $this->toQuarterHour($clock);

        $this->row($itinerary, $dayNumber, $sortOrder, array_merge([
            'kind' => 'meal',
            'title' => 'Lunch — '.($restaurant?->name ?? $destination->name),
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes(self::MEAL_MINUTES),
            'restaurant_id' => $restaurant?->id,
            'destination_id' => $restaurant ? null : $destination->id,
        ], $this->ruleColumns($suggestion['rule'] ?? null)));

        return $start->copy()->addMinutes(self::MEAL_MINUTES);
    }

    /**
     * The association-rule columns for a row, or nulls when no rule applied.
     *
     * @param  array{basis: string, support: float, confidence: float, co_count: int}|null  $rule
     */
    private function ruleColumns(?array $rule): array
    {
        return [
            'rule_basis' => $rule['basis'] ?? null,
            'rule_support' => $rule['support'] ?? null,
            'rule_confidence' => $rule['confidence'] ?? null,
            'rule_co_count' => $rule['co_count'] ?? null,
        ];
    }

    /** Days that end at the accommodation: travel back, dinner, overnight. */
    private function closeOvernightDay(Itinerary $itinerary, int $dayNumber, int &$sortOrder, Carbon $clock, array $here, ?Accommodation $accommodation, ?array $stayRule = null): void
    {
        if (! $accommodation) {
            return;
        }

        $this->addTravel(
            $itinerary,
            $dayNumber,
            $sortOrder,
            $this->toQuarterHour($clock->copy()->addMinutes(self::DEPARTURE_BUFFER_MINUTES)),
            $here,
            $this->place($accommodation),
        );

        $this->row($itinerary, $dayNumber, $sortOrder, [
            'kind' => 'meal',
            'title' => 'Dinner — '.$accommodation->name,
            'starts_at' => Carbon::parse(self::DINNER),
            'accommodation_id' => $accommodation->id,
        ]);

        $this->row($itinerary, $dayNumber, $sortOrder, array_merge([
            'kind' => 'overnight',
            'title' => 'Overnight stay',
            'starts_at' => Carbon::parse(self::OVERNIGHT),
            'accommodation_id' => $accommodation->id,
        ], $this->ruleColumns($stayRule)));
    }

    /** The final day: souvenirs, a last meal if lunch has not happened, then the journey out. */
    private function closeTripDay(Itinerary $itinerary, int $dayNumber, int &$sortOrder, Carbon $clock, array $here, array $origin, bool $lunchTaken, $lastDestination = null): void
    {
        $suggestion = $this->souvenirStop($here, $lastDestination, $clock);
        $souvenirs = $suggestion['listing'] ?? null;

        if ($souvenirs) {
            $clock = $this->addTravel(
                $itinerary,
                $dayNumber,
                $sortOrder,
                $this->toQuarterHour($clock->copy()->addMinutes(self::DEPARTURE_BUFFER_MINUTES)),
                $here,
                $this->place($souvenirs),
            );

            $start = $this->toQuarterHour($clock);
            $end = $start->copy()->addMinutes(self::MEAL_MINUTES);

            $this->row($itinerary, $dayNumber, $sortOrder, array_merge([
                'kind' => 'activity',
                'title' => 'Souvenir shopping',
                'starts_at' => $start,
                'ends_at' => $end,
                'souvenir_center_id' => $souvenirs->id,
            ], $this->ruleColumns($suggestion['rule'] ?? null)));

            $clock = $end;
            $here = $this->place($souvenirs);
        }

        if (! $lunchTaken) {
            $start = $this->toQuarterHour($clock);
            $this->row($itinerary, $dayNumber, $sortOrder, [
                'kind' => 'meal',
                'title' => 'Lunch before departure',
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes(self::MEAL_MINUTES),
            ]);
            $clock = $start->copy()->addMinutes(self::MEAL_MINUTES);
        }

        $clock = $this->addTravel(
            $itinerary,
            $dayNumber,
            $sortOrder,
            $this->toQuarterHour($clock->copy()->addMinutes(self::DEPARTURE_BUFFER_MINUTES)),
            $here,
            $origin,
        );

        $this->row($itinerary, $dayNumber, $sortOrder, [
            'kind' => 'departure',
            'title' => 'Departure',
            'starts_at' => $this->toQuarterHour($clock),
        ]);
    }

    /** Writes one schedule line. */
    private function row(Itinerary $itinerary, int $dayNumber, int &$sortOrder, array $attributes): void
    {
        $starts = $attributes['starts_at'] ?? null;
        $ends = $attributes['ends_at'] ?? null;

        // Overrides go on the LEFT of the union: array + keeps the left-hand
        // key, and passing the Carbon objects through unformatted wrote a full
        // datetime ("2026-08-30 08:00:00") into a time column.
        $itinerary->items()->create([
            'day_number' => $dayNumber,
            'sort_order' => $sortOrder++,
            'slot' => $starts ? $this->slotFor($starts) : 'Any time',
            'starts_at' => $starts?->format('H:i:s'),
            'ends_at' => $ends?->format('H:i:s'),
        ] + $attributes);
    }

    /** The time-of-day band a row falls in, kept for the recommendation write-up. */
    private function slotFor(Carbon $time): string
    {
        return match (true) {
            $time->hour < 12 => 'Morning',
            $time->hour < 17 => 'Afternoon',
            default => 'Evening',
        };
    }

    private function arrivalTime(TouristPreference $preference): Carbon
    {
        return blank($preference->arrival_time)
            ? Carbon::parse('08:00')
            : Carbon::parse(substr((string) $preference->arrival_time, 0, 5));
    }

    /**
     * Lunch is due once the window opens and stays due until it is genuinely
     * too late to call it lunch.
     *
     * The first version only fired if the clock landed inside a narrow window
     * at the exact moment it was checked, so a day whose stops ended at 11:15
     * and 2:15 skipped the meal altogether.
     */
    private function lunchIsDue(Carbon $clock): bool
    {
        return $clock->greaterThanOrEqualTo(Carbon::parse(self::LUNCH_FROM))
            && $clock->lessThanOrEqualTo(Carbon::parse(self::LUNCH_LATEST));
    }

    /**
     * Null coordinates stay null. Casting them to a float made them 0,0 -- a
     * real point in the Atlantic that every distance was then measured to.
     *
     * @return array{lat: float|null, lng: float|null, label: string, region: string|null}
     */
    private function place($listing): array
    {
        return [
            'lat' => $listing->latitude === null ? null : (float) $listing->latitude,
            'lng' => $listing->longitude === null ? null : (float) $listing->longitude,
            'label' => $listing->name,
            'region' => $listing->region?->name,
        ];
    }

    /** Both ends have to be known before a distance means anything. */
    private function measurable(array $from, array $to): bool
    {
        return $from['lat'] !== null && $from['lng'] !== null
            && $to['lat'] !== null && $to['lng'] !== null;
    }

    /**
     * @return array{listing: mixed, rule: array}|null
     */
    private function nearbyRestaurant($destination): ?array
    {
        $rule = $this->apriori->suggestionsFor('destination', $destination->id, 5)
            ->firstWhere('listing_kind', 'restaurant');

        if (! $rule || ! $rule['listing']) {
            return null;
        }

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

    /**
     * The souvenir stop: the first candidate (see souvenirCandidates) that is close enough and early
     * enough to be worth the detour, or none -- a trip is better without a souvenir stop than with one
     * that is an hour away or ends after dark.
     *
     * @return array{listing: mixed, rule: array|null}|null
     */
    private function souvenirStop(array $here, $lastDestination, Carbon $clock): ?array
    {
        foreach ($this->souvenirCandidates($here, $lastDestination) as $candidate) {
            if ($this->souvenirFits($here, $candidate['listing'], $clock)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Is this shop close enough, and is there time to get there and browse before the evening?
     *
     * A shop whose distance cannot be measured is judged on time alone, with the usual allowance for an
     * unmeasured journey.
     */
    private function souvenirFits(array $here, $shop, Carbon $clock): bool
    {
        $there = $this->place($shop);
        $estimate = $this->estimateLeg($here, $there);

        if ($estimate['distance_km'] !== null && $estimate['distance_km'] > self::SOUVENIR_MAX_KM) {
            return false;
        }

        $arrival = $this->toQuarterHour($clock->copy()->addMinutes(self::DEPARTURE_BUFFER_MINUTES));
        $arrival = $arrival->addMinutes($estimate['max_minutes']);
        $end = $this->toQuarterHour($arrival)->addMinutes(self::MEAL_MINUTES);

        return $end->lessThanOrEqualTo(Carbon::parse(self::SOUVENIR_LATEST_END));
    }

    /**
     * Where to shop, best reason first: what visitors to the last stop also went to, then the nearest shop.
     *
     * Apriori first: "people who visited this place also visited that shop" is a stronger reason to send
     * someone somewhere than "it happens to be closest", and it is the same mechanism that picks the
     * restaurants. Proximity is the fallback, and when the traveller's position is unknown the best-rated
     * shop is, since "nearest" would then be a guess dressed up as a measurement.
     *
     * @return list<array{listing: mixed, rule: array|null}>
     */
    private function souvenirCandidates(array $here, $lastDestination): array
    {
        $candidates = [];

        if ($lastDestination) {
            $rule = $this->apriori->suggestionsFor('destination', $lastDestination->id, 5)
                ->firstWhere('listing_kind', 'souvenir_center');

            if ($rule && $rule['listing']) {
                $candidates[] = [
                    'listing' => $rule['listing'],
                    'rule' => [
                        'basis' => $lastDestination->name,
                        'support' => $rule['support'],
                        'confidence' => $rule['confidence'],
                        'co_count' => $rule['co_count'],
                    ],
                ];
            }
        }

        if ($here['lat'] === null || $here['lng'] === null) {
            $listing = \App\Models\SouvenirCenter::publiclyVisible()->availableForTrip()->orderByDesc('rating')->first();

            return $listing ? [...$candidates, ['listing' => $listing, 'rule' => null]] : $candidates;
        }

        $listing = \App\Models\SouvenirCenter::publiclyVisible()
            ->availableForTrip()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->sortBy(fn ($shop) => $this->haversineKm($here['lat'], $here['lng'], (float) $shop->latitude, (float) $shop->longitude))
            ->first();

        return $listing ? [...$candidates, ['listing' => $listing, 'rule' => null]] : $candidates;
    }

    /** Rounds a clock time up to the next quarter hour, so a day reads in real times. */
    private function toQuarterHour(Carbon $time): Carbon
    {
        $rounded = $time->copy()->second(0);
        $remainder = $rounded->minute % 15;

        return $remainder === 0 ? $rounded : $rounded->addMinutes(15 - $remainder);
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
