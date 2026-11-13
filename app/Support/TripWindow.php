<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The trip's real start and end: two moments in Philippine time (Asia/Manila, which has no daylight saving).
 *
 *  - the start is when the traveller is free at the pick-up location;
 *  - the end is when the trip has to be finished (for example back at the airport).
 *
 * Everything here is pure: parsing the browser's date-time value, the rules, the duration in the words the form shows,
 * and the mapping of a trip saved before this existed (arrival date + time, number of days, optional finish-by).
 *
 * Moments are stored and compared as wall-clock Philippine time, like every other date and time in this app.
 */
final class TripWindow
{
    public const ZONE = 'Asia/Manila';

    /** The value format a browser's datetime-local input sends. */
    public const INPUT_FORMAT = 'Y-m-d\TH:i';

    /** The time a trip saved without an arrival time is taken to start. */
    public const LEGACY_DEFAULT_START = '09:00';

    public static function minHours(): int
    {
        return max(1, (int) config('itinerary.trip_window.min_hours', 3));
    }

    public static function maxDays(): int
    {
        return max(1, (int) config('itinerary.trip_window.max_days', 30));
    }

    public static function now(): Carbon
    {
        return Carbon::now(self::ZONE);
    }

    /** A moment from the browser's value ("2026-12-01T14:00", or with a space), or null when it is not a real date and time. */
    public static function parse(?string $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        foreach ([self::INPUT_FORMAT, 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s'] as $format) {
            // Carbon throws, rather than returning false, for a value that does not fit the format: try the next one.
            try {
                $moment = Carbon::createFromFormat('!'.$format, $value, self::ZONE);
            } catch (\Throwable) {
                continue;
            }
            // A date that PHP rolls over (30 February) is not a date.
            if ($moment !== false && $moment->format($format) === $value) {
                return $moment;
            }
        }

        return null;
    }

    /** A moment read back from the database, where it is stored as wall-clock Philippine time. */
    public static function fromStorage(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? Carbon::createFromFormat('!Y-m-d H:i:s', $value->format('Y-m-d H:i:s'), self::ZONE)
            : self::parse(substr((string) $value, 0, 19));
    }

    public static function toStorage(Carbon $moment): string
    {
        return $moment->format('Y-m-d H:i:s');
    }

    /** ISO 8601 with the Philippine offset, for anything that wants an unambiguous moment. */
    public static function iso(Carbon $moment): string
    {
        return $moment->copy()->setTimezone(self::ZONE)->format('Y-m-d\TH:i:sP');
    }

    /**
     * What is wrong with this start and end, keyed by field. Empty when it is fine.
     *
     * @return array<string, string>
     */
    public static function errors(?Carbon $start, ?Carbon $end, bool $allowPast = false, ?Carbon $now = null): array
    {
        $now ??= self::now();
        $errors = [];

        if ($start !== null && ! $allowPast && $start->lt($now->copy()->startOfMinute())) {
            $errors['start_at'] = 'Start must be in the future (Philippine time).';
        }
        // The same two-year limit the arrival date always had: a typo such as 2062 would otherwise be planned as written.
        if ($start !== null && $start->gt($now->copy()->addYears(2))) {
            $errors['start_at'] = 'Start can be at most two years ahead.';
        }

        if ($start !== null && $end !== null) {
            if ($end->lte($start)) {
                $errors['end_at'] = 'End must be after the start.';
            } elseif ($start->diffInMinutes($end) < self::minHours() * 60) {
                $errors['end_at'] = 'End must be at least '.self::minHours().' hours after the start.';
            } elseif ($start->diffInMinutes($end) > self::maxDays() * 1440 || self::calendarDays($start, $end) > self::maxDays()) {
                $errors['end_at'] = 'A trip can be at most '.self::maxDays().' days long.';
            }
        }

        return $errors;
    }

    /** Whole and part days, hours and minutes between the two moments. */
    public static function parts(Carbon $start, Carbon $end): array
    {
        $minutes = max(0, (int) $start->diffInMinutes($end));

        return ['days' => intdiv($minutes, 1440), 'hours' => intdiv($minutes % 1440, 60), 'minutes' => $minutes % 60];
    }

    /** "3 days, 5 hours", "1 day, 20 hours", "5 hours", "2 days". Zero parts are left out. */
    public static function text(Carbon $start, Carbon $end): string
    {
        $p = self::parts($start, $end);
        $out = [];
        foreach (['days' => 'day', 'hours' => 'hour', 'minutes' => 'minute'] as $key => $word) {
            if ($p[$key] > 0) {
                $out[] = $p[$key].' '.$word.($p[$key] === 1 ? '' : 's');
            }
        }

        return $out === [] ? '0 minutes' : implode(', ', $out);
    }

    /**
     * The length stored for queries and reports: the calendar days the trip touches, so it matches the Day 1, 2, 3 tabs
     * (1 Dec 14:00 to 3 Dec 10:00 is 3). The exact length ("1 day, 20 hours") is shown separately by text().
     */
    public static function durationDays(Carbon $start, Carbon $end): int
    {
        return max(1, self::calendarDays($start, $end));
    }

    /**
     * The calendar days the trip touches, first and last included. This is what the day-by-day plan is built on:
     * 1 Dec 14:00 to 3 Dec 10:00 is three planning days (a partial first day, a full day and a partial last day),
     * although its duration is 1 day, 20 hours.
     */
    public static function calendarDays(Carbon $start, Carbon $end): int
    {
        return (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1;
    }

    /**
     * A trip saved before start and end existed: the arrival date and time (09:00 when there was no time), lasting the
     * number of days (the last day to 23:59, or to the finish-by time when there was one). Null without an arrival date.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public static function fromLegacy(mixed $startDate, ?string $arrivalTime, int $days, ?string $finishBy): ?array
    {
        if ($startDate === null || $startDate === '') {
            return null;
        }

        $day = Carbon::parse($startDate instanceof \DateTimeInterface ? $startDate->format('Y-m-d') : substr((string) $startDate, 0, 10), self::ZONE)->startOfDay();
        $time = filled($arrivalTime) ? substr((string) $arrivalTime, 0, 5) : self::LEGACY_DEFAULT_START;
        $start = self::parse($day->format('Y-m-d').' '.$time) ?? $day->copy()->setTime(9, 0);

        $lastDay = $day->copy()->addDays(max(1, $days) - 1);
        $end = $lastDay->copy()->setTime(23, 59);
        if (filled($finishBy)) {
            $candidate = self::parse($lastDay->format('Y-m-d').' '.substr((string) $finishBy, 0, 5));
            if ($candidate !== null && $candidate->gt($start)) {
                $end = $candidate;
            }
        }

        return [$start, $end];
    }
}
