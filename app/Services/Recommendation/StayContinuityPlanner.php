<?php

namespace App\Services\Recommendation;

/** Whole-trip stay optimisation adapted from GitHub main's planStays (38ca7e1).
 * Eligibility and distance limits are decided by the caller, before optimisation.
 */
final class StayContinuityPlanner
{
    /**
     * @param  array<int, array<int, array{listing: mixed, cost: int, rule: ?array}>>  $nights
     * @return array<int, array{listing: mixed, rule: ?array}|null>
     */
    public function plan(array $nights, int $transferPenalty): array
    {
        $result = array_fill_keys(array_keys($nights), null);
        $segments = [];
        $segment = [];
        foreach ($nights as $night => $candidates) {
            if ($candidates !== []) {
                $segment[] = $night;
            } elseif ($segment !== []) {
                $segments[] = $segment;
                $segment = [];
            }
        }
        if ($segment !== []) {
            $segments[] = $segment;
        }

        foreach ($segments as $days) {
            $previous = [];
            $from = [];
            foreach ($days as $index => $day) {
                $next = [];
                foreach ($nights[$day] as $id => $candidate) {
                    $best = null;
                    // An association is a small preference, never permission to
                    // use an ineligible hotel or ignore its travel cost.
                    $cost = $candidate['cost'] - ($candidate['rule'] ? 15 : 0);
                    if ($index === 0) {
                        $best = [$cost, 0, 0];
                    } else {
                        foreach ($previous as $priorId => $score) {
                            $change = $priorId !== $id;
                            $trial = [$score[0] + $cost + ($change ? $transferPenalty : 0),
                                $score[1] + (int) $change,
                                $score[2] + ($change ? count($days) - $index : 0)];
                            if ($best === null || $trial < $best) {
                                $best = $trial;
                                $from[$index][$id] = $priorId;
                            }
                        }
                    }
                    $next[$id] = $best;
                }
                $previous = $next;
            }
            $last = $days[array_key_last($days)];
            $ids = array_keys($previous);
            usort($ids, fn ($a, $b) => [...$previous[$a], -(float) $nights[$last][$a]['listing']->rating, $a]
                <=> [...$previous[$b], -(float) $nights[$last][$b]['listing']->rating, $b]);
            $id = $ids[0];
            for ($index = count($days) - 1; $index >= 0; $index--) {
                $candidate = $nights[$days[$index]][$id];
                $result[$days[$index]] = ['listing' => $candidate['listing'], 'rule' => $candidate['rule']];
                $id = $from[$index][$id] ?? $id;
            }
        }

        return $result;
    }
}
