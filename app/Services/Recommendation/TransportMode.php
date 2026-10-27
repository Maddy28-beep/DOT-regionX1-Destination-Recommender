<?php

namespace App\Services\Recommendation;

/**
 * How the traveller intends to get between stops, and what that does to journey times.
 *
 * The planner used to assume one kind of trip for everybody: a private vehicle on mixed roads. A traveller
 * riding jeepneys does not move at that pace -- they wait at the roadside, stop at every corner and change
 * at terminals -- so the same eight stops that fit in a day by car do not fit by jeep. Each mode here is
 * therefore a speed RANGE and a fixed overhead per leg, the same two quantities TravelTimeEstimator already
 * works in, not a separate calculation.
 *
 * The figures are planning allowances, not measurements. They are named and kept in one table so they can
 * be corrected against real trips without hunting through the estimator.
 *
 * An unanswered or unrecognised mode is the personal car, which is exactly what the estimator assumed before
 * this setting existed -- so a saved plan or a guest draft from before it behaves as it always did.
 */
final class TransportMode
{
    public const PERSONAL_CAR = 'personal_car';

    public const RENTAL_CAR = 'rental_car';

    public const TAXI = 'taxi';

    public const JEEP = 'jeep';

    public const TOUR_OPERATOR = 'tour_operator';

    /**
     * mode => [label, "by ..." phrase, fastest km/h, slowest km/h, overhead minutes per leg]
     *
     * A tour operator collects the traveller at the starting point and drives them to the stops, so the legs
     * run at the pace of a private vehicle. The extra five minutes of overhead is the van picking up and
     * setting down at each stop. Which operator, when and where from is the operator's to say, not ours:
     * nothing here knows it, and the itinerary says so.
     */
    private const PROFILES = [
        self::TOUR_OPERATOR => ['Tour Operator Pick-up', 'by tour operator', 40.0, 25.0, 15],
        self::JEEP => ['Jeep', 'by jeep', 25.0, 15.0, 15],
        self::TAXI => ['Taxi', 'by taxi', 40.0, 25.0, 10],
        self::PERSONAL_CAR => ['Personal Car', 'by car', 40.0, 25.0, 10],
        self::RENTAL_CAR => ['Rent a Car', 'by rental car', 40.0, 25.0, 10],
    ];

    /** @return list<string> every value a traveller may submit */
    public static function keys(): array
    {
        return array_keys(self::PROFILES);
    }

    /** True for a mode this class knows; null and unknown strings are not modes. */
    public static function isKnown(?string $mode): bool
    {
        return $mode !== null && isset(self::PROFILES[$mode]);
    }

    /** The mode to plan with: the one given, or the personal car when none (or an unknown one) was. */
    public static function resolve(?string $mode): string
    {
        return self::isKnown($mode) ? $mode : self::PERSONAL_CAR;
    }

    public static function label(?string $mode): string
    {
        return self::PROFILES[self::resolve($mode)][0];
    }

    /** The label for a small badge on each journey, where "Tour Operator Pick-up" would be too long. */
    public static function shortLabel(?string $mode): string
    {
        return self::isTourOperator($mode) ? 'Tour operator' : self::label($mode);
    }

    /** "by jeep" -- reads after a distance: "Approx. 12 km by jeep, 45–60 mins". */
    public static function phrase(?string $mode): string
    {
        return self::PROFILES[self::resolve($mode)][1];
    }

    public static function fastKmh(?string $mode): float
    {
        return self::PROFILES[self::resolve($mode)][2];
    }

    public static function slowKmh(?string $mode): float
    {
        return self::PROFILES[self::resolve($mode)][3];
    }

    public static function overheadMinutes(?string $mode): int
    {
        return self::PROFILES[self::resolve($mode)][4];
    }

    /** A tour operator collects the traveller; every other mode is the traveller commuting on their own. */
    public static function isTourOperator(?string $mode): bool
    {
        return $mode === self::TOUR_OPERATOR;
    }

    /** Jeep and taxi are the two public modes the form groups under "Public Transport". */
    public static function isPublic(?string $mode): bool
    {
        return $mode === self::JEEP || $mode === self::TAXI;
    }

    /**
     * What the form needs to compare ways of travelling: the speed range and overhead of the modes it draws
     * a bar for, so the page and the planner read the same table rather than two copies of it.
     *
     * @return array<string, array{label: string, fast: float, slow: float, overhead: int}>
     */
    public static function comparison(): array
    {
        $out = [];
        foreach ([self::TOUR_OPERATOR, self::JEEP, self::TAXI, self::PERSONAL_CAR] as $mode) {
            $out[$mode] = [
                'label' => self::label($mode),
                'fast' => self::fastKmh($mode),
                'slow' => self::slowKmh($mode),
                'overhead' => self::overheadMinutes($mode),
            ];
        }

        return $out;
    }

}
