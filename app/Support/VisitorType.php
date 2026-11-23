<?php

namespace App\Support;

use App\Support\Geo\PhProvinces;

final class VisitorType
{
    public const FIRST = 'First-time Visitor';
    public const RETURNING = 'Returning Visitor';
    public const RESIDENT = 'Davao Region Resident';
    public const LEGACY_LOCAL = 'Regular / Local';

    public static function hasDavaoOrigin(?string $country, ?string $province): bool
    {
        return strtoupper(trim((string) $country)) === 'PH'
            && PhProvinces::regionOf(trim((string) $province)) === 'Region XI (Davao Region)';
    }

    public static function options(bool $originFlow): array
    {
        return [self::FIRST, self::RETURNING, $originFlow ? self::RESIDENT : self::LEGACY_LOCAL];
    }

    public static function isLocal(?string $value): bool
    {
        return in_array($value, [self::RESIDENT, self::LEGACY_LOCAL], true);
    }

    /** Preserve old trips without continuing to describe frequent nonlocal visitors as residents. */
    public static function forDisplay(?string $value, ?string $country, ?string $province): ?string
    {
        $eligible = self::hasDavaoOrigin($country, $province);
        if ($value === self::LEGACY_LOCAL) return $eligible ? self::RESIDENT : self::RETURNING;
        if ($value === self::RESIDENT && !$eligible) return null;
        return $value;
    }
}
