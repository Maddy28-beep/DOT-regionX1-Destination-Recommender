<?php

namespace App\Support;

/**
 * The tourism niche DOT Region XI gave each province and city under its "Davao: Diversity is our Beauty" branding (2023).
 * Shown beside the province name on the listing pages and on a destination, so each place carries its own line.
 */
class ProvinceBrand
{
    public const REGION_LINE = 'Davao: Diversity is our Beauty';

    /** @var array<string, string> */
    public const NICHES = [
        'Davao City' => 'A fun place to be in',
        'Davao Oriental' => 'Adventure and thrill-seeking',
        'Davao de Oro' => 'A place to reset, known for wellness',
        'Davao del Norte' => 'The eco-tourism hub',
        'Davao del Sur' => 'A food and coffee haven',
        'Davao Occidental' => 'Where nature meets culture',
    ];

    public static function for(?string $regionName): ?string
    {
        return $regionName !== null ? (self::NICHES[$regionName] ?? null) : null;
    }
}
