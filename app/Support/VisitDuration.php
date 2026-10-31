<?php

namespace App\Support;

/** Conservative parsing for scheduling; unknown text is not a verified duration. */
final class VisitDuration
{
    /** The shortest meaningful shortened visit, shared by scheduler and audit. */
    public static function minimumMinutes(int $recorded): int
    {
        return min($recorded, max(120, intdiv($recorded, 2)));
    }

    public static function minutes(?string $text): ?int
    {
        $text = mb_strtolower(trim((string) $text));

        // The current scheduler cannot split a single activity across days.
        if (preg_match('/\b(?:overnight|multi[- ]day)\b|\d+\s*(?:days?|nights?)\b/u', $text)) {
            return 1440;
        }

        if (in_array($text, ['half day', 'half-day'], true)) {
            return 240;
        }
        if (in_array($text, ['full day', 'whole day', 'all day'], true)) {
            return 480;
        }

        // Use the upper end of a range so travel plans allow the whole visit.
        if (! preg_match('/^(\d+(?:\.\d+)?)(?:\s*(?:-|–|—|to)\s*(\d+(?:\.\d+)?))?\s*(minutes?|mins?|hours?|hrs?|h)$/u', $text, $m)) {
            return null;
        }

        $lower = (float) $m[1];
        $upper = ($m[2] ?? '') === '' ? $lower : (float) $m[2];
        if ($lower <= 0 || $upper < $lower) {
            return null;
        }

        $minutes = $upper * (str_starts_with($m[3], 'h') ? 60 : 1);

        return (int) min(1440, ceil($minutes));
    }
}
