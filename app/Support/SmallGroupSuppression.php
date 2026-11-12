<?php

namespace App\Support;

/**
 * Minimum group size for anything shown about visitors: a group smaller than $min is never shown on its own.
 *
 * Small groups are folded into one "Other" row. If that row would itself be smaller than $min (so it would reveal "1 to 4
 * people"), the smallest shown group is folded into it as well, until it is big enough. If the whole table is smaller than
 * $min nothing is shown at all. This is applied when the numbers are displayed; the stored rows are not changed.
 */
final class SmallGroupSuppression
{
    public const OTHER = 'Other (groups under %d)';

    /**
     * @param  array<string, int>  $counts  label => number of visitors
     * @return array{rows: array<int, array{label: string, count: int}>, hidden: int, total: int, enough: bool}
     */
    public static function apply(array $counts, int $min = 5): array
    {
        $counts = array_filter($counts, fn ($n) => $n > 0);
        $total = array_sum($counts);

        if ($total < $min) {
            return ['rows' => [], 'hidden' => $total, 'total' => $total, 'enough' => false];
        }

        arsort($counts);
        $shown = array_filter($counts, fn ($n) => $n >= $min);
        $other = array_sum(array_filter($counts, fn ($n) => $n < $min));

        // The "Other" row must not reveal a small number either.
        while ($other > 0 && $other < $min && $shown !== []) {
            $label = array_key_last($shown);
            $other += $shown[$label];
            unset($shown[$label]);
        }

        $rows = [];
        foreach ($shown as $label => $n) {
            $rows[] = ['label' => (string) $label, 'count' => (int) $n];
        }
        if ($other > 0) {
            $rows[] = ['label' => sprintf(self::OTHER, $min), 'count' => (int) $other];
        }

        return ['rows' => $rows, 'hidden' => (int) $other, 'total' => (int) $total, 'enough' => true];
    }
}
