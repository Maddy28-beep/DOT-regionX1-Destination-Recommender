<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use Illuminate\Database\Seeder;

/**
 * Tags each resort with the setting(s) it is in -- Mountain, Inland, Island, Lake, Hot Spring, All-Inclusive.
 *
 * The catalogue never recorded this, so the only evidence is what an establishment says about itself: its
 * name and its description. A word is only ever read as the setting it names ("Lake" in "Lake Sebu Resort",
 * "all inclusive" in a description), the same discipline MabuhayAccommodationTypeSeeder applies to lodging
 * types. Anything the text does not say is left null -- "not classified yet" -- and never guessed, so a
 * traveller asking for a Mountain resort is not handed one that merely sounds like it.
 *
 * Inland is the one inference: a plain Resort (not a Beach Resort) whose text names no coast, island or other
 * setting is taken to be away from the sea. It is a weaker claim than the rest, which is why it is only made
 * when nothing contradicts it.
 *
 * Idempotent, and only fills rows still unclassified, so a hand-corrected value is never overwritten.
 */
class ResortSettingSeeder extends Seeder
{
    /** Ordered as they are shown; the stored string follows this order too. */
    private const PATTERNS = [
        'Mountain' => '/\b(mountain|mount|mt\.?|highland|highlands|hilltop|ridge|peak|upland)\b/i',
        'Island' => '/\b(island|isla|samal|talikud|pearl\s+farm)\b/i',
        'Lake' => '/\b(lake|lawa)\b/i',
        'Hot Spring' => '/\bhot[\s-]?springs?\b/i',
        'All-Inclusive' => '/\ball[\s-]?inclusive\b/i',
    ];

    /** Words that put a resort at the coast, which rules out calling it Inland. */
    private const COASTAL = '/\b(beach|island|isla|sea|bay|coast|coastal|cove|shore|gulf|samal|dahican|mati|waterfront|seaside|oceanfront|surf)\b/i';

    /**
     * The settings a resort's own words support, in display order.
     *
     * @return list<string>
     */
    public static function settingsFor(string $name, ?string $description, ?string $type): array
    {
        $text = trim($name.' '.$description);
        $found = [];

        foreach (self::PATTERNS as $setting => $pattern) {
            if (preg_match($pattern, $text)) {
                $found[] = $setting;
            }
        }

        // Only a plain resort is called inland, and only if nothing in its text points at the coast.
        if ($found === [] && strcasecmp((string) $type, 'Resort') === 0 && ! preg_match(self::COASTAL, $text)) {
            $found[] = 'Inland';
        }

        return $found;
    }

    public function run(): void
    {
        Accommodation::query()
            ->whereNull('resort_settings')
            ->where(fn ($q) => $q->whereIn('type', ['Resort', 'Beach Resort'])->orWhere('name', 'like', '%resort%'))
            ->get()
            ->each(function (Accommodation $stay) {
                $encoded = Accommodation::encodeResortSettings(self::settingsFor($stay->name, $stay->description, $stay->type));

                if ($encoded !== null) {
                    $stay->forceFill(['resort_settings' => $encoded])->saveQuietly();
                }
            });
    }
}
