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
     * Verified resort setting classifications for the 69 DOT Region XI resorts in the catalogue.
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
        'discovery-samal' => ['Beach', 'Island', 'All-Inclusive'],
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
        'd-leonor-inland-resort-adventure-park' => ['Inland', 'Lake'],
        'eden-mountain-resort' => ['Mountain', 'Inland', 'Lake', 'All-Inclusive'],
        'hidden-gardens-resort-davao' => ['Mountain', 'Inland'],
        'jack-s-in-the-sky-mountain-resort' => ['Mountain'],
        'loleng-s-mountain-resort' => ['Mountain', 'Inland'],
        'malagos-garden-resort' => ['Inland', 'All-Inclusive'],
        'mergrande-beach-resort' => ['Beach'],
        'villa-carmelita-inland-resort-and-hotel' => ['Inland'],

        // ── Davao del Sur Mountain, Hot Spring & Inland Resorts (Mt. Apo / Kapatagan / Bansalan) ──
        'haven-s-dew-resort-opc' => ['Mountain', 'Inland'],
        'montefrio-garden-resort' => ['Mountain', 'Hot Spring'],
        'twin-mountain-view-resort' => ['Mountain'],

        // ── Davao de Oro Island, Beach, Mountain, Hot Spring & Inland Resorts ──
        'aguakan-resort' => ['Mountain', 'Inland', 'Hot Spring'],
        'ayuste-highland-mountain-resort' => ['Mountain'],
        'coral-dive-beach-resort' => ['Beach'],
        'dusit-thani-lubi-plantation-resort' => ['Beach', 'Island', 'All-Inclusive'],
        'magnaga-waters-beach-resort' => ['Beach'],
        'odyson-s-mountain-resort' => ['Mountain'],

        // ── Davao del Norte Plantation, Lagoon & Beach Resorts ──
        'hijo-resorts-davao' => ['Beach', 'Inland', 'Lake'],

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

        // Only a plain resort is called inland, and only if nothing in its text points at the coast.
        if ($found === [] && strcasecmp((string) $type, 'Resort') === 0 && ! preg_match(self::COASTAL, $text)) {
            $found[] = 'Inland';
        }

        return $found;
    }

    public function run(): void
    {
        Accommodation::query()
            ->where(fn ($q) => $q->whereIn('type', ['Resort', 'Beach Resort'])->orWhere('name', 'like', '%resort%'))
            ->get()
            ->each(function (Accommodation $stay) {
                if (isset(self::KNOWN_RESORT_SETTINGS[$stay->slug])) {
                    $encoded = Accommodation::encodeResortSettings(self::KNOWN_RESORT_SETTINGS[$stay->slug]);
                    if ($encoded !== null && $stay->resort_settings !== $encoded) {
                        $stay->forceFill(['resort_settings' => $encoded])->saveQuietly();
                    }

                    return;
                }

                if ($stay->resort_settings !== null) {
                    return;
                }

                $encoded = Accommodation::encodeResortSettings(self::settingsFor($stay->name, $stay->description, $stay->type));

                if ($encoded !== null) {
                    $stay->forceFill(['resort_settings' => $encoded])->saveQuietly();
                }
            });
    }
}
