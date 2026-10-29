<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The partner portal's opening-hours form, as text and back again.
 *
 * Listings keep their hours as one line of text that OpeningHours reads ("8:00 AM–5:00 PM",
 * "Mon–Fri 8:00 AM–5:00 PM; Sat 8:00 AM–12:00 PM"). The form is seven rows, one per day, so this turns
 * the rows into a line OpeningHours understands (days with the same hours are grouped) and a saved line
 * back into rows when it can be read.
 */
final class OpeningHoursText
{
    public const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    public const FULL_DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /**
     * @param  array<int, array{on: bool, from: string, to: string}>  $week  indexed 0 (Monday) to 6 (Sunday)
     */
    public static function compose(array $week): string
    {
        $groups = [];
        foreach (self::DAYS as $i => $name) {
            $day = $week[$i] ?? null;
            if ($day && $day['on']) {
                $groups[self::range($day['from'], $day['to'])][] = $i;
            }
        }

        // The same hours every day need no day names: the plain form OpeningHours reads everywhere.
        if (count($groups) === 1 && count(reset($groups)) === 7) {
            return (string) array_key_first($groups);
        }

        $parts = [];
        foreach ($groups as $range => $days) {
            $parts[] = self::label($days).' '.$range;
        }

        return implode('; ', $parts);
    }

    /**
     * The seven rows for text already saved, or null when the text is not something OpeningHours can read
     * for every weekday (then the form starts from defaults and says so).
     *
     * @return array<int, array{on: bool, from: string, to: string}>|null
     */
    public static function week(?string $text): ?array
    {
        if (trim((string) $text) === '') {
            return null;
        }

        $monday = Carbon::parse('2026-01-05', OpeningHours::TIMEZONE);   // any Monday: only the weekday matters
        $week = [];

        foreach (self::DAYS as $i => $name) {
            $windows = OpeningHours::windowsOn($text, $monday->copy()->addDays($i));
            if ($windows === null) {
                return null;
            }

            $week[$i] = $windows === []
                ? ['on' => false, 'from' => '08:00', 'to' => '17:00']
                : ['on' => true, 'from' => self::clock($windows[0][0]), 'to' => self::clock($windows[0][1])];
        }

        return $week;
    }

    /** "08:00" and "17:00" become "8:00 AM–5:00 PM". */
    private static function range(string $from, string $to): string
    {
        return self::twelve($from).'–'.self::twelve($to);
    }

    private static function twelve(string $time): string
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return (($hour + 11) % 12 + 1).':'.sprintf('%02d', $minute).' '.($hour < 12 ? 'AM' : 'PM');
    }

    private static function clock(int $minutes): string
    {
        $minutes %= 1440;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** "Mon–Fri", "Sat", "Sat, Sun", "Mon, Wed–Fri": runs of three or more days are written as a range. */
    private static function label(array $days): string
    {
        $runs = [];
        foreach ($days as $day) {
            if ($runs && end($runs)[1] === $day - 1) {
                $runs[array_key_last($runs)][1] = $day;
            } else {
                $runs[] = [$day, $day];
            }
        }

        $out = [];
        foreach ($runs as [$a, $b]) {
            $out[] = match (true) {
                $a === $b => self::DAYS[$a],
                $b === $a + 1 => self::DAYS[$a].', '.self::DAYS[$b],
                default => self::DAYS[$a].'–'.self::DAYS[$b],
            };
        }

        return implode(', ', $out);
    }
}
