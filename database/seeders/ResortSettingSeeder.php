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
 * Inland requires explicit text or a reviewed catalogue classification. Missing coastal information
 * is not evidence of an inland location. Dated corrections and sources are recorded in
 * database/data/resort-setting-corrections-2026-11-01.json.
 *
 * Idempotent, and only fills rows still unclassified, so a hand-corrected value is never overwritten.
 */
class ResortSettingSeeder extends Seeder
{
    /** Ordered as they are shown; the stored string follows this order too. */
    private const PATTERNS = [
        'Inland' => '/\binland\b/i',
        'Mountain' => '/\b(mountain|mount|mt\.?|highland|highlands|hilltop|ridge|peak|upland)\b/i',
        'Island' => '/\b(island|isla|samal|talikud|pearl\s+farm)\b/i',
        'Lake' => '/\b(lake|lawa)\b/i',
        'Hot Spring' => '/\bhot[\s-]?springs?\b/i',
        'All-Inclusive' => '/\ball[\s-]?inclusive\b/i',
    ];

    /**
     * Catalogue setting defaults. These only initialize unclassified resorts, never replace saved values.
     * The dated evidence manifest documents the reviewed corrections; this list is not DOT certification.
     *
     * Keyed by slug so each resort is classified into its true geographic / amenity setting(s):
     * Beach, Mountain, Inland, Island, Lake, Hot Spring, All-Inclusive.
     *
     * @var array<string, list<string>>
     */
    public const KNOWN_RESORT_SETTINGS = [
        // ── Samal & Talicud Island Beach / Island / All-Inclusive / Inland Resorts ──
        '3kings-beach-resort' => ['Beach', 'Island'],
        'adecor-garden-resort' => ['Inland', 'Island'],
        'altavista-beach-resort' => ['Beach', 'Island'],
        'bluejaz-beach-resort' => ['Beach', 'Island'],
        'buenavista-island-resort' => ['Beach', 'Island', 'All-Inclusive'],
        'camp-holiday-resort-and-recreation-area' => ['Beach', 'Island'],
        'casa-tanaw-inland-resort' => ['Inland', 'Island'],
        'cavanico-il-mare-resort' => ['Beach', 'Island'],
        'chema-s-by-the-sea' => ['Beach', 'Island'],
        'club-samal-resorts-development-inc' => ['Beach', 'Island'],
        'costa-azalea' => ['Beach', 'Island'],
        'costa-marina-beach-resort' => ['Beach', 'Island'],
        'discovery-samal' => ['Beach', 'Island'],
        'eagles-nest-beach-resort' => ['Beach', 'Island'],
        'funventure-talicud-beach-resort' => ['Beach', 'Island'],
        'georgia-s-samal-resort' => ['Inland', 'Island'],
        'heavenly-beach-resort' => ['Beach', 'Island'],
        'hof-gorei-beach-resort' => ['Beach', 'Island'],
        'holiday-oceanview-beach-resort' => ['Beach', 'Island'],
        'island-garden-beach-resort' => ['Beach', 'Island'],
        'la-florenda-beach-resort' => ['Beach', 'Island'],
        'leticia-by-the-sea' => ['Beach', 'Island'],
        'lyr-beach-resort-corporation' => ['Beach', 'Island'],
        'mahan-garden-resort' => ['Inland', 'Island'],
        'paradise-island-park-and-beach-resort' => ['Beach', 'Island'],
        'paradise-island-park-beach-resort' => ['Beach', 'Island'],
        'pearl-farm-beach-resort' => ['Beach', 'Island', 'All-Inclusive'],
        'rainbow-breeze-beach-resort' => ['Beach', 'Island'],
        'rmpc-beach-resort' => ['Beach', 'Island'],
        'seashell-island-beach-resort' => ['Beach', 'Island'],
        'stream-valley-nature-s-park' => ['Inland', 'Island'],
        'swissfinity-beach-resort' => ['Beach', 'Island'],
        'tlsy-beach-front-resort' => ['Beach', 'Island'],
        'villa-amparo-garden-beach-resort' => ['Beach', 'Island'],

        // ── Davao City Mountain, Inland, Lake & Beach Resorts ──
        'catigan-mountain-breeze-resort' => ['Mountain', 'Inland'],
        'd-japanese-tunnel-family-resort-and-restaurant' => ['Inland'],
        'd-leonor-inland-resort-adventure-park' => ['Inland'],
        'eden-mountain-resort' => ['Mountain', 'Inland'],
        'hidden-gardens-resort-davao' => ['Mountain', 'Inland'],
        'jack-s-in-the-sky-mountain-resort' => ['Mountain'],
        'loleng-s-mountain-resort' => ['Mountain', 'Inland'],
        'malagos-garden-resort' => ['Inland'],
        'mergrande-beach-resort' => ['Beach'],
        'villa-carmelita-inland-resort-and-hotel' => ['Inland'],

        // ── Davao del Sur Mountain, Hot Spring & Inland Resorts (Mt. Apo / Kapatagan / Bansalan) ──
        'haven-s-dew-resort-opc' => ['Mountain', 'Inland'],
        'montefrio-garden-resort' => ['Mountain'],
        'twin-mountain-view-resort' => ['Mountain'],

        // ── Davao de Oro Island, Beach, Mountain, Hot Spring & Inland Resorts ──
        'aguakan-resort' => ['Inland'],
        'ayuste-highland-mountain-resort' => ['Mountain'],
        'coral-dive-beach-resort' => ['Beach'],
        'dusit-thani-lubi-plantation-resort' => ['Beach', 'Island'],
        'magnaga-waters-beach-resort' => ['Beach'],
        'odyson-s-mountain-resort' => ['Mountain'],

        // ── Davao del Norte Plantation, Lagoon & Beach Resorts ──
        'hijo-resorts-davao' => ['Beach'],

        // ── Davao Oriental Beach & Inland Resorts (Mati, Baganga, Cateel, Banaybanay, Gov. Generoso) ──
        'anrana-beach-resort' => ['Beach'],
        'baculin-beach-resort' => ['Beach'],
        'dahican-beach-resort-and-spa' => ['Beach'],
        'daya-adventure-resort-hotel' => ['Inland'],
        'debonaires-beach-resort' => ['Beach'],
        'estella-beach-n-star-resort' => ['Beach'],
        'gov-gen-beach-resort' => ['Beach'],
        'jillux-lodging-beach-resort' => ['Beach'],
        'langoyon-beach-resort' => ['Beach'],
        'moonlit-baganga-beach-resort' => ['Beach'],
        'playa-del-rosario-resort' => ['Beach'],
        'playa-del-rosario-resort-2' => ['Beach'],
        'primo-whitesand-beach-resort' => ['Beach'],
        'rustic-lounge-resort' => ['Beach'],
        'wind-and-waves-beach-resort' => ['Beach'],
    ];

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

        return $found;
    }

    public function run(): void
    {
        Accommodation::query()
            ->where(fn ($q) => $q->whereIn('type', ['Resort', 'Beach Resort'])->orWhere('name', 'like', '%resort%'))
            ->get()
            ->each(function (Accommodation $stay) {
                // A saved value (including an intentionally empty string) belongs to the editor.
                if ($stay->resort_settings !== null) {
                    return;
                }

                if (isset(self::KNOWN_RESORT_SETTINGS[$stay->slug])) {
                    $encoded = Accommodation::encodeResortSettings(self::KNOWN_RESORT_SETTINGS[$stay->slug]);
                    if ($encoded !== null) {
                        Accommodation::whereKey($stay->id)->whereNull('resort_settings')->update(['resort_settings' => $encoded]);
                    }

                    return;
                }

                $encoded = Accommodation::encodeResortSettings(self::settingsFor($stay->name, $stay->description, $stay->type));

                if ($encoded !== null) {
                    Accommodation::whereKey($stay->id)->whereNull('resort_settings')->update(['resort_settings' => $encoded]);
                }
            });
    }
}
