<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Breakfast, lunch and dinner on the hero search's Dining tab.
 *
 * A restaurant record has no "serves breakfast" flag, but it does carry its opening hours, and the trip planner
 * already treats a meal as a time of day. So a meal choice here means "open at the hour the planner schedules
 * that meal", read with the same OpeningHours parser the "Plan your visit" card uses. Hours the parser cannot
 * read (or none on file) put no limit on the meal, as everywhere else in the app: the place is kept, not
 * dropped on a guess.
 *
 * The hours are the planner's (ItineraryScheduleBuilder: breakfast 07:00, lunch 12:00, dinner 18:30).
 */
final class MealTimes
{
    /** meal => [label, minutes after midnight] */
    public const MEALS = [
        'breakfast' => ['Breakfast', 7 * 60],
        'lunch' => ['Lunch', 12 * 60],
        'dinner' => ['Dinner', 18 * 60 + 30],
    ];

    public static function label(string $meal): ?string
    {
        return self::MEALS[$meal][0] ?? null;
    }

    public static function valid(string $meal): bool
    {
        return isset(self::MEALS[$meal]);
    }

    /**
     * Whether a restaurant with these opening hours is open for the meal (on that date, when given).
     */
    public static function servedAt(?string $hours, string $meal, ?Carbon $date = null): bool
    {
        if (! self::valid($meal)) {
            return true;
        }

        $windows = $date ? OpeningHours::windowsOn($hours, $date) : OpeningHours::windows($hours);
        if ($windows === null) {
            return true; // nothing readable: no limit is put on the visit
        }

        $at = self::MEALS[$meal][1];
        foreach ($windows as [$open, $close]) {
            if ($at >= $open && $at < $close) {
                return true;
            }
        }

        return false;
    }
}
