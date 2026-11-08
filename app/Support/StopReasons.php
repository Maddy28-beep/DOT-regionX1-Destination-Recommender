<?php

namespace App\Support;

use App\Models\Destination;
use App\Models\TouristPreference;
use App\Services\Recommendation\InterestCoverage;

/**
 * The plain reason shown under "Why this pick?" for a sightseeing stop.
 *
 * Every word here comes from what the planner itself knows: the interests the traveller ticked that this place covers (the
 * same test the ranking and the interest-coverage step use), its rating, and whether its price band fits the budget band.
 * No AI writes any of it.
 */
final class StopReasons
{
    /** The price bands in order (the order the audit's budget check uses). */
    private const TIER_ORDER = ['Free' => 0, 'Budget-Friendly' => 1, 'Mid-range' => 2, 'Premium' => 3];

    /**
     * @return array{sentence: string, facts: array<int, string>}
     */
    public static function for(Destination $destination, ?TouristPreference $preference): array
    {
        $facts = [];
        $matched = [];

        $interests = $preference
            ? $preference->activities->pluck('activity')->filter()->unique()->values()->all()
            : [];
        foreach ($interests as $interest) {
            if (InterestCoverage::covers($destination, (string) $interest)) {
                $matched[] = (string) $interest;
            }
        }
        foreach (array_slice($matched, 0, 2) as $interest) {
            $facts[] = 'Matches your interest: '.$interest;
        }

        // Bands, not prices: the chip says "fits your budget band" and never claims a cost. Nothing is said when either band is
        // unknown: an unpriced place does not fit a band, it just has no claim.
        $budgetLimit = self::TIER_ORDER[$preference?->budget ?? null] ?? null;
        $stopTier = self::TIER_ORDER[$destination->price_tier ?? null] ?? null;
        if ($budgetLimit !== null && $stopTier !== null && $stopTier <= $budgetLimit) {
            $facts[] = 'Fits your budget band';
        }

        if ((float) $destination->rating > 0 && (int) $destination->review_count > 0) {
            $facts[] = 'Rated '.rtrim(rtrim(number_format((float) $destination->rating, 1), '0'), '.').' from '
                .$destination->review_count.' '.($destination->review_count === 1 ? 'review' : 'reviews');
        }

        if ($facts === []) {
            $facts[] = 'One of the best matches for your answers';
        }

        $sentence = match (true) {
            $matched !== [] => 'Chosen because it matches your interest in '.self::list(array_slice($matched, 0, 2)).'.',
            default => 'Chosen as one of the best-scoring places for your answers.',
        };

        return ['sentence' => $sentence, 'facts' => array_slice($facts, 0, 3)];
    }

    /** @param  array<int, string>  $items */
    private static function list(array $items): string
    {
        return count($items) === 2 ? $items[0].' and '.$items[1] : (string) ($items[0] ?? '');
    }
}
