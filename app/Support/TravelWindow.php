<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * The dates of the trip being planned right now.
 *
 * ItineraryGenerationService sets it for the length of one generate() call, so
 * every place it picks along the way (accommodation, an association-rule
 * restaurant, a souvenir stop) is checked against the same dates without
 * threading them through every method. Outside a trip it is empty and the
 * "available for the trip" scopes do nothing, so public pages and admin
 * reports are unaffected.
 */
final class TravelWindow
{
    /** @var array{0: Carbon, 1: Carbon}|null */
    private static ?array $current = null;

    public static function set(Carbon $from, Carbon $to): void
    {
        self::$current = [$from->copy()->startOfDay(), $to->copy()->startOfDay()];
    }

    public static function clear(): void
    {
        self::$current = null;
    }

    /** @return array{0: Carbon, 1: Carbon}|null */
    public static function current(): ?array
    {
        return self::$current;
    }
}
