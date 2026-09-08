<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use Illuminate\Database\Seeder;

/**
 * Retypes the accommodations the DOT import filed as "Mabuhay Accommodation".
 *
 * That string is an accreditation CATEGORY, not a kind of lodging -- the
 * Mabuhay Accommodation Program covers small establishments of every sort, and
 * the category is already preserved in each record's accreditation number
 * (DOT-R11-MAB-01724-2026), so nothing is lost by taking it out of the `type`
 * column. Leaving it there did real damage: `type` is what the trip planner
 * filters on when a traveller states an accommodation preference, so all 143
 * of these were unreachable by anyone who asked for anything specific --
 * roughly two thirds of the accredited accommodation catalogue.
 *
 * Retyping them all as "Hotel" would have been worse than leaving them: 38
 * carry "Resort" in their own name, so a traveller asking for a Hotel would
 * have been offered Altavista Beach Resort -- the exact confusion this column
 * exists to prevent. Each record's name is therefore the only evidence used,
 * and it is only ever read for the words the establishment chose for itself.
 * Where a name says nothing either way (27 of them: "Casa Julieta", "Edar's
 * Place"), it falls back to Hotel as the least surprising general lodging.
 *
 * Idempotent, and scoped to rows still carrying the raw category string, so a
 * hand-corrected type is never overwritten.
 */
class MabuhayAccommodationTypeSeeder extends Seeder
{
    /** The raw accreditation-category string the DOT list uses for these. */
    public const RAW_CATEGORY = 'Mabuhay Accommodation';

    /**
     * Ordered patterns; first match wins. "Beach Resort" has to be tested
     * before the general resort rule, and the general resort rule before the
     * Hotel fallback, or a beach resort ends up filed as an inland one.
     */
    private const PATTERNS = [
        '/\b(homestay|home\s?stay|guest\s?house|bed\s+and\s+breakfast)\b/i' => 'Homestay',
        '/\bbeach\b.*\bresort\b|\bresort\b.*\bbeach\b/i' => 'Beach Resort',
        '/\bresorts?\b/i' => 'Resort',
    ];

    /**
     * The lodging type a record should carry, given the name it goes by.
     *
     * Anything that is not the raw category string is passed straight back:
     * the DOT list types most accommodations properly ("Hotel", "Resort"),
     * and only the Mabuhay rows need interpreting. Shared with
     * RealAccreditedEstablishmentSeeder so a fresh import and an existing
     * database resolve a name the same way.
     */
    public static function lodgingTypeFor(string $name, ?string $rawType): ?string
    {
        if ($rawType !== self::RAW_CATEGORY) {
            return $rawType;
        }

        foreach (self::PATTERNS as $pattern => $type) {
            if (preg_match($pattern, $name)) {
                return $type;
            }
        }

        // Hotels, inns, suites, pension houses and the merely unlabelled.
        return 'Hotel';
    }

    public function run(): void
    {
        Accommodation::where('type', self::RAW_CATEGORY)
            ->get()
            ->each(function (Accommodation $accommodation) {
                $accommodation->type = self::lodgingTypeFor($accommodation->name, self::RAW_CATEGORY);
                $accommodation->save();
            });
    }
}
