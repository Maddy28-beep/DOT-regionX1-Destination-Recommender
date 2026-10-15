<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Reads the free-text opening hours and visit durations stored on listings, for the "Plan your visit"
 * card: whether a place is open right now, and how long a visit takes in plain words.
 *
 * The hours are text typed by people ("9:00 AM–5:00 PM", "8:00 AM–12:00 PM; 1:00 PM–5:00 PM"), not a
 * schedule, so only the simple daily forms are understood. Anything else ("Mon–Sat ...", "By
 * arrangement") returns null, and the card then shows the text without claiming open or closed.
 * Times are Philippine local time.
 */
final class OpeningHours
{
    public const TIMEZONE = 'Asia/Manila';

    private const RANGE = '/^\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)\s*(?:–|—|-|to)\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)\s*$/iu';

    /**
     * Opening windows as minutes since midnight: [[open, close], ...]. A window that runs past midnight
     * has a close above 1440.
     *
     * @return array<int, array{0: int, 1: int}>|null  null when the text is not a plain daily schedule
     */
    public static function windows(?string $text): ?array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^(open\s+)?24\s*(hours|hrs|hr|\/7)\b/i', $text)) {
            return [[0, 1440]];
        }

        $windows = [];
        foreach (explode(';', $text) as $part) {
            if (! preg_match(self::RANGE, $part, $m)) {
                return null;
            }

            $open = self::minutes((int) $m[1], (int) ($m[2] ?: 0), $m[3]);
            $close = self::minutes((int) $m[4], (int) ($m[5] ?: 0), $m[6]);

            if ($close <= $open) {
                $close += 1440; // closes after midnight
            }

            $windows[] = [$open, $close];
        }

        return $windows ?: null;
    }

    private const DAY_NAMES = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];

    private const TIME_RANGE = '\d{1,2}(?::\d{2})?\s*(?:am|pm)\s*(?:–|—|-|to)\s*\d{1,2}(?::\d{2})?\s*(?:am|pm)';

    /**
     * Opening windows on one particular date, for hours that name days: "Mon–Sat 8:00 AM–5:00 PM",
     * "Mon–Fri 8:00 AM–5:00 PM; Sat 8:00 AM–12:00 PM", "Closed Mondays", "9:00 AM–5:00 PM; Closed Sun".
     *
     * Plain daily hours behave exactly as windows() does. Returns:
     *   - a list of windows when the place is open that day,
     *   - an empty list when it is closed that whole day,
     *   - null when nothing about that day can be told from the text (no limit is put on the visit).
     *
     * @return array<int, array{0: int, 1: int}>|null
     */
    public static function windowsOn(?string $text, CarbonInterface $date): ?array
    {
        $plain = self::windows($text);
        if ($plain !== null) {
            return $plain;
        }

        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        $weekday = $date->dayOfWeekIso;
        $closed = [];
        $timed = [];   // [ [days|null, open, close], ... ]

        foreach (explode(';', $text) as $part) {
            $part = trim($part);

            if (preg_match('/^closed(?:\s+on)?\s+(.+)$/iu', $part, $m)) {
                $days = self::days($m[1]);
                if ($days === null) {
                    return null;
                }
                $closed = array_merge($closed, $days);

                continue;
            }

            if (! preg_match('/^(?:(.+?)\s*[:,]?\s+)?('.self::TIME_RANGE.')$/iu', $part, $m)) {
                return null;
            }

            $days = ($m[1] ?? '') === '' ? null : self::days($m[1]);
            if (($m[1] ?? '') !== '' && $days === null) {
                return null;
            }

            $range = self::windows($m[2]);
            if ($range === null) {
                return null;
            }

            $timed[] = [$days, $range[0][0], $range[0][1]];
        }

        if (in_array($weekday, $closed, true)) {
            return [];
        }

        $today = [];
        foreach ($timed as [$days, $open, $close]) {
            if ($days === null || in_array($weekday, $days, true)) {
                $today[] = [$open, $close];
            }
        }

        if ($today !== []) {
            return $today;
        }

        // Opening times were given, but only for other days: closed today.
        return $timed !== [] ? [] : null;
    }

    /**
     * "Mon–Sat", "Sat & Sun", "Fri, Sat", "Mondays", "daily" as ISO weekday numbers (1 = Monday), or null when
     * the text is not a list of days.
     *
     * @return array<int, int>|null
     */
    private static function days(string $text): ?array
    {
        $text = strtolower(trim($text));

        if (preg_match('/^(daily|every\s*day|everyday|all\s+days)$/', $text)) {
            return [1, 2, 3, 4, 5, 6, 7];
        }

        $set = [];
        foreach (preg_split('/\s*(?:,|&|\band\b)\s*/', $text) as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }

            if (! preg_match('/^([a-z]+)(?:\s*(?:–|—|-|to)\s*([a-z]+))?$/u', $piece, $m)) {
                return null;
            }

            $from = self::DAY_NAMES[substr($m[1], 0, 3)] ?? null;
            $to = isset($m[2]) ? (self::DAY_NAMES[substr($m[2], 0, 3)] ?? null) : $from;

            if ($from === null || $to === null) {
                return null;
            }

            for ($d = $from; ; $d = $d % 7 + 1) {   // "Sat–Mon" wraps past Sunday
                $set[] = $d;
                if ($d === $to) {
                    break;
                }
            }
        }

        return $set === [] ? null : array_values(array_unique($set));
    }

    /** True or false when the hours can be read, null when they cannot. */
    public static function isOpenNow(?string $text, ?CarbonInterface $now = null): ?bool
    {
        $windows = self::windows($text);
        if ($windows === null) {
            return null;
        }

        $now = ($now ? Carbon::instance($now) : Carbon::now())->setTimezone(self::TIMEZONE);
        $minute = $now->hour * 60 + $now->minute;

        foreach ($windows as [$open, $close]) {
            // the second test is the tail of a window that began yesterday and runs past midnight
            if (($minute >= $open && $minute < $close) || ($minute + 1440 >= $open && $minute + 1440 < $close)) {
                return true;
            }
        }

        return false;
    }

    /** "240 minutes" becomes "About 4 hours"; text that is not a number of minutes is returned as typed. */
    public static function spendLabel(?string $text): ?string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        if (! preg_match('/^(\d+)\s*(?:minutes?|mins?)$/i', $text, $m)) {
            return $text;
        }

        $minutes = (int) $m[1];
        if ($minutes < 60) {
            return "About {$minutes} minutes";
        }

        $hours = $minutes / 60;
        $label = $hours == floor($hours) ? (string) (int) $hours : rtrim(rtrim(number_format($hours, 1), '0'), '.');

        return 'About '.$label.($label === '1' ? ' hour' : ' hours');
    }

    private static function minutes(int $hour, int $minute, string $meridiem): int
    {
        $hour = $hour % 12;
        if (strtolower($meridiem) === 'pm') {
            $hour += 12;
        }

        return $hour * 60 + $minute;
    }
}
