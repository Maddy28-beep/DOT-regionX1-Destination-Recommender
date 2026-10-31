<?php

namespace App\Support;

use App\Services\Recommendation\ItineraryScheduleBuilder;

/**
 * Turns the recorded reasons a place was left out of a trip (itineraries.stay_plan notices, see
 * ItineraryScheduleBuilder::recordedAttempts) into one plain sentence for the traveller.
 *
 * It states only what was recorded. A notice with no recorded attempt, or one whose attempts it does not know how to
 * read, gets NEUTRAL: nothing about the cause is claimed.
 */
class OmissionReason
{
    public const NEUTRAL = 'It was not added to your plan.';

    /** @param  array<string, mixed>  $notice  an 'unplaced_destination' notice */
    public static function sentence(array $notice): string
    {
        $attempts = array_values(array_filter((array) ($notice['attempts'] ?? []), fn ($a) => is_array($a) && isset($a['cause'], $a['day'])));
        if ($attempts === []) {
            return self::NEUTRAL;
        }

        $parts = [];
        // Days are told together only when the figures in the sentence are the same on each of them.
        foreach (collect($attempts)->groupBy(fn ($a) => $a['cause'].'|'.($a['minutes'] ?? '').'|'.($a['limit'] ?? '').'|'.(! empty($a['fresh']) ? 'f' : '')) as $group) {
            $part = self::part((string) $group->first()['cause'], $group->sortBy('day')->values()->all());
            if ($part !== null) {
                $parts[] = $part;
            }
        }

        return $parts === [] ? self::NEUTRAL : ucfirst(implode('; ', $parts)).'.';
    }

    /** @param  array<int, array<string, mixed>>  $group  attempts of one cause, in day order */
    private static function part(string $cause, array $group): ?string
    {
        $days = self::days(array_column($group, 'day'));
        $first = $group[0];
        // 'fresh': the place could not be done even starting that day fresh, so it was set aside rather than tried again.
        $fresh = collect($group)->contains(fn ($a) => ! empty($a['fresh'])) ? ', even starting the day fresh' : '';

        return match ($cause) {
            'would_end_after_cutoff' => "on $days the visit would have ended after the ".ItineraryScheduleBuilder::LAST_ACTIVITY_END." sightseeing cutoff$fresh",
            'closed_that_day' => "it is closed on $days$fresh",
            'outside_opening_hours' => "on $days it would not have been open long enough for a visit$fresh",
            'drive_budget_reached' => 'on '.$days.' it would have taken the day to about '.self::duration((int) ($first['minutes'] ?? 0)).' of driving, over the '.self::duration((int) ($first['limit'] ?? 0)).' limit for your range',
            'day_full' => "$days already held ".((int) ($first['limit'] ?? 0)).' '.((int) ($first['limit'] ?? 0) === 1 ? 'place' : 'places'),
            'no_sightseeing_time_that_day' => "$days had no sightseeing time after your arrival",
            default => null,
        };
    }

    /** "day 2", "days 2 and 3", "days 2, 3 and 5". */
    private static function days(array $days): string
    {
        $days = array_values(array_unique(array_map('intval', $days)));
        sort($days);
        if (count($days) === 1) {
            return 'day '.$days[0];
        }

        return 'days '.implode(', ', array_slice($days, 0, -1)).' and '.end($days);
    }

    private static function duration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' min';
        }

        return intdiv($minutes, 60).' h'.($minutes % 60 ? ' '.($minutes % 60).' min' : '');
    }
}

