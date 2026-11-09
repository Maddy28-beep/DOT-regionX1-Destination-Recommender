<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The hero search's Budget dropdown: what it offers for each catalogue, and how a choice becomes a peso range.
 *
 * What a budget means differs by catalogue, because the data does:
 *   - Destinations: the entry fee on file (entry_fee_min to entry_fee_max). A place fits when its fee range
 *     overlaps the budget. A place with no fee on file never fits a budget; "Any budget" keeps it.
 *   - Stays: price_per_night.
 *   - Packages: price_per_pax times the travellers asked for (one, when none is given).
 *   - Dining: restaurants carry a price level (Budget-Friendly / Mid-range / Premium) but no peso amount, so the
 *     dropdown offers those three levels and no custom amount. Nothing is converted to pesos.
 *   - Souvenirs: no price is on file at all, so the card shows no budget there.
 *
 * The peso brackets were cut from the spread of the prices on file on 8 Nov 2026 so that every bracket holds
 * at least one listing; a bracket is offered only while it still does (see offered()).
 */
final class BudgetRanges
{
    public const CUSTOM = 'custom';

    /** A typed amount above this is refused; nothing in the catalogue is near it. */
    public const MAX_AMOUNT = 1000000;

    /** @var array<string, array{basis: string, unit: string, custom: bool, brackets: list<array{0: string, 1: string, 2: ?float, 3: ?float}>}> */
    private const RANGES = [
        'destinations' => [
            'basis' => 'Entry fee',
            'unit' => 'entry fee',
            'custom' => true,
            'brackets' => [
                ['free', 'Free entry available', 0.0, 0.0],
                ['1-100', '₱1 – ₱100', 1.0, 100.0],
                ['101-300', '₱101 – ₱300', 101.0, 300.0],
                ['301-1000', '₱301 – ₱1,000', 301.0, 1000.0],
                ['1000-up', 'Above ₱1,000', 1000.01, null],
            ],
        ],
        'accommodations' => [
            'basis' => 'Price per night',
            'unit' => 'per night',
            'custom' => true,
            'brackets' => [
                ['under-1500', 'Under ₱1,500', null, 1499.99],
                ['1500-2500', '₱1,500 – ₱2,500', 1500.0, 2499.99],
                ['2500-4000', '₱2,500 – ₱4,000', 2500.0, 3999.99],
                ['4000-up', '₱4,000 and above', 4000.0, null],
            ],
        ],
        'packages' => [
            'basis' => 'Total package price',
            'unit' => 'total',
            'custom' => true,
            'brackets' => [
                ['under-1000', 'Under ₱1,000', null, 999.99],
                ['1000-2500', '₱1,000 – ₱2,500', 1000.0, 2499.99],
                ['2500-5000', '₱2,500 – ₱5,000', 2500.0, 4999.99],
                ['5000-up', '₱5,000 and above', 5000.0, null],
            ],
        ],
    ];

    /** Dining offers the price levels already on file instead of peso brackets. */
    public const DINING_LEVELS = [
        'Budget-Friendly' => 'Budget-friendly',
        'Mid-range' => 'Mid-range',
        'Premium' => 'Premium',
    ];

    /** Whether the catalogue has a budget dropdown at all. */
    public static function has(string $purpose): bool
    {
        return isset(self::RANGES[$purpose]) || $purpose === 'restaurants';
    }

    /** @return array{basis: string, unit: string, custom: bool, brackets: list<array>}|null */
    public static function for(string $purpose): ?array
    {
        return self::RANGES[$purpose] ?? null;
    }

    /**
     * The dropdown's options for one catalogue: [key => label], Any budget first and Custom budget last
     * where an amount can be typed. Dining lists its three levels and nothing else.
     *
     * @return array<string, string>
     */
    public static function options(string $purpose): array
    {
        $options = ['' => 'Any budget'];

        if ($purpose === 'restaurants') {
            foreach (self::DINING_LEVELS as $value => $label) {
                $options['level:'.$value] = $label;
            }

            return $options;
        }

        $config = self::RANGES[$purpose] ?? null;
        if (! $config) {
            return $options;
        }

        foreach ($config['brackets'] as [$key, $label]) {
            $options[$key] = $label;
        }
        if ($config['custom']) {
            $options[self::CUSTOM] = 'Custom budget';
        }

        return $options;
    }

    /**
     * What the dropdown choice (and typed amounts, for Custom) comes to.
     *
     * @return array{min: ?float, max: ?float, tier: ?string, error: ?string}
     */
    public static function resolve(string $purpose, ?string $choice, mixed $customMin, mixed $customMax): array
    {
        $none = ['min' => null, 'max' => null, 'tier' => null, 'error' => null];
        $choice = (string) $choice;

        if ($choice === '') {
            return $none;
        }

        if ($purpose === 'restaurants') {
            $level = str_starts_with($choice, 'level:') ? substr($choice, 6) : '';

            return isset(self::DINING_LEVELS[$level]) ? ['tier' => $level] + $none : $none;
        }

        $config = self::RANGES[$purpose] ?? null;
        if (! $config) {
            return $none;
        }

        if ($choice === self::CUSTOM && $config['custom']) {
            $min = self::amount($customMin);
            $max = self::amount($customMax);

            if ($min === false || $max === false) {
                return ['error' => 'Enter the budget as a number, for example 1500.'] + $none;
            }
            if ($min !== null && $max !== null && $min > $max) {
                return ['error' => 'The minimum budget is higher than the maximum.'] + $none;
            }

            return ['min' => $min, 'max' => $max] + $none;
        }

        foreach ($config['brackets'] as [$key, , $min, $max]) {
            if ($key === $choice) {
                return ['min' => $min, 'max' => $max] + $none;
            }
        }

        return $none;
    }

    /**
     * A typed amount: null when empty, false when it is not a sensible number, else the amount.
     */
    public static function amount(mixed $value): float|false|null
    {
        $text = trim(str_replace([',', '₱', ' '], '', (string) $value));
        if ($text === '') {
            return null;
        }
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $text)) {
            return false;
        }
        $number = (float) $text;

        return $number <= self::MAX_AMOUNT ? $number : false;
    }

    /**
     * The price range a listing page was asked for (price_min / price_max), ignoring anything that is not a
     * sensible number.
     *
     * @return array{0: ?float, 1: ?float}
     */
    public static function bounds(Request $request): array
    {
        $min = self::amount($request->input('price_min'));
        $max = self::amount($request->input('price_max'));

        return [$min === false ? null : $min, $max === false ? null : $max];
    }

    /** "₱1,500" for a chip. */
    public static function peso(float $amount): string
    {
        return '₱'.number_format($amount, $amount == floor($amount) ? 0 : 2);
    }
}
