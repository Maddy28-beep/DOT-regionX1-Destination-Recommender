<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Runtime feature switches. A row in `feature_flags` wins; without one the value in config/features.php is used.
 * A missing table or any database error reads as "use the config default", so a half-migrated server never breaks a page.
 */
final class Features
{
    public const TRIP_LOCATION_V2 = 'trip_location_v2';

    public static function on(string $name): bool
    {
        try {
            if (Schema::hasTable('feature_flags')) {
                $row = DB::table('feature_flags')->where('name', $name)->first();
                if ($row !== null) {
                    return (bool) $row->enabled;
                }
            }
        } catch (Throwable) {
            // fall through to the default
        }

        return (bool) config('features.'.$name, false);
    }

    public static function tripLocation(): bool
    {
        return self::on(self::TRIP_LOCATION_V2);
    }

    /** Names that exist, so a typo in the artisan command cannot create a flag nothing reads. */
    public static function known(): array
    {
        return array_keys((array) config('features', []));
    }
}
