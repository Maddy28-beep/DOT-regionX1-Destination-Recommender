<?php

namespace App\Support;

/**
 * The small icon in front of each category chip on the listing pages (destination types, kinds of stay, cuisines, package types,
 * operator specializations). The categories come from the data, so the icon is chosen from the words in the name; the first
 * keyword that matches wins, and anything unrecognised gets the page's fallback icon (a plate for dining, a compass for operators,
 * otherwise a tag).
 */
final class CategoryIcons
{
    /** keyword (lower case) => icon name, most specific first. */
    private const KEYWORDS = [
        // stays
        'condominium' => 'key', 'apartment' => 'building', 'hostel' => 'users', 'homestay' => 'home', 'villa' => 'home', 'pension' => 'key', 'inn' => 'key',
        'all inclusive' => 'sparkles', 'all-inclusive' => 'sparkles', 'hot spring' => 'pool', 'lake' => 'wind', 'mountain resort' => 'mountain',
        'inland' => 'leaf', 'island resort' => 'umbrella', 'beach resort' => 'umbrella', 'resort' => 'pool', 'hotel' => 'building',
        // destinations and packages
        'hiking' => 'boot', 'trek' => 'boot', 'surf' => 'wind', 'beach' => 'umbrella', 'island' => 'umbrella', 'cruise' => 'umbrella',
        'adventure' => 'mountain', 'nature' => 'mountain', 'mountain' => 'mountain', 'highland' => 'mountain',
        'culture' => 'heritage-building', 'cultural' => 'heritage-building', 'heritage' => 'heritage-building', 'museum' => 'heritage-building', 'history' => 'heritage-building',
        'religio' => 'church', 'church' => 'church', 'wellness' => 'heart', 'spa' => 'heart', 'health' => 'heart',
        'farm' => 'leaf', 'eco' => 'leaf', 'conservation' => 'leaf', 'garden' => 'leaf', 'park' => 'leaf', 'wildlife' => 'feather',
        'event' => 'calendar', 'convention' => 'calendar', 'festival' => 'calendar', 'mice' => 'briefcase',
        'shopping' => 'bag', 'souvenir' => 'bag', 'food' => 'utensils', 'theme' => 'ticket', 'city' => 'building', 'urban' => 'building',
        // transport and tours
        'transport' => 'van', 'bus' => 'bus', 'van' => 'van', 'general' => 'compass', 'tour' => 'compass', 'travel' => 'compass',
        // dining
        'grill' => 'utensils', 'bar' => 'moon', 'nightlife' => 'moon', 'cafe' => 'coffee', 'café' => 'coffee', 'coffee' => 'coffee', 'seafood' => 'fish', 'japanese' => 'bowl', 'filipino' => 'utensils',
    ];

    public static function for(string $label, string $fallback = 'tag'): string
    {
        $name = mb_strtolower(trim($label));

        // "All Types", "All Cuisines": the reset chip (but "All Inclusive Resort" is a kind of stay).
        if (preg_match('/^all\b/u', $name) && ! str_contains($name, 'inclusive')) {
            return 'grid';
        }

        foreach (self::KEYWORDS as $keyword => $icon) {
            // At the start of a word, so "inn" is not found inside "dining".
            if (preg_match('/\b'.preg_quote($keyword, '/').'/u', $name)) {
                return $icon;
            }
        }

        return $fallback;
    }
}
