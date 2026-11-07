<?php

namespace App\Services\Recommendation;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Restaurant;
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

    /**
     * No day's first journey starts before this. A traveller who lands earlier is not sent sightseeing
     * straight off the plane (a 4:00 AM arrival once got a beach visit at 5:30 AM); the time until
     * then is shown as free time. Public because the planning form quotes it.
     */
    public const EARLIEST_DEPARTURE = '08:00';

    /**
     * A planning estimate, not a measured airport, port or transfer time: the minutes between the
     * arrival time the traveller entered and setting off. Public because the planning form quotes it.
     */
    public const ARRIVAL_PREPARATION_MINUTES = 30;

    /** How long a traveller spends at one place, in minutes. */
    private const VISIT_MINUTES = 150;

    /** A shorter visit, used where the day has to fit more in. */
    private const SHORT_VISIT_MINUTES = 90;

    /**
     * Meal windows, as the times a meal may START: breakfast 07:00 to 08:30, lunch 11:30 to 13:00 (planned for 12:00
     * unless the clock gets there first), dinner 18:00 to 19:30. Outside its window a meal is not planned at all (no
     * breakfast on a noon start, no lunch after 1 PM) rather than faked at the wrong hour.
     */
    private const BREAKFAST_LATEST = '08:30';

    private const BREAKFAST_MINUTES = 45;

    /** Lunch may start from here (when the clock gets there with lunch still to eat), ... */
    private const LUNCH_EARLIEST = '11:30';

    /** ... is planned for here when a time has to be chosen (waiting, a stop on the road, a visit cut to make room), ... */
    private const LUNCH_DEFAULT = '12:00';

    /** ... and may not start after here. */
    private const LUNCH_LATEST = '13:00';

    /** Arriving this close to the earliest lunch time or less, eat first (a short wait) rather than squeeze in a short visit. */
    private const EAT_FIRST_IF_WITHIN_MINUTES = 30;

    private const MEAL_MINUTES = 60;

    /** How far from where the traveller is an accredited restaurant may be to be offered as the meal stop. */
    private const MEAL_SEARCH_KM = 10;

    /** Leaving within this long before a window opens, the traveller eats first instead of setting off. */
    private const EAT_FIRST_LEAD_MINUTES = 45;

    /** A visit squeezed in before lunch has to be at least this long, or lunch comes first. */
    private const MIN_VISIT_BEFORE_LUNCH_MINUTES = 60;

    /**
     * A day's sightseeing has to be finished by this hour.
     *
     * Without a cutoff the clock simply kept accumulating: a far-flung second
     * stop with a four-hour drive produced souvenir shopping at 1:45 AM and a
     * departure at 3:30 AM. A stop that cannot finish by the cutoff is pushed
     * to the next day instead, and a plan with fewer stops is worth more than
     * one that is impossible. Public because the planning form quotes it.
     */
    public const LAST_ACTIVITY_END = '18:00';

    /**
     * How long a day may spend on the road, on the slow end of the travel estimates, by how far the traveller
     * said they are willing to go. The cutoff above only stops a day from running late; it does not stop one
     * from being mostly driving, which is how a two-day trip ended up with three legs of one to three hours
     * each on the same day.
     *
     * The count includes the journey back to where the day ends (the accommodation, or the departure point
     * on the last day). Even a day's first stop may not push total daily driving above MAX_DAILY_DRIVE_MINUTES.
     */
    public const DRIVE_BUDGET_MINUTES = ['near' => 210, 'moderate' => 300, 'far' => 420];

    /** Used when the traveller's distance preference is missing or unknown. */
    public const DEFAULT_DRIVE_BUDGET_MINUTES = 300;

    /** Hard ceiling on total daily road time (including the first stop and return leg to accommodation or origin). */
    public const MAX_DAILY_DRIVE_MINUTES = 480;

    /**
     * The souvenir stop is a short extra at the end of the trip, not a second excursion: it has to be near
     * the last stop, and the visit has to be over by this hour (shops list no hours of their own).
     */
    private const SOUVENIR_MAX_KM = 20;

    private const SOUVENIR_LATEST_END = '19:00';

    /**
     * On a Remote trip the journey home is already long, so a last-day stop (or the souvenir stop after it) is only
     * added when the traveller is still back before this hour once it, and the dinner break a late journey needs, are counted.
     */
    public const LATEST_ARRIVAL_HOME = '20:00';

    private const SOUVENIR_LATEST_ARRIVAL_HOME = self::LATEST_ARRIVAL_HOME;

    /**
     * How long the traveller may wait outside a place for it to open before the stop is put off until
     * later in the day. A place that opens at 1 pm is not a morning stop; it goes after lunch.
     */
    private const MAX_WAIT_FOR_OPENING_MINUTES = 60;

    /** How many of the next stops are considered when the first one cannot be done right now. */
    private const LOOKAHEAD = 3;

    private const DINNER = '18:30';

    /** Dinner may start from here (the window is 18:00 to 19:30; 18:30 is the usual hour, DINNER above). */
    private const DINNER_EARLIEST = '18:00';

    /** Arriving at the accommodation after this (the end of the dinner window), dinner is not planned: only the late check-in. */
    private const LATEST_DINNER_START = '19:30';

    private const OVERNIGHT = '20:00';

    /**
     * Back at the accommodation with at least this long before dinner, the gap is shown as free time. Shorter
     * than this is only the slack left by the quarter-hour rounding and the departure buffer. Dinner stays at its
     * fixed hour either way: this row only says what the time before it is.
     */
    private const MIN_FREE_TIME_BEFORE_DINNER_MINUTES = 30;

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
     * The departure the traveller gave, read once per build(): null when no departure time was given.
     * 'place' is null when a time was given without a located departure point (nothing is planned to it, and the
     * departure row says so); 'deadline' is the latest time to be AT the departure point (departure time minus the
     * stated allowance); 'at' is the departure time itself.
     *
     * @var array{at: Carbon, deadline: Carbon, buffer: int, place: array|null}|null
     */
    private ?array $departure = null;

    /** The same departure while the LAST day is being planned (and a located point exists); null on every other day. */
    private ?array $finalDayDeparture = null;

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
        // One stay for every night (the old form: ['listing' => ..., 'rule' => ...]) or a stay per night
        // ([night => ['listing' => ..., 'rule' => ...]]).
        $stayByNight = $this->stayByNight($stay, $dayCapacities);
        $sortOrder = 0;
        $this->lastStart = $this->pastMidnight = [];
        $this->attempts = [];
        $this->planRejection = null;
        $this->failedNotes = [];
        $this->noteFailure = false;
        // Every journey estimate this build makes is at the pace of how the traveller said they will get around.
        $this->transport = $preference->transport_pref;
        $this->remoteTrip = $preference->distance_pref === 'far';
        $this->softDriveBudget = $this->driveBudget($preference);
        $this->pendingActivityNote = null;
        $this->mealRestaurants = null;
        $lastDay = array_key_last($dayCapacities);

        /*
         * A queue, not a fixed per-day slice. A stop that cannot be reached and
         * visited before the day's cutoff stays at the front of the queue and
         * is tried again tomorrow, rather than being crammed into tonight. That
         * is what stops a distant stop dragging the day past midnight.
         */
        $queue = $sequence;
        $tripStart = $preference->travelWindow()[0];
        $this->departure = $this->departurePlan($preference);
        $this->finalDayDeparture = null;
        if ($this->departure !== null && $this->departure['place'] !== null && $lastDay > 1) {
            $queue = $this->queueForDeparture($queue, $stayByNight, $lastDay, $origin, $tripStart->copy()->addDays($lastDay - 1));
        }

        // Where the traveller physically is. Starts at their baseline and moves
        // with them, which is what makes each journey estimate honest rather
        // than always measured from the same point.
        $here = $origin;
        $lastDestination = null;

        foreach ($dayCapacities as $dayNumber => $slots) {
            $isLastDay = $dayNumber === $lastDay;
            // The calendar date of this day, so a place that is closed on this weekday is not scheduled on it.
            $date = $tripStart->copy()->addDays($dayNumber - 1);
            $capacity = count($slots);

            // The stay of tonight, and of last night (where this morning starts: breakfast, and the first journey).
            $accommodation = $stayByNight[$dayNumber]['listing'] ?? null;
            $stayRule = $stayByNight[$dayNumber]['rule'] ?? null;
            $lastNight = $stayByNight[$dayNumber - 1]['listing'] ?? null;

            $this->finalDayDeparture = $isLastDay && $this->departure !== null && $this->departure['place'] !== null ? $this->departure : null;
            $startPlace = $dayNumber === 1 ? $origin : ($lastNight ? $this->place($lastNight) : $here);
            $clock = $this->openDay($itinerary, $dayNumber, $sortOrder, $preference, $origin, $lastNight, $date, $this->finalDayDeparture !== null ? $this->latestTransferLeave($startPlace) : null);
            $lunchTaken = false;
            $scheduled = 0;

            // Road time so far today, and where the day has to end (for the return journey).
            $driven = 0;
            $dayEnd = $isLastDay ? ($this->finalDayDeparture['place'] ?? $origin) : ($accommodation ? $this->place($accommodation) : null);

            $shifted = false;

            while ($scheduled < $capacity && $queue !== []) {
                /*
                 * Which stop next? Normally the first in the queue (the route is already ordered), but when
                 * the first cannot be done right now -- it opens in the afternoon, or it would mean too much
                 * driving -- the next few are tried instead, and the first is left for later in the day.
                 */
                $choice = null;
                $waiting = null;
                $overlong = null;
                // The last day, and the arrival day while nothing is placed yet, look past the first few: the best
                // stop that can actually be reached and visited today is promoted to the front instead of the day
                // staying empty behind stops that are too far to reach.
                $widen = ($this->remoteTrip && ($isLastDay || ($dayNumber === 1 && $scheduled === 0))) || $this->finalDayDeparture !== null;
                $lookahead = $widen ? array_keys($queue) : array_slice(array_keys($queue), 0, self::LOOKAHEAD);

                foreach ($lookahead as $pos => $index) {
                    if ($pos >= self::LOOKAHEAD && $waiting !== null) {
                        break;
                    }
                    $destination = $queue[$index]['row']['destination'];
                    $there = $this->place($destination);

                    // The last stop of the final day is trimmed so there is time to
                    // shop, eat and still reach the departure point.
                    $visitMinutes = ($isLastDay && ($scheduled === $capacity - 1 || count($queue) === 1))
                        ? self::SHORT_VISIT_MINUTES
                        : self::VISIT_MINUTES;

                    $visit = $this->planVisit($clock, $here, $there, $visitMinutes, $lunchTaken, $destination, $date);

                    if ($visit === null) {
                        $this->note($destination->id, $dayNumber, (string) $this->planRejection);

                        continue;
                    }

                    // Too much driving for one day: not this stop, today.
                    $leg = $this->estimateLeg($here, $there)['max_minutes'];
                    $back = $dayEnd ? $this->estimateLeg($there, $dayEnd)['max_minutes'] : 0;

                    if ($scheduled > 0 && $driven + $leg + $back > $this->driveBudget($preference)) {
                        $this->note($destination->id, $dayNumber, 'drive_budget_reached', $driven + $leg + $back, $this->driveBudget($preference));

                        continue;
                    }

                    // On a Remote trip's last day, even the first stop is skipped when the return home (dinner included)
                    // would reach the starting point at or after LATEST_ARRIVAL_HOME.
                    if ($isLastDay && $this->remoteTrip && $this->projectVisitHomeArrival($visit, $there, $origin)->greaterThanOrEqualTo(Carbon::parse(self::LATEST_ARRIVAL_HOME))) {
                        $this->note($destination->id, $dayNumber, 'would_return_after_2000');

                        continue;
                    }

                    // A departure time was given: the stop, the meal it brings and the journey on to the departure point
                    // have to fit before the allowance starts. Decided here, while the day is being built.
                    if ($this->finalDayDeparture !== null && $this->projectDepartureArrival($visit, $there)->greaterThan($this->finalDayDeparture['deadline'])) {
                        // A visit that runs into the lunch window brings a lunch with it. Ending it before lunch is due
                        // may still make the departure; if it does not, the stop cannot be done today.
                        $shorter = $this->visitEndingBeforeLunch($visit, $clock, $here, $there, $lunchTaken, $destination, $date);

                        if ($shorter === null) {
                            $this->note($destination->id, $dayNumber, 'would_miss_departure');

                            continue;
                        }

                        $visit = $shorter;
                    }

                    // One driving ceiling for every trip, first stop included. A stop that alone is over it is not dropped: it is
                    // kept as a last resort for a day with nothing else to offer, and then placed alone with a warning.
                    if ($scheduled === 0 && $driven + $leg + $back > self::MAX_DAILY_DRIVE_MINUTES) {
                        $overlong ??= compact('index', 'destination', 'there', 'visit', 'leg') + ['solo_minutes' => $leg + $back];

                        continue;
                    }

                    $candidate = compact('index', 'destination', 'there', 'visit', 'leg');

                    if ($visit['wait'] <= self::MAX_WAIT_FOR_OPENING_MINUTES) {
                        $choice = $candidate;

                        break;
                    }

                    $waiting ??= $candidate;
                }

                if ($choice === null && $waiting === null && $overlong !== null) {
                    $choice = $overlong;
                } elseif ($overlong !== null) {
                    // Something else fills the day: the over-ceiling stop waits, and says why it was passed over today.
                    $this->note($overlong['destination']->id, $dayNumber, 'drive_budget_reached', $overlong['solo_minutes'], self::MAX_DAILY_DRIVE_MINUTES);
                }

                // Nothing is done yet today and the only stop on offer opens later: set off later, so the
                // traveller arrives as it opens instead of standing outside for hours.
                if ($choice === null && $waiting !== null && $scheduled === 0) {
                    if (! $shifted) {
                        $shifted = true;
                        $depart = $this->toQuarterHour($waiting['visit']['starts_at']->copy()->subMinutes($waiting['leg']));

                        if ($depart->greaterThan($clock)) {
                            $clock = $depart;
                        }

                        continue;
                    }

                    $choice = $waiting;
                }

                if ($choice === null) {
                    // Nothing was done today, so the first stop is not just late for today -- if it cannot be
                    // done even from a fresh morning (it has closed before anyone could get there, or it is
                    // too far to visit in a day) it never will be. Drop it, so it cannot sit at the front of
                    // the queue and keep every stop behind it from being scheduled.
                    if ($scheduled === 0) {
                        $front = $queue[array_key_first($queue)]['row']['destination'];
                        $freshMorning = Carbon::parse(self::EARLIEST_DEPARTURE);

                        if ($this->planVisit($freshMorning, $here, $this->place($front), self::VISIT_MINUTES, false, $front, $date) === null) {
                            $freshRejection = (string) $this->planRejection;

                            // Closed only on today's weekday: keep it for a later day instead of dropping it.
                            $closedToday = OpeningHours::windowsOn($front->hours ?? null, $date) === [];

                            if ($closedToday && ! $isLastDay && $this->planVisit($freshMorning, $here, $this->place($front), self::VISIT_MINUTES, false, $front, $date->copy()->addDay()) !== null) {
                                break;
                            }

                            $this->note($front->id, $dayNumber, $freshRejection, null, null, true);
                            array_shift($queue);

                            continue;
                        }
                    }

                    break;
                }

                ['index' => $index, 'destination' => $destination, 'there' => $there, 'visit' => $visit, 'leg' => $leg] = $choice;

                array_splice($queue, $index, 1);

                $soloDay = isset($choice['solo_minutes']);
                if ($soloDay) {
                    $hours = number_format($choice['solo_minutes'] / 60, 1);
                    $this->pendingActivityNote = "About {$hours} hours of driving there and back for this one stop, more than a day should hold, so it has a day of its own. Consider staying closer, or spending a night nearby.";
                }

                // The journey, with the meal that falls before it, on the way, or on arrival (see planLeg).
                $plan = $visit['plan'];
                $clock = $this->commitLeg($itinerary, $dayNumber, $sortOrder, $plan, $here, $there, $destination, $date);
                $lunchTaken = $plan['lunchTaken'];
                $driven += $leg;
                $here = $there;

                // A place that is not open yet on arrival: the visit starts when it opens.
                if ($visit['starts_at'] !== null && $visit['starts_at']->greaterThan($clock)) {
                    $clock = $visit['starts_at']->copy();
                }

                $clock = $this->addActivity($itinerary, $dayNumber, $sortOrder, $clock, $destination, $visit['minutes']);
                $lastDestination = $destination;
                $scheduled++;

                // A stop that is a day's whole driving leaves no room for another.
                if ($soloDay) {
                    $capacity = $scheduled;
                }

                if (! $lunchTaken && $this->lunchIsDue($clock)) {
                    $clock = $this->addMeal($itinerary, $dayNumber, $sortOrder, $clock, 'Lunch', $there, $destination, $date, 'after');
                    $lunchTaken = true;
                }
            }

            // The day took as many stops as it holds, and the queue still had some: they wait for the next day.
            if ($scheduled >= $capacity) {
                $this->noteLeftovers($queue, $dayNumber, $capacity);
            }

            if ($isLastDay && $this->finalDayDeparture !== null) {
                $this->closeDepartureDay($itinerary, $dayNumber, $sortOrder, $clock, $here, $lunchTaken, $lastDestination, $date, $scheduled > 0, $driven);
            } elseif ($isLastDay) {
                $this->closeTripDay($itinerary, $dayNumber, $sortOrder, $clock, $here, $origin, $lunchTaken, $lastDestination, $date, $driven);
            } else {
                $this->closeOvernightDay($itinerary, $dayNumber, $sortOrder, $clock, $here, $accommodation, $stayRule, $lastNight, $date);
                $here = $accommodation ? $this->place($accommodation) : $here;
            }
        }

        $this->finalDayDeparture = null;
        $this->storeAttempts($sequence, $stayByNight);
    }

    /**
     * Why a stop was not placed, as the build decided it. These are records of decisions the build already makes; reading
     * them changes nothing about what is built. One entry per day and cause, per destination:
     * ['day' => int, 'cause' => string, 'minutes' => int|null, 'limit' => int|null, 'fresh' => bool].
     * Causes: would_end_after_cutoff, would_return_after_2000, closed_that_day, outside_opening_hours, drive_budget_reached, day_full.
     * 'fresh' means the stop could not be done even starting that day fresh (so it was set aside).
     *
     * @var array<int, array<int, array<string, mixed>>>
     */
    private array $attempts = [];

    /** @var array<string, array<int, array<int, array<string, mixed>>>> the attempts of each build, by what it was built from */
    private array $recorded = [];

    /** Why the last planVisit() returned null (set just before it does). */
    private ?string $planRejection = null;

    /** @var array<int|string, true> destinations whose note could not be taken in this build (they get the neutral wording) */
    private array $failedNotes = [];

    /** Set when a failure could not be tied to one destination: this build's notes are then all left out. */
    private bool $noteFailure = false;

    /**
     * Takes one note for the page. Taking a note never decides anything and must never stop a build: if it fails for any
     * reason the place simply has no recorded reason, and the page says only that it was not added to the plan.
     */
    private function note(mixed $destinationId, mixed $day, mixed $cause, mixed $minutes = null, mixed $limit = null, mixed $fresh = false): void
    {
        // Untyped on purpose: a bad value must be caught below, not fail as the call is made.
        try {
            $this->recordAttempt($destinationId, $day, $cause, $minutes, $limit, $fresh);
        } catch (\Throwable $e) {
            if (is_int($destinationId) || is_string($destinationId)) {
                $this->failedNotes[$destinationId] = true;
            } else {
                $this->noteFailure = true;
            }
            $this->reportNoteFailure($e);
        }
    }

    /** One note for every stop still waiting when a day is full (or has no sightseeing time at all). */
    private function noteLeftovers(array $queue, int $day, int $capacity): void
    {
        foreach ($queue as $leftover) {
            try {
                $id = $leftover['row']['destination']->id;
            } catch (\Throwable $e) {
                $this->noteFailure = true;
                $this->reportNoteFailure($e);

                continue;
            }

            $this->note($id, $day, $capacity === 0 ? 'no_sightseeing_time_that_day' : 'day_full', null, $capacity);
        }
    }

    /** Keeps this build's notes for later, under what the build was made from. A failure leaves the build without notes. */
    private function storeAttempts(array $sequence, array $stayByNight): void
    {
        try {
            $kept = $this->noteFailure ? [] : array_diff_key($this->attempts, $this->failedNotes);
            $this->recorded[$this->attemptsKey($sequence, $stayByNight)] = $kept;
        } catch (\Throwable $e) {
            $this->reportNoteFailure($e);
        }
    }

    private function reportNoteFailure(\Throwable $e): void
    {
        try {
            report($e);
        } catch (\Throwable) {
            // Even reporting is not allowed to stop a build.
        }
    }

    protected function recordAttempt(int|string $destinationId, int $day, string $cause, ?int $minutes = null, ?int $limit = null, bool $fresh = false): void
    {
        if ($cause === '') {
            return;
        }

        foreach ($this->attempts[$destinationId] ?? [] as $i => $known) {
            if ($known['day'] === $day && $known['cause'] === $cause) {
                $this->attempts[$destinationId][$i] = ['day' => $day, 'cause' => $cause, 'minutes' => $minutes, 'limit' => $limit, 'fresh' => $known['fresh'] || $fresh];

                return;
            }
        }

        $this->attempts[$destinationId][] = ['day' => $day, 'cause' => $cause, 'minutes' => $minutes, 'limit' => $limit, 'fresh' => $fresh];
    }

    /** What a build is identified by: the stops in order and the stay of each night. Preference, dates and origin are fixed within one trip. */
    protected function attemptsKey(array $sequence, array $stayByNight): string
    {
        return md5(json_encode([
            array_map(fn ($entry) => $entry['row']['destination']->id, array_values($sequence)),
            array_map(fn ($night) => ($night['listing'] ?? null)?->id, $stayByNight),
        ]));
    }

    /** Forget every recorded build; called when a new trip starts. */
    public function forgetRecordedAttempts(): void
    {
        $this->recorded = [];
        $this->attempts = [];
    }

    /**
     * The recorded attempts of the build made from this sequence and these stays, keyed by destination id; null when no such
     * build was recorded, or when the lookup itself failed (the page then gives every omission the neutral wording).
     *
     * @param  array<int, array{row: array, distance_km: float|null}>  $sequence
     * @param  array<int, array<int, string>>  $dayCapacities
     * @return array<int, array<int, array<string, mixed>>>|null
     */
    public function recordedAttempts(array $sequence, ?array $stay, array $dayCapacities): ?array
    {
        try {
            return $this->recorded[$this->attemptsKey($sequence, $this->stayByNight($stay, $dayCapacities))] ?? null;
        } catch (\Throwable $e) {
            $this->reportNoteFailure($e);

            return null;
        }
    }

    /**
     * Normalises the stay argument to one entry per night.
     *
     * @param  array<int|string, mixed>|null  $stay
     * @param  array<int, array<int, string>>  $dayCapacities
     * @return array<int, array{listing: Accommodation|null, rule: array|null}>
     */
    private function stayByNight(?array $stay, array $dayCapacities): array
    {
        if ($stay === null) {
            return [];
        }

        // A single stay for the whole trip: the same one every night.
        if (array_key_exists('listing', $stay)) {
            return array_fill_keys(array_keys($dayCapacities), $stay);
        }

        return array_filter($stay, 'is_array');
    }

    /**
     * The allowance for a leg that cannot be measured, at the pace of how the traveller said they get around.
     *
     * UNKNOWN_TRAVEL_MINUTES is what such a leg takes at a car's slowest pace. A slower way of travelling takes
     * proportionally longer over the same unknown road (a jeep, at 15 km/h against a car's 25, needs 75 minutes),
     * so a jeepney day is not planned as if its unmeasured legs were done by car. Rounded to the quarter hour like
     * every other journey time.
     */
    private function unknownLegMinutes(): int
    {
        $carSlowest = TransportMode::slowKmh(TransportMode::PERSONAL_CAR);
        $scaled = self::UNKNOWN_TRAVEL_MINUTES * $carSlowest / TransportMode::slowKmh($this->transport);

        return max(15, (int) (round($scaled / 15) * 15));
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
            $allowance = $this->unknownLegMinutes();

            return [
                'distance_km' => null,
                'min_minutes' => $allowance,
                'max_minutes' => $allowance,
            ];
        }

        return $this->travel->estimate(
            $this->haversineKm($from['lat'], $from['lng'], $to['lat'], $to['lng']),
            $from['region'] ?? null,
            $to['region'] ?? null,
            $this->transport,
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
     * @return array{minutes: int, starts_at: Carbon|null, wait: int, plan: array}|null  null when the stop cannot be done
     *                                                                       today; starts_at and wait are set
     *                                                                       only when the visit has to wait for opening
     */
    private function planVisit(Carbon $clock, array $from, array $to, int $visitMinutes, bool $lunchTaken, $destination, ?Carbon $date = null): ?array
    {
        // The journey and any meal it makes room for. Lunch is only charged when it is actually taken, so a
        // mid-afternoon arrival is not made an hour longer than it is.
        $plan = $this->planLeg($clock, $from, $to, $lunchTaken, $visitMinutes);
        $visitMinutes = $plan['visitMinutes'];
        $start = $this->toQuarterHour($plan['arrival']);

        // The cutoff is the sightseeing hour, or -- on a last day with a departure time -- the latest the visit may end
        // and still leave time for the journey to the departure point, whichever is earlier.
        $cutoff = Carbon::parse(self::LAST_ACTIVITY_END);
        $byDeparture = false;
        if ($this->finalDayDeparture !== null) {
            $latest = $this->latestFinish($to);
            if ($latest->lessThan($cutoff)) {
                $cutoff = $latest;
                $byDeparture = true;
            }
        }

        // The sightseeing cutoff. A visit that does not fit before it in full is shortened to what does fit, as long
        // as that is still a proper visit (at least SHORT_VISIT_MINUTES, or the whole visit if that is shorter).
        // Without this, arriving at 15:45 with 135 minutes left dropped a place 15 km away and left the arrival day
        // with nothing but the drive to the stay.
        if ($start->copy()->addMinutes($visitMinutes)->greaterThan($cutoff)) {
            $fit = $this->minutesBetween($start, $cutoff);

            if ($fit < min($visitMinutes, self::SHORT_VISIT_MINUTES)) {
                $this->planRejection = $byDeparture ? 'would_miss_departure' : 'would_end_after_cutoff';

                return null;
            }

            $visitMinutes = $fit;
        }

        $windows = $date
            ? OpeningHours::windowsOn($destination->hours ?? null, $date)
            : OpeningHours::windows($destination->hours ?? null);

        // Closed all day on this weekday ("Closed Mondays", "Mon–Sat ..." on a Sunday).
        if ($windows === []) {
            $this->planRejection = 'closed_that_day';

            return null;
        }

        if ($windows === null) {
            return ['minutes' => $visitMinutes, 'starts_at' => null, 'wait' => 0, 'plan' => $plan];
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

            // Waiting for a place to open pushes the visit later: it still has to end by the cutoff (the 18:00 sightseeing hour,
            // or the departure cutoff), however long the wait was.
            $minutes = min($minutes, ($cutoff->hour * 60 + $cutoff->minute) - $begin);

            if ($minutes >= $enough) {
                return [
                    'minutes' => $minutes,
                    'starts_at' => $begin > $startMinute ? $midnight->copy()->addMinutes($begin) : null,
                    'wait' => $begin - $startMinute,
                    'plan' => $plan,
                ];
            }
        }

        $this->planRejection = 'outside_opening_hours';

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
        ?Carbon $date = null,
        ?Carbon $mustLeaveBy = null,
    ): Carbon {
        if ($dayNumber === 1) {
            $arrival = $this->arrivalTime($preference);
            $earliest = Carbon::parse(self::EARLIEST_DEPARTURE);

            // The starting point is stated here, apart from the title: it is where travel times are measured
            // from, not necessarily where the traveller arrives. A blank arrival time is still planned as
            // an arrival at the earliest departure, and says so.
            $note = 'Starting point for travel times: '.$origin['label'].'.';
            if (blank($preference->arrival_time)) {
                $note = 'No arrival time provided; assuming '.$arrival->format('g:i A').'. '.$note;
            }

            $this->row($itinerary, $dayNumber, $sortOrder, [
                'kind' => 'baseline',
                'title' => 'Planned arrival',
                'starts_at' => $arrival,
                'note' => $note,
            ]);

            // departure = the later of (arrival + preparation) and the earliest departure.
            $ready = $arrival->copy()->addMinutes(self::ARRIVAL_PREPARATION_MINUTES);

            // Setting off inside the breakfast window: eat first, near the starting point, then go. Only for an
            // arrival time the traveller gave; an assumed one does not invent a meal.
            if (filled($preference->arrival_time)
                && $ready->greaterThanOrEqualTo(Carbon::parse(self::BREAKFAST))
                && $ready->lessThanOrEqualTo(Carbon::parse(self::BREAKFAST_LATEST))) {
                $end = $this->addMeal($itinerary, $dayNumber, $sortOrder, $ready, 'Breakfast', $origin, null, $date, 'start');

                return $end->lessThan($earliest) ? $earliest : $end;
            }

            if ($ready->lessThan($earliest)) {
                // Neutral on purpose: nothing about a room, a meal or any service is assumed or arranged.
                $this->row($itinerary, $dayNumber, $sortOrder, [
                    'kind' => 'free',
                    'title' => 'Free time until '.$earliest->format('g:i A'),
                    'starts_at' => $arrival,
                    'ends_at' => $earliest,
                    'note' => 'You might rest or eat; nothing is arranged.',
                ]);

                return $earliest;
            }

            return $ready;
        }

        // The journey to the departure point has to start before breakfast could be over: no breakfast is planned,
        // and the transfer row says when to leave.
        if ($mustLeaveBy !== null && $mustLeaveBy->lessThan(Carbon::parse(self::BREAKFAST)->addMinutes(self::BREAKFAST_MINUTES))) {
            return Carbon::parse(self::EARLIEST_DEPARTURE);
        }

        // At the stay when there is one; otherwise the traveller's own choice, never a made-up "your accommodation".
        $this->row($itinerary, $dayNumber, $sortOrder, array_filter([
            'kind' => 'meal',
            'title' => $accommodation ? 'Breakfast — '.$accommodation->name : 'Breakfast (your choice)',
            'starts_at' => Carbon::parse(self::BREAKFAST),
            'accommodation_id' => $accommodation?->id,
            'note' => $accommodation ? null : 'No stay is planned, so breakfast is up to you.',
        ], fn ($v) => $v !== null));

        return Carbon::parse(self::EARLIEST_DEPARTURE);
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
     * One journey, with the meal that belongs to it.
     *
     * Lunch is a meal STOP, never a destination. Where it falls depends on where the journey sits against the
     * lunch window (it may START between 12:00 and 1:00 PM):
     *   - setting off inside the window: lunch first, near where the traveller is;
     *   - setting off up to 45 minutes before it opens, and the journey would cross it: lunch first, at 12:00;
     *   - a journey that spans the whole window: split in two, with lunch on the way;
     *   - arriving inside the window: lunch on arrival, near the place;
     *   - arriving before the window with too little time for a worthwhile visit before it: lunch first;
     *     with time for one, the visit is cut short to end when the window opens.
     * A journey that starts after the window, or that ends before it opens with no visit running past it, has no
     * lunch in it. planVisit() and commitLeg() both read this one plan, so what is checked is what is written.
     *
     * @return array{steps: list<array<string, mixed>>, arrival: Carbon, lunchTaken: bool, visitMinutes: int}
     */
    private function planLeg(Carbon $clock, array $from, array $to, bool $lunchTaken, int $visitMinutes): array
    {
        $estimate = $this->estimateLeg($from, $to);
        $total = $estimate['max_minutes'];
        $earliest = Carbon::parse(self::LUNCH_EARLIEST);
        $default = Carbon::parse(self::LUNCH_DEFAULT);
        $latest = Carbon::parse(self::LUNCH_LATEST);
        $steps = [];
        $t = $clock->copy();

        $travel = function (int $minutes) use (&$steps, &$t, $estimate, $total) {
            if ($minutes <= 0) {
                return;
            }

            $steps[] = ['type' => 'travel', 'start' => $t->copy(), 'minutes' => $minutes, 'share' => $total > 0 ? $minutes / $total : 1.0, 'estimate' => $estimate];
            $t = $t->copy()->addMinutes($minutes);
        };

        $meal = function (Carbon $at, string $phase, array $near) use (&$steps, &$t) {
            $start = $this->toQuarterHour($at);
            $steps[] = ['type' => 'meal', 'at' => $start, 'phase' => $phase, 'near' => $near];
            $t = $start->copy()->addMinutes(self::MEAL_MINUTES);
        };

        $end = $t->copy()->addMinutes($total);

        if (! $lunchTaken && $t->greaterThanOrEqualTo($earliest) && $t->lessThanOrEqualTo($latest)) {
            $meal($t, 'start', $from);
            $lunchTaken = true;
            $travel($total);
        } elseif (! $lunchTaken && $t->lessThan($earliest) && $end->greaterThan($earliest) && $this->minutesBetween($t, $earliest) <= self::EAT_FIRST_LEAD_MINUTES) {
            $meal($earliest, 'start', $from);
            $lunchTaken = true;
            $travel($total);
        } elseif (! $lunchTaken && $t->lessThan($earliest) && $end->greaterThan($latest)) {
            $before = $this->minutesBetween($t, $default);
            $travel($before);
            $meal($default, 'road', $this->pointAt($from, $to, $total > 0 ? $before / $total : 0.5));
            $lunchTaken = true;
            $travel($total - $before);
        } else {
            $travel($total);

            if (! $lunchTaken && $t->greaterThanOrEqualTo($earliest) && $t->lessThanOrEqualTo($latest)) {
                $meal($t, 'arrival', $to);
                $lunchTaken = true;
            }
        }

        // Arriving before lunch with a visit that would run past it. Within 30 minutes of the earliest lunch time
        // (arriving 11:00 to 11:30), eat first and give the place its full visit afterwards: a short wait beats a
        // one-hour visit after a long drive. Earlier than that, end the visit at the planned lunch time (12:00).
        if (! $lunchTaken && $t->lessThan($earliest) && $t->copy()->addMinutes($visitMinutes)->greaterThan($latest)) {
            if ($this->minutesBetween($t, $earliest) <= self::EAT_FIRST_IF_WITHIN_MINUTES) {
                $meal($earliest, 'arrival', $to);
                $lunchTaken = true;
            } else {
                $visitMinutes = $this->minutesBetween($t, $default);
            }
        }

        return ['steps' => $steps, 'arrival' => $t, 'lunchTaken' => $lunchTaken, 'visitMinutes' => $visitMinutes];
    }

    /**
     * The journey that ends a day (the way home on the last day, the drive back to the stay on the others), with
     * dinner where the journey would otherwise carry the traveller through the whole dinner window (18:00 to 19:30)
     * and arrive after it: dinner first when setting off inside the window, a stop on the way when the journey
     * spans it. A journey that arrives inside the window, or that is over before it, gets no dinner row: the stay's
     * own dinner, or home, covers it. Same shape as planLeg().
     *
     * @return array{steps: list<array<string, mixed>>, arrival: Carbon, lunchTaken: bool, visitMinutes: int}
     */
    private function planDinnerLeg(Carbon $clock, array $from, array $to): array
    {
        $estimate = $this->estimateLeg($from, $to);
        $total = $estimate['max_minutes'];
        $earliest = Carbon::parse(self::DINNER_EARLIEST);
        $default = Carbon::parse(self::DINNER);
        $latest = Carbon::parse(self::LATEST_DINNER_START);
        $steps = [];
        $t = $clock->copy();
        $end = $t->copy()->addMinutes($total);

        $travel = function (int $minutes) use (&$steps, &$t, $estimate, $total) {
            if ($minutes <= 0) {
                return;
            }

            $steps[] = ['type' => 'travel', 'start' => $t->copy(), 'minutes' => $minutes, 'share' => $total > 0 ? $minutes / $total : 1.0, 'estimate' => $estimate];
            $t = $t->copy()->addMinutes($minutes);
        };
        $meal = function (Carbon $at, string $phase, array $near) use (&$steps, &$t) {
            $start = $this->toQuarterHour($at);
            $steps[] = ['type' => 'meal', 'meal' => 'Dinner', 'at' => $start, 'phase' => $phase, 'near' => $near];
            $t = $start->copy()->addMinutes(self::MEAL_MINUTES);
        };

        if ($end->greaterThan($latest) && $t->lessThanOrEqualTo($latest)) {
            if ($t->greaterThanOrEqualTo($earliest)) {
                $meal($t, 'start', $from);
                $travel($total);
            } elseif ($this->minutesBetween($t, $earliest) <= self::EAT_FIRST_LEAD_MINUTES) {
                $meal($earliest, 'start', $from);
                $travel($total);
            } else {
                $before = $this->minutesBetween($t, $default);
                $travel($before);
                $meal($default, 'road', $this->pointAt($from, $to, $total > 0 ? $before / $total : 0.5));
                $travel($total - $before);
            }
        } else {
            $travel($total);
        }

        return ['steps' => $steps, 'arrival' => $t, 'lunchTaken' => true, 'visitMinutes' => 0];
    }

    /** Writes the rows of a planLeg() plan and returns the clock when they are done. */
    private function commitLeg(Itinerary $itinerary, int $dayNumber, int &$sortOrder, array $plan, array $from, array $to, $destination, ?Carbon $date): Carbon
    {
        $travelSteps = count(array_filter($plan['steps'], fn (array $step) => $step['type'] === 'travel'));
        $seen = 0;

        foreach ($plan['steps'] as $step) {
            if ($step['type'] === 'meal') {
                $onSite = $step['phase'] === 'arrival' ? $destination : null;
                $this->addMeal($itinerary, $dayNumber, $sortOrder, $step['at'], $step['meal'] ?? 'Lunch', $step['near'], $onSite, $date, $step['phase']);

                continue;
            }

            $seen++;
            $estimate = $step['estimate'];
            $share = $step['share'];
            $whole = $share >= 0.999;

            $this->row($itinerary, $dayNumber, $sortOrder, [
                'kind' => 'travel',
                'title' => ($seen === $travelSteps ? 'Travel to ' : 'Travel toward ').$to['label'],
                'starts_at' => $step['start']->copy(),
                'distance_km' => $estimate['distance_km'] === null ? null : ($whole ? $estimate['distance_km'] : round($estimate['distance_km'] * $share, 1)),
                'travel_min_minutes' => $whole ? $estimate['min_minutes'] : (int) round($estimate['min_minutes'] * $share),
                'travel_max_minutes' => $step['minutes'],
            ]);
        }

        return $plan['arrival']->copy();
    }

    /** Whole minutes from $a to $b (negative when $b is earlier). */
    private function minutesBetween(Carbon $a, Carbon $b): int
    {
        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 60);
    }

    /** The point a fraction of the way from one place to another, for a meal on the road. */
    private function pointAt(array $from, array $to, float $fraction): array
    {
        if (! $this->measurable($from, $to)) {
            return $from;
        }

        return [
            'lat' => $from['lat'] + ($to['lat'] - $from['lat']) * $fraction,
            'lng' => $from['lng'] + ($to['lng'] - $from['lng']) * $fraction,
            'label' => 'the road',
            'region' => null,
        ];
    }

    /** @var \Illuminate\Support\Collection<int, Restaurant>|null  accredited, mapped restaurants, loaded once per build */
    private $mealRestaurants = null;

    /**
     * A meal row. It is always somewhere to eat, never a sightseeing stop: the destination's own restaurant when
     * its amenities include one, else an accredited restaurant within MEAL_SEARCH_KM of where the traveller is
     * (same region, open at that hour), else "(your choice)" with no listing attached.
     *
     * @param  array{lat: float|null, lng: float|null, label: string, region?: string|null}  $near
     * @param  string  $phase  'start' (before setting off), 'road' (on the way), 'arrival' or 'after' (at a place)
     * @return Carbon the clock when the meal is over
     */
    private function addMeal(Itinerary $itinerary, int $dayNumber, int &$sortOrder, Carbon $at, string $meal, array $near, ?Destination $onSite, ?Carbon $date, string $phase): Carbon
    {
        $start = $this->toQuarterHour($at);
        $minutes = $meal === 'Breakfast' ? self::BREAKFAST_MINUTES : self::MEAL_MINUTES;
        $stop = $this->mealStop($start, $minutes, $near, $onSite, $date);

        $attributes = ['kind' => 'meal', 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes($minutes)];

        if ($stop['kind'] === 'onsite') {
            $attributes += ['title' => $meal.' — '.$onSite->name.' Restaurant', 'destination_id' => $onSite->id];
        } elseif ($stop['kind'] === 'restaurant') {
            $attributes += [
                'title' => $meal.' — '.$stop['listing']->name.($phase === 'road' ? ' (on the way)' : ''),
                'restaurant_id' => $stop['listing']->id,
            ] + $this->ruleColumns($stop['rule'] ?? null);
        } else {
            $attributes += [
                'title' => $meal.' break'.($phase === 'road' ? ' on the way' : '').' (your choice)',
                'note' => 'No accredited restaurant within '.self::MEAL_SEARCH_KM.' km that is open then. Choose where to eat.',
            ];
        }

        $this->row($itinerary, $dayNumber, $sortOrder, $attributes);

        return $attributes['ends_at']->copy();
    }

    /** @return array{kind: string, listing?: Restaurant, rule?: array|null} */
    private function mealStop(Carbon $start, int $minutes, array $near, ?Destination $onSite, ?Carbon $date): array
    {
        if ($onSite && $this->hasOwnRestaurant($onSite)) {
            return ['kind' => 'onsite'];
        }

        // A restaurant Apriori ties to the place, when it also passes the same distance, region and hours checks.
        if ($onSite && ($suggestion = $this->nearbyRestaurant($onSite)) && $this->mealFits($suggestion['listing'], $start, $minutes, $near, $date)) {
            return ['kind' => 'restaurant', 'listing' => $suggestion['listing'], 'rule' => $suggestion['rule']];
        }

        $best = null;
        $bestKm = null;

        foreach ($this->mealRestaurants() as $restaurant) {
            if (! $this->mealFits($restaurant, $start, $minutes, $near, $date)) {
                continue;
            }

            $km = $this->haversineKm($near['lat'], $near['lng'], (float) $restaurant->latitude, (float) $restaurant->longitude);

            if ($bestKm === null || $km < $bestKm) {
                $best = $restaurant;
                $bestKm = $km;
            }
        }

        return $best ? ['kind' => 'restaurant', 'listing' => $best, 'rule' => null] : ['kind' => 'none'];
    }

    /** Within MEAL_SEARCH_KM, in the same region when both are known, and open for the meal. */
    private function mealFits($restaurant, Carbon $start, int $minutes, array $near, ?Carbon $date): bool
    {
        if ($near['lat'] === null || $near['lng'] === null || $restaurant->latitude === null || $restaurant->longitude === null) {
            return false;
        }

        if ($this->haversineKm($near['lat'], $near['lng'], (float) $restaurant->latitude, (float) $restaurant->longitude) > self::MEAL_SEARCH_KM) {
            return false;
        }

        $region = $near['region'] ?? null;

        if ($region !== null && $restaurant->region?->name !== null && $restaurant->region->name !== $region) {
            return false;
        }

        $windows = $date ? OpeningHours::windowsOn($restaurant->opening_hours, $date) : OpeningHours::windows($restaurant->opening_hours);

        if ($windows === null) {
            return true; // hours that cannot be read put no limit on the meal
        }

        $begin = $start->hour * 60 + $start->minute;

        foreach ($windows as [$open, $close]) {
            if ($begin >= $open && $begin + 30 <= $close) {
                return true;
            }
        }

        return false;
    }

    /** The destination's own restaurant: its amenities say so. */
    private function hasOwnRestaurant(Destination $destination): bool
    {
        $destination->loadMissing('tags');

        return $destination->tags->where('kind', 'amenity')->contains(fn ($tag) => mb_strtolower(trim((string) $tag->value)) === 'restaurant');
    }

    private function mealRestaurants()
    {
        // availableForTrip(): a restaurant that is closed on the trip dates (its own status, or a closing advisory) is never offered.
        return $this->mealRestaurants ??= \App\Support\OfficialOnlyMode::apply(Restaurant::publiclyVisible()->availableForTrip()->with('region'))
            ->whereNotNull('latitude')->whereNotNull('longitude')->orderBy('id')->get();
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
    private function closeOvernightDay(Itinerary $itinerary, int $dayNumber, int &$sortOrder, Carbon $clock, array $here, ?Accommodation $accommodation, ?array $stayRule = null, ?Accommodation $lastNight = null, ?Carbon $date = null): void
    {
        if (! $accommodation) {
            return;
        }

        $arrival = $this->commitLeg(
            $itinerary,
            $dayNumber,
            $sortOrder,
            $this->planDinnerLeg($this->toQuarterHour($clock->copy()->addMinutes(self::DEPARTURE_BUFFER_MINUTES)), $here, $this->place($accommodation)),
            $here,
            $this->place($accommodation),
            null,
            $date,
        );

        // Reaching the accommodation after the latest dinner time (a late flight, a long drive from the airport):
        // there is no dinner to plan, only the check-in. Before this, a 11:30 PM arrival was given dinner at
        // 1:45 AM and an overnight stay at 3:15 AM.
        if ($this->toQuarterHour($arrival)->greaterThan(Carbon::parse(self::LATEST_DINNER_START))) {
            $this->row($itinerary, $dayNumber, $sortOrder, array_merge([
                'kind' => 'overnight',
                'title' => 'Late check-in and overnight stay',
                'starts_at' => $this->toQuarterHour($arrival),
                'accommodation_id' => $accommodation->id,
                'note' => $this->stayChangeNote($lastNight, $accommodation),
            ], $this->ruleColumns($stayRule)));

            return;
        }

        // Dinner is at the usual hour, or when the traveller gets back if the drive ran longer than that.
        $returned = $this->toQuarterHour($arrival);
        $dinner = Carbon::parse(self::DINNER);
        $dinner = $returned->greaterThan($dinner) ? $returned : $dinner;

        // Back well before dinner: say so, instead of leaving an unexplained jump from the return journey to dinner.
        // Neutral on purpose, like the early-arrival free time: no listing is attached, and nothing about a room,
        // a meal or any service is claimed.
        if (($dinner->getTimestamp() - $returned->getTimestamp()) / 60 >= self::MIN_FREE_TIME_BEFORE_DINNER_MINUTES) {
            $this->row($itinerary, $dayNumber, $sortOrder, [
                'kind' => 'free',
                'title' => 'Free time until dinner',
                'starts_at' => $returned,
                'ends_at' => $dinner,
                'note' => 'Nothing is arranged for this time.',
            ]);
        }

        $overnight = Carbon::parse(self::OVERNIGHT);
        $overnight = $dinner->copy()->addMinutes(90)->greaterThan($overnight) ? $dinner->copy()->addMinutes(90) : $overnight;

        $this->row($itinerary, $dayNumber, $sortOrder, [
            'kind' => 'meal',
            'title' => 'Dinner — '.$accommodation->name,
            'starts_at' => $dinner,
            'accommodation_id' => $accommodation->id,
        ]);

        $this->row($itinerary, $dayNumber, $sortOrder, array_merge([
            'kind' => 'overnight',
            'title' => 'Overnight stay',
            'starts_at' => $overnight,
            'accommodation_id' => $accommodation->id,
            'note' => $this->stayChangeNote($lastNight, $accommodation),
        ], $this->ruleColumns($stayRule)));
    }

    /** Says so when tonight's stay is not last night's: the traveller packs up in the morning. */
    private function stayChangeNote(?Accommodation $lastNight, Accommodation $tonight): ?string
    {
        return $lastNight && $lastNight->id !== $tonight->id
            ? "You change stays tonight. Last night you were at {$lastNight->name}; the journeys of the day are planned around where each night is spent."
            : null;
    }

    /** The final day: souvenirs, a last meal if lunch has not happened, then the journey out. */
    private function closeTripDay(Itinerary $itinerary, int $dayNumber, int &$sortOrder, Carbon $clock, array $here, array $origin, bool $lunchTaken, $lastDestination = null, ?Carbon $date = null, int $driven = 0): void
    {
        $suggestion = $this->souvenirStop($here, $lastDestination, $clock);
        $souvenirs = $suggestion['listing'] ?? null;

        if ($souvenirs) {
            // souvenirFits() only looks at the shop; here the whole journey home is counted, dinner break included.
            $shop = $this->place($souvenirs);
            $shopLeg = $this->estimateLeg($here, $shop)['max_minutes'];
            $homeLeg = $this->estimateLeg($shop, $origin)['max_minutes'];

            if ($driven + $shopLeg + $homeLeg > min(self::MAX_DAILY_DRIVE_MINUTES, $this->softDriveBudget)) {
                // The day's driving budget holds on every trip, the shop and the way home included: the shop would take the day past it.
                $souvenirs = null;
            } elseif ($this->remoteTrip) {
                $shopping = $this->toQuarterHour($this->toQuarterHour($clock->copy()->addMinutes(self::DEPARTURE_BUFFER_MINUTES))
                    ->addMinutes($shopLeg))->addMinutes(self::MEAL_MINUTES);
                $home = $this->projectHomeArrival($shopping, $shop, $origin, $lunchTaken);

                if ($home->greaterThanOrEqualTo(Carbon::parse(self::LATEST_ARRIVAL_HOME))) {
                    $souvenirs = null;
                }
            }
        }

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

        // Only inside the lunch window: not at 8 AM, and not at 23:30 for a traveller who lands late.
        if (! $lunchTaken && $this->lunchIsDue($clock)) {
            $clock = $this->addMeal($itinerary, $dayNumber, $sortOrder, $clock, 'Lunch', $here, null, $date, 'after');
        }

        $clock = $this->commitLeg(
            $itinerary,
            $dayNumber,
            $sortOrder,
            $this->planDinnerLeg($this->toQuarterHour($clock->copy()->addMinutes(self::DEPARTURE_BUFFER_MINUTES)), $here, $origin),
            $here,
            $origin,
            null,
            $date,
        );

        $this->row($itinerary, $dayNumber, $sortOrder, array_filter([
            'kind' => 'departure',
            'title' => 'Departure',
            'starts_at' => $this->toQuarterHour($clock),
            // A time was given but no located departure point: say so rather than plan a journey to nowhere.
            'note' => $this->departure !== null && $this->departure['place'] === null
                ? 'You gave a departure time of '.$this->departure['at']->format('g:i A').' but no located departure point, so the journey there is not planned. Add where you leave from to plan it.'
                : null,
        ], fn ($v) => $v !== null));
    }

    /**
     * The departure the traveller gave, or null. The allowance is configuration (itinerary.departure.airport_buffer_minutes)
     * and is stated on the itinerary; the point is the traveller's own choice, never inferred.
     *
     * @return array{at: Carbon, deadline: Carbon, buffer: int, place: array|null}|null
     */
    private function departurePlan(TouristPreference $preference): ?array
    {
        $departure = $preference->departure();
        if ($departure === null) {
            return null;
        }

        $buffer = max(0, (int) config('itinerary.departure.airport_buffer_minutes', 120));
        $at = Carbon::parse($departure['time']);

        return [
            'at' => $at,
            'deadline' => $at->copy()->subMinutes($buffer),
            'buffer' => $buffer,
            'place' => $departure['point'] === null ? null : $departure['point'] + ['region' => null],
        ];
    }

    /** Floors a clock time to the quarter hour (toQuarterHour rounds up). */
    private function floorToQuarterHour(Carbon $time): Carbon
    {
        $floored = $time->copy()->second(0);

        return $floored->subMinutes($floored->minute % 15);
    }

    /**
     * The latest a visit at $from may END so that, after the usual set-off buffer, the journey on to the departure
     * point still arrives by the deadline. The journey is the planner's own slow-end estimate (estimateLeg).
     */
    private function latestFinish(array $from): Carbon
    {
        return $this->latestTransferLeave($from)->subMinutes(self::DEPARTURE_BUFFER_MINUTES);
    }

    /** The latest the journey from $from to the departure point may begin and still arrive by the deadline. */
    private function latestTransferLeave(array $from): Carbon
    {
        $leg = $this->estimateLeg($from, $this->finalDayDeparture['place'])['max_minutes'];

        return $this->floorToQuarterHour($this->finalDayDeparture['deadline']->copy()->subMinutes($leg));
    }

    /** When the traveller would reach the departure point after finishing at $from at $finished (set-off buffer included). */
    private function departureArrival(Carbon $finished, array $from): Carbon
    {
        $setOff = $this->toQuarterHour($finished->copy()->addMinutes(self::DEPARTURE_BUFFER_MINUTES));

        return $setOff->addMinutes($this->estimateLeg($from, $this->finalDayDeparture['place'])['max_minutes']);
    }

    /**
     * When the traveller would reach the departure point if the last day ended with this candidate visit: the steps
     * build() writes (the journey, waiting for opening, the visit, the lunch a visit ending in the lunch window brings),
     * then departureArrival().
     */
    private function projectDepartureArrival(array $visit, array $there): Carbon
    {
        $afterLeg = $visit['plan']['arrival']->copy();
        if ($visit['starts_at'] !== null && $visit['starts_at']->greaterThan($afterLeg)) {
            $afterLeg = $visit['starts_at']->copy();
        }
        $clock = $this->toQuarterHour($afterLeg)->addMinutes($visit['minutes']);

        if (! $visit['plan']['lunchTaken'] && $this->lunchIsDue($clock)) {
            $clock = $this->toQuarterHour($clock)->addMinutes(self::MEAL_MINUTES);
        }

        return $this->departureArrival($clock, $there);
    }

    /**
     * The same stop with a visit that ends before lunch is due, when that makes the departure and is still a proper
     * visit; null otherwise. Only used on a last day with a departure time.
     *
     * @return array{minutes: int, starts_at: Carbon|null, wait: int, plan: array}|null
     */
    private function visitEndingBeforeLunch(array $visit, Carbon $clock, array $here, array $there, bool $lunchTaken, $destination, ?Carbon $date): ?array
    {
        if ($visit['plan']['lunchTaken'] || $lunchTaken) {
            return null;
        }

        $begin = $visit['starts_at'] ?? $this->toQuarterHour($visit['plan']['arrival']);
        $lunchDue = Carbon::parse(self::LUNCH_EARLIEST);
        $room = $this->minutesBetween($begin, $lunchDue) - 15;
        $minutes = intdiv(max(0, $room), 15) * 15;

        if ($minutes < self::SHORT_VISIT_MINUTES) {
            return null;
        }

        $again = $this->planVisit($clock, $here, $there, $minutes, $lunchTaken, $destination, $date);

        return $again !== null && $this->projectDepartureArrival($again, $there)->lessThanOrEqualTo($this->finalDayDeparture['deadline'])
            ? $again
            : null;
    }

    /**
     * With a departure time, the stops that cannot be done on the last day go earlier. Each stop is tried as the last
     * day's first visit (a fresh morning, starting from last night's stay); those that cannot make the departure move to
     * the front, so earlier days take them, and the rest keep their order for the last day. Without a departure, or
     * with a one-day trip, the queue is left as it is.
     *
     * @param  array<int, array{row: array, distance_km: float|null}>  $queue
     * @param  array<int, array{listing: mixed, rule: array|null}>  $stayByNight
     * @return array<int, array{row: array, distance_km: float|null}>
     */
    private function queueForDeparture(array $queue, array $stayByNight, int $lastDay, array $origin, Carbon $lastDate): array
    {
        $lastNight = $stayByNight[$lastDay - 1]['listing'] ?? null;
        $from = $lastNight ? $this->place($lastNight) : $origin;
        $this->finalDayDeparture = $this->departure;
        $morning = Carbon::parse(self::EARLIEST_DEPARTURE);
        $early = $late = [];

        try {
            foreach ($queue as $item) {
                $destination = $item['row']['destination'];
                $there = $this->place($destination);
                $visit = $this->planVisit($morning, $from, $there, self::VISIT_MINUTES, false, $destination, $lastDate);
                $fits = $visit !== null && (
                    $this->projectDepartureArrival($visit, $there)->lessThanOrEqualTo($this->departure['deadline'])
                    || $this->visitEndingBeforeLunch($visit, $morning, $from, $there, false, $destination, $lastDate) !== null
                );

                if ($fits) {
                    $late[] = $item;
                } else {
                    $early[] = $item;
                }
            }
        } finally {
            $this->finalDayDeparture = null;
        }

        return array_values([...$early, ...$late]);
    }

    /**
     * The final day with a departure time: souvenirs and lunch only when they still fit before the allowance, then the
     * journey to the departure point and the departure row (arrive-by time and the allowance, stated). With no
     * sightseeing the day is just the journey, set off at the latest safe time, so no time is spent on stops that
     * would put the departure at risk.
     */
    private function closeDepartureDay(Itinerary $itinerary, int $dayNumber, int &$sortOrder, Carbon $clock, array $here, bool $lunchTaken, $lastDestination, ?Carbon $date, bool $sawSights, int $driven = 0): void
    {
        $d = $this->finalDayDeparture;
        $point = $d['place'];
        $deadline = $d['deadline'];

        $suggestion = $sawSights ? $this->souvenirStop($here, $lastDestination, $clock) : null;
        if ($suggestion) {
            $shop = $this->place($suggestion['listing']);
            $shopArrival = $this->toQuarterHour($this->toQuarterHour($clock->copy()->addMinutes(self::DEPARTURE_BUFFER_MINUTES))->addMinutes($this->estimateLeg($here, $shop)['max_minutes']));
            $shopEnd = $shopArrival->copy()->addMinutes(self::MEAL_MINUTES);

            // The shop also counts against the day's driving budget, with the transfer to the departure point after it.
            $shopDrive = $driven + $this->estimateLeg($here, $shop)['max_minutes'] + $this->estimateLeg($shop, $point)['max_minutes'];

            if ($this->departureArrival($shopEnd, $shop)->greaterThan($deadline) || $shopDrive > min(self::MAX_DAILY_DRIVE_MINUTES, $this->softDriveBudget)) {
                $suggestion = null;
            }
        }

        if ($suggestion) {
            $clock = $this->addTravel($itinerary, $dayNumber, $sortOrder, $this->toQuarterHour($clock->copy()->addMinutes(self::DEPARTURE_BUFFER_MINUTES)), $here, $this->place($suggestion['listing']));
            $start = $this->toQuarterHour($clock);
            $end = $start->copy()->addMinutes(self::MEAL_MINUTES);
            $this->row($itinerary, $dayNumber, $sortOrder, array_merge([
                'kind' => 'activity',
                'title' => 'Souvenir shopping',
                'starts_at' => $start,
                'ends_at' => $end,
                'souvenir_center_id' => $suggestion['listing']->id,
            ], $this->ruleColumns($suggestion['rule'] ?? null)));
            $clock = $end;
            $here = $this->place($suggestion['listing']);
        }

        if ($sawSights && ! $lunchTaken && $this->lunchIsDue($clock)
            && $this->departureArrival($this->toQuarterHour($clock)->addMinutes(self::MEAL_MINUTES), $here)->lessThanOrEqualTo($deadline)) {
            $clock = $this->addMeal($itinerary, $dayNumber, $sortOrder, $clock, 'Lunch', $here, null, $date, 'after');
        }

        $estimate = $this->estimateLeg($here, $point);
        $setOff = $this->toQuarterHour($clock->copy()->addMinutes(self::DEPARTURE_BUFFER_MINUTES));

        if (! $sawSights) {
            // Nothing was visited: stay put and set off at the latest safe time, rather than add stops.
            $latest = $this->latestTransferLeave($here);
            // A journey may set off before the day's 08:00 start (only sightseeing waits for 08:00), but never before the
            // traveller is ready on the arrival day, nor before the row already written for the day.
            $floor = $dayNumber === 1 ? $clock : Carbon::parse($this->lastStart[$itinerary->id.'-'.$dayNumber] ?? '00:00:00');
            if ($latest->toDateString() === $clock->toDateString() && $latest->greaterThanOrEqualTo($floor)) {
                $setOff = $latest;
                if ($this->minutesBetween($clock, $setOff) >= self::MIN_FREE_TIME_BEFORE_DINNER_MINUTES) {
                    $this->row($itinerary, $dayNumber, $sortOrder, [
                        'kind' => 'free',
                        'title' => 'Free time until '.$setOff->format('g:i A'),
                        'starts_at' => $clock->copy(),
                        'ends_at' => $setOff->copy(),
                        'note' => 'Nothing is planned: the journey to '.$point['label'].' needs the time that is left before your departure.',
                    ]);
                }
            } else {
                $setOff = $this->toQuarterHour($floor);
            }
        }

        $arrival = $this->toQuarterHour($this->addTravel($itinerary, $dayNumber, $sortOrder, $setOff, $here, $point));

        $notes = ['Be at '.$point['label'].' by '.$deadline->format('g:i A').': that is '.$this->durationText($d['buffer']).' before your '.$d['at']->format('g:i A').' departure, a planning allowance and not an airline or airport rule.'];
        $notes[] = $estimate['distance_km'] === null
            ? 'The distance to '.$point['label'].' could not be measured from here, so a time allowance was used.'
            : 'The journey is an estimate (straight line plus a detour allowance, at the slowest pace), not a routed time.';
        if ($arrival->greaterThan($deadline)) {
            $notes[] = 'Even so, this estimate reaches '.$point['label'].' at '.$arrival->format('g:i A').', after the allowance starts. Consider an earlier start or a later departure.';
        }

        $this->row($itinerary, $dayNumber, $sortOrder, [
            'kind' => 'departure',
            'title' => 'Departure from '.$point['label'],
            'starts_at' => $arrival,
            'ends_at' => $d['at']->copy(),
            'note' => implode(' ', $notes),
        ]);
    }

    private function durationText(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return trim(($h > 0 ? $h.' '.($h === 1 ? 'hour' : 'hours') : '').($m > 0 ? ' '.$m.' minutes' : '')) ?: '0 minutes';
    }

    /**
     * When the traveller would reach the starting point if the last day ended with this candidate visit.
     *
     * Mirrors the steps build() writes for an accepted stop (commitLeg, waiting for opening time when needed,
     * the activity row, and a post-visit lunch when the visit ends inside the lunch window), then delegates to
     * projectHomeArrival() for the return leg.
     */
    private function projectVisitHomeArrival(array $visit, array $there, array $origin): Carbon
    {
        $afterLeg = $visit['plan']['arrival']->copy();
        if ($visit['starts_at'] !== null && $visit['starts_at']->greaterThan($afterLeg)) {
            $afterLeg = $visit['starts_at']->copy();
        }
        $afterVisit = $this->toQuarterHour($afterLeg)->addMinutes($visit['minutes']);

        return $this->projectHomeArrival($afterVisit, $there, $origin, (bool) $visit['plan']['lunchTaken']);
    }

    /**
     * When the traveller would reach the starting point if the trip ended after finishing at $from at $clock:
     * lunch first when the stop ended inside the lunch window without lunch yet taken, the departure buffer,
     * and the way home with the dinner break a late return needs (the same steps closeTripDay() writes).
     */
    private function projectHomeArrival(Carbon $clock, array $from, array $origin, bool $lunchTaken): Carbon
    {
        if (! $lunchTaken && $this->lunchIsDue($clock)) {
            $clock = $this->toQuarterHour($clock)->addMinutes(self::MEAL_MINUTES);
        }

        $depart = $this->toQuarterHour($clock->copy()->addMinutes(self::DEPARTURE_BUFFER_MINUTES));
        $arrival = $this->planDinnerLeg($depart, $from, $origin)['arrival'];

        return $this->toQuarterHour($arrival);
    }

    /**
     * When the traveller would check in tonight if the arrival day had no sightseeing stop at all: the day opens as
     * openDay() opens it, then they go straight from the starting point to the stay (departure buffer and any dinner
     * break included, exactly as closeOvernightDay() plans it). Used by the stay plan to see a late check-in coming
     * before the day is built; the built itinerary stays the judge.
     */
    public function projectArrivalDayCheckIn(TouristPreference $preference, array $origin, Accommodation $stay): Carbon
    {
        $previous = $this->transport;
        $this->transport = $preference->transport_pref;

        try {
            $earliest = Carbon::parse(self::EARLIEST_DEPARTURE);
            $ready = $this->arrivalTime($preference)->copy()->addMinutes(self::ARRIVAL_PREPARATION_MINUTES);

            if (filled($preference->arrival_time)
                && $ready->greaterThanOrEqualTo(Carbon::parse(self::BREAKFAST))
                && $ready->lessThanOrEqualTo(Carbon::parse(self::BREAKFAST_LATEST))) {
                $clock = $ready->copy()->addMinutes(self::BREAKFAST_MINUTES);
                $clock = $clock->lessThan($earliest) ? $earliest : $clock;
            } else {
                $clock = $ready->lessThan($earliest) ? $earliest : $ready;
            }

            $depart = $this->toQuarterHour($clock->copy()->addMinutes(self::DEPARTURE_BUFFER_MINUTES));

            return $this->toQuarterHour($this->planDinnerLeg($depart, $origin, $this->place($stay))['arrival']);
        } finally {
            $this->transport = $previous;
        }
    }

    /**
     * Whether the arrival day has room for even the shortest proper visit: the day starts at the later of arrival plus
     * preparation and the earliest departure (the arrival rule, unchanged), and a visit needs at least
     * SHORT_VISIT_MINUTES before the sightseeing cutoff. A blank arrival time is planned as the earliest departure.
     */
    public function arrivalDayHasRoomForAVisit(TouristPreference $preference): bool
    {
        $ready = $this->arrivalTime($preference)->copy()->addMinutes(self::ARRIVAL_PREPARATION_MINUTES);
        $earliest = Carbon::parse(self::EARLIEST_DEPARTURE);
        $start = $ready->lessThan($earliest) ? $earliest : $ready;

        return $this->minutesBetween($start, Carbon::parse(self::LAST_ACTIVITY_END)) >= self::SHORT_VISIT_MINUTES;
    }
    /** Whether a check-in at this time is "late": after the latest dinner start, when no dinner is planned. */
    public static function latestDinnerStart(): string
    {
        return self::LATEST_DINNER_START;
    }
    /** The TransportMode the current build() is planning for; null plans as a personal car. */
    private ?string $transport = null;

    /** The current build()'s daily driving budget (3.5 / 5 / 7 hours by distance preference); a souvenir stop has to fit inside it too. */
    private int $softDriveBudget = self::MAX_DAILY_DRIVE_MINUTES;

    /** Whether the current build() is planning a Remote (distance_pref 'far') trip. */
    private bool $remoteTrip = false;

    /** Warning to put on the next sightseeing row (a stop whose drive alone is over the daily ceiling). */
    private ?string $pendingActivityNote = null;

    /**
     * The latest stored start time of each day, and the days whose clock has run past midnight, both keyed
     * "itinerary-day". The schedule columns are `time` (no date), so a row after midnight would otherwise be
     * stored as, say, 00:15 inside Day 1: shown as a morning time, with the day's times running backwards.
     *
     * @var array<string, string>
     */
    private array $lastStart = [];

    /** @var array<string, bool> */
    private array $pastMidnight = [];

    /** Writes one schedule line. */
    private function row(Itinerary $itinerary, int $dayNumber, int &$sortOrder, array $attributes): void
    {
        $starts = $attributes['starts_at'] ?? null;
        $ends = $attributes['ends_at'] ?? null;

        if ($this->pendingActivityNote !== null && ($attributes['kind'] ?? null) === 'activity' && ! empty($attributes['destination_id'])) {
            $attributes['note'] = filled($attributes['note'] ?? null) ? $attributes['note'].' '.$this->pendingActivityNote : $this->pendingActivityNote;
            $this->pendingActivityNote = null;
        }

        // A row whose time precedes the one before it in the same day has crossed midnight, and so has every row
        // after it: those carry no time, only a note, rather than a time that reads as the next morning.
        $key = $itinerary->id.'-'.$dayNumber;
        if ($starts !== null) {
            $time = $starts->format('H:i:s');
            if (! empty($this->pastMidnight[$key]) || (isset($this->lastStart[$key]) && $time < $this->lastStart[$key])) {
                $this->pastMidnight[$key] = true;
                $starts = $ends = null;
                $note = 'This falls after midnight, so no set time is shown.';
                $attributes['note'] = filled($attributes['note'] ?? null) ? $attributes['note'].' '.$note : $note;
            } else {
                $this->lastStart[$key] = $time;
                // A stay that begins before midnight and ends after it keeps its start and drops the end.
                if ($ends !== null && $ends->format('H:i:s') < $time) {
                    $ends = null;
                }
            }
        }

        // Overrides go on the LEFT of the union: array + keeps the left-hand
        // key, and passing the Carbon objects through unformatted wrote a full
        // datetime ("2026-08-30 08:00:00") into a time column.
        $itinerary->items()->create([
            'day_number' => $dayNumber,
            'sort_order' => $sortOrder++,
            'slot' => $starts ? $this->slotFor($starts) : 'Any time',
            'starts_at' => $starts?->format('H:i:s'),
            'ends_at' => $ends?->format('H:i:s'),
            'note' => $attributes['note'] ?? null,
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

    /** Lunch is due while the clock is inside the lunch window (the times it may start): 11:30 AM to 1:00 PM. */
    private function lunchIsDue(Carbon $clock): bool
    {
        return $clock->greaterThanOrEqualTo(Carbon::parse(self::LUNCH_EARLIEST))
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
        $rule = $this->apriori->suggestionsFor('destination', $destination->id, 5, 'restaurant')->first();

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
            $rule = $this->apriori->suggestionsFor('destination', $lastDestination->id, 5, 'souvenir_center')->first();

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
            $listing = \App\Support\OfficialOnlyMode::apply(\App\Models\SouvenirCenter::publiclyVisible()->availableForTrip())->orderByDesc('rating')->first();

            return $listing ? [...$candidates, ['listing' => $listing, 'rule' => null]] : $candidates;
        }

        $listing = \App\Support\OfficialOnlyMode::apply(\App\Models\SouvenirCenter::publiclyVisible()
            ->availableForTrip())
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
