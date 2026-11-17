<?php

namespace App\Support;

use App\Models\Accommodation;
use Illuminate\Database\Eloquent\Builder;

/** Shared display and overnight search ranges; never write estimates into recorded price fields. */
final class StayPricing
{
    /** Display classification only. Preserve recorded tiers; infer one category from the range midpoint. */
    public static function tier(Accommodation $stay): array
    {
        if (filled($stay->price_tier)) {
            return ['label' => $stay->price_tier, 'estimated' => false];
        }
        $range = self::nightly($stay);
        $midpoint = ($range['min'] + $range['max']) / 2;
        $label = $midpoint < 2500 ? 'Budget-Friendly' : ($midpoint < 7000 ? 'Mid-range' : 'Premium');

        return ['label' => $label, 'estimated' => true];
    }

    public static function applyNightlyBudget(Builder $query, ?float $min, ?float $max): void
    {
        if ($min !== null && $max !== null && $min > $max) {
            $query->whereRaw('1 = 0');
            return;
        }
        // Resolve through the same rules as the cards. Filter before pagination so totals and maps agree.
        // The catalogue is small; this avoids database-specific CASE expressions duplicating pricing rules.
        $ids = (clone $query)->withoutEagerLoads()->reorder()
            ->select(['id', 'name', 'type', 'price_tier', 'price_per_night'])->get()
            ->filter(function (Accommodation $stay) use ($min, $max) {
                $range = self::nightly($stay);

                return ($min === null || $range['max'] >= $min)
                    && ($max === null || $range['min'] <= $max);
            })->modelKeys();
        $query->whereIn($query->getModel()->qualifyColumn('id'), $ids);
    }

    public static function nightly(Accommodation $stay): array
    {
        if ($stay->price_per_night !== null) {
            $rate = (float) $stay->price_per_night;

            return self::range($rate, $rate, 'recorded', 'Recorded rate',
                'Nightly rate on file. Confirm current rates, taxes, inclusions and availability with the property.');
        }

        $published = config('stay-pricing.published', [])[$stay->name] ?? null;
        if ($published) {
            return self::range($published['min'], $published['max'], 'published', 'Published room range',
                $published['note']) + ['source' => $published['source'], 'checked' => $published['checked']];
        }

        $tiers = config('stay-pricing.tier_estimates', []);
        $types = config('stay-pricing.estimates');
        [$min, $max] = $tiers[$stay->price_tier] ?? $types[$stay->type] ?? $types['default'];
        $basis = isset($tiers[$stay->price_tier]) ? 'recorded price tier' : 'property type';

        return self::range($min, $max, 'estimated', 'Estimated range',
            'Planning allowance based on '.$basis.', not a quote from this property. Per room or unit per night. Confirm the actual rate, guest capacity, taxes and inclusions before booking.');
    }

    private static function range(float $min, float $max, string $kind, string $label, string $note): array
    {
        return compact('min', 'max', 'kind', 'label', 'note') + [
            'amount' => number_format($min).($min !== $max ? '–'.number_format($max) : ''),
        ];
    }
}
