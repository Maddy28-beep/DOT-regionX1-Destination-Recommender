<?php

namespace App\Support;

/**
 * The one list of amenities and accessibility features every listing draws from.
 *
 * A key means the same thing everywhere ("parking" on a destination is "parking" on a restaurant), and each kind of
 * listing is only offered the choices that make sense for it. The labels of the first seven are the strings the trip
 * planner and the recommender have always used, so nothing already saved stops matching.
 *
 * Packages are trips, not places: instead of amenities they have who the trip suits and what is on the trip.
 */
final class Amenities
{
    public const FACILITY = 'f';

    public const ACCESS = 'a';

    public const WHO = 'w';

    public const TRIP = 't';

    /** key => [label, group] */
    public const CATALOG = [
        'parking' => ['Parking Area', self::FACILITY], 'restroom' => ['Restroom', self::FACILITY], 'restaurant' => ['Restaurant', self::FACILITY],
        'wifi' => ['Wi-Fi', self::FACILITY], 'pool' => ['Swimming Pool', self::FACILITY], 'ac' => ['Air Conditioning', self::FACILITY],
        'info' => ['Information desk', self::FACILITY], 'lockers' => ['Lockers', self::FACILITY], 'picnic' => ['Picnic area', self::FACILITY],
        'outdoor' => ['Outdoor seating', self::FACILITY], 'kids' => ['Kid-friendly', self::FACILITY], 'airport' => ['Airport pickup', self::FACILITY],
        'gcash' => ['GCash or card payment', self::FACILITY],
        'ramp' => ['Accessibility Ramp', self::ACCESS], 'stepfree' => ['Step-free entrance', self::ACCESS], 'accroom' => ['Accessible room', self::ACCESS],
        'elevator' => ['Elevator', self::ACCESS], 'accwc' => ['Accessible restroom', self::ACCESS], 'firstaid' => ['First aid on site', self::ACCESS],
        // packages
        'wheel' => ['Wheelchair or step-free', self::WHO], 'seniors' => ['Suitable for seniors (65+)', self::WHO],
        'children' => ['Suitable for children under 12', self::WHO], 'pregnant' => ['Suitable for pregnant travellers', self::WHO],
        'route_rest' => ['Restrooms along the route', self::TRIP], 'route_water' => ['Drinking water provided', self::TRIP],
        'route_firstaid' => ['First aid kit and a trained guide', self::TRIP], 'route_lift' => ['Vehicle with a ramp or lift', self::TRIP],
    ];

    /** The choices each kind of listing is offered, in the order they are shown. Tour operators have none. */
    public const FOR_KIND = [
        'destination' => ['parking', 'restroom', 'restaurant', 'wifi', 'pool', 'ac', 'info', 'lockers', 'picnic', 'ramp', 'accwc', 'firstaid'],
        'accommodation' => ['wifi', 'pool', 'ac', 'parking', 'restaurant', 'airport', 'accroom', 'elevator', 'ramp', 'accwc'],
        'restaurant' => ['parking', 'restroom', 'ac', 'wifi', 'outdoor', 'kids', 'stepfree', 'accwc'],
        'souvenir_center' => ['parking', 'restroom', 'ac', 'gcash', 'stepfree', 'accwc'],
        'package' => ['wheel', 'seniors', 'children', 'pregnant', 'route_rest', 'route_water', 'route_firstaid', 'route_lift'],
    ];

    /** What the trip planner asks a traveller to choose from: the same list, the part a destination can answer. */
    public const PLANNER = ['parking', 'restaurant', 'pool', 'wifi', 'restroom', 'ramp', 'ac'];

    public const DIFFICULTY = [
        'easy' => ['Easy', 'Mostly flat, short walks. Most travellers manage it.'],
        'moderate' => ['Moderate', 'Some climbing or long walking. Good general fitness helps.'],
        'hard' => ['Challenging', 'Steep or long. Fitness and experience needed.'],
    ];

    /** Enough ticked amenities for a listing to count as having filled them in. */
    public const ENOUGH = 4;

    public static function hasList(string $kind): bool
    {
        return isset(self::FOR_KIND[$kind]);
    }

    /** @return array<int, string> */
    public static function keysFor(string $kind, ?string $group = null): array
    {
        return array_values(array_filter(self::FOR_KIND[$kind] ?? [], fn (string $k) => $group === null || self::CATALOG[$k][1] === $group));
    }

    public static function label(string $key): string
    {
        return self::CATALOG[$key][0] ?? $key;
    }

    public static function group(string $key): ?string
    {
        return self::CATALOG[$key][1] ?? null;
    }

    /** @return array<string, string> label => key, for reading values saved as labels (destination tags, the planner) */
    public static function keysByLabel(): array
    {
        return array_flip(array_map(fn (array $c) => $c[0], self::CATALOG));
    }

    /** @return array<int, string> the planner's choices as the labels it has always stored */
    public static function plannerLabels(): array
    {
        return array_map(fn (string $k) => self::label($k), self::PLANNER);
    }

    public static function allowed(string $kind, ?array $keys): array
    {
        return array_values(array_intersect(self::FOR_KIND[$kind] ?? [], (array) $keys));
    }
}
