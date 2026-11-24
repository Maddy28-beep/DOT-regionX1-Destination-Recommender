<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * The search box on the listing pages, read as keywords instead of one exact phrase.
 *
 *  - Budget words become the page's own budget filter, so "free beach", "mura na resort", "under 500" or "₱300 pababa"
 *    work without opening the Budget dropdown. They only fill a budget the visitor has not already chosen.
 *  - The remaining words are matched one by one (every word must match), each in any of the page's text columns (name,
 *    location, type or cuisine, description), and each together with its common equivalents ("waterfall" also finds
 *    "Falls", "kape" also finds "Cafe"). Small connecting words ("in", "the", "sa", "ng") are ignored.
 *  - Unless the visitor picked a sort order, places whose name carries the words come first.
 *
 * Everything is case-insensitive and treats % and _ as ordinary characters, like SearchTerm.
 */
final class KeywordSearch
{
    private const ESCAPE = '!';

    /** At most this many words are used, so a pasted paragraph cannot build a huge query. */
    private const MAX_WORDS = 6;

    /** Words that carry no meaning on their own in a search (English and Filipino/Bisaya). */
    private const STOPWORDS = [
        'a', 'an', 'and', 'at', 'best', 'for', 'good', 'in', 'near', 'nearby', 'nice', 'of', 'on', 'or', 'place', 'places',
        'spot', 'spots', 'the', 'to', 'tourist', 'with', 'ang', 'mga', 'na', 'ng', 'nga', 'sa', 'si', 'malapit', 'dito', 'diha',
    ];

    /** Groups of words that mean the same thing in a search. A word matches when any member of its group does. */
    private const EQUIVALENTS = [
        ['falls', 'waterfall', 'waterfalls', 'talon', 'busay'],
        ['beach', 'beaches', 'baybayon', 'shore', 'dagat'],
        ['island', 'islands', 'isla', 'islet'],
        ['mountain', 'mount', 'mt', 'bukid', 'peak', 'highland', 'highlands'],
        ['hotel', 'inn', 'lodge', 'lodging', 'hostel', 'pension', 'suites', 'hotels'],
        ['resort', 'resorts'],
        ['church', 'cathedral', 'shrine', 'simbahan', 'chapel'],
        ['museum', 'museo'],
        ['park', 'parke', 'plaza'],
        ['coffee', 'kape', 'cafe', 'café', 'coffeeshop'],
        ['restaurant', 'resto', 'kainan', 'eatery', 'dining', 'food', 'pagkaon'],
        ['seafood', 'fish', 'isda', 'tuna'],
        ['grill', 'bbq', 'barbecue', 'inihaw', 'sugba'],
        ['pizza', 'italian'],
        ['spring', 'springs', 'bukal'],
        ['cave', 'caves', 'kweba', 'langob'],
        ['market', 'merkado', 'tiangge', 'palengke'],
        ['souvenir', 'souvenirs', 'pasalubong'],
        ['zoo', 'wildlife', 'animals', 'animal'],
        ['farm', 'farms', 'agri', 'agritourism', 'uma'],
        ['river', 'suba', 'rafting', 'tubing'],
        ['view', 'viewdeck', 'viewpoint', 'overlook', 'tanaw'],
        ['pool', 'pools', 'swimming', 'swim', 'langoy'],
        ['spa', 'massage', 'wellness', 'hilot'],
        ['heritage', 'historical', 'history', 'old', 'kasaysayan'],
    ];

    /** Words that ask for the low, middle or high end of a catalogue's prices. */
    private const CHEAP = ['cheap', 'cheapest', 'mura', 'barato', 'budget', 'affordable', 'tipid', 'inexpensive', 'low-cost', 'lowcost'];

    private const MIDDLE = ['mid-range', 'midrange', 'moderate', 'katamtaman'];

    private const PRICEY = ['luxury', 'luxurious', 'premium', 'upscale', 'high-end', 'highend', 'expensive', 'mahal', 'fancy'];

    /** Words that ask for no entry fee (destinations only: elsewhere "free" usually means "free breakfast" or "free wifi"). */
    private const FREE = ['free', 'libre', 'walang bayad', 'no entrance fee', 'no entry fee', 'free entrance', 'free entry'];

    /**
     * Reads the search box of a listing page: fills the page's budget from budget words (when none was chosen) and leaves
     * only the remaining words in "q". Call it before the page builds its query.
     *
     * @param  string  $purpose  the catalogue, as BudgetRanges names it (destinations, accommodations, accommodations_day_use,
     *                           packages, restaurants) or another name for a catalogue with no budget
     */
    public static function prepare(Request $request, string $purpose): void
    {
        $text = trim((string) $request->input('q'));
        if ($text === '') {
            return;
        }

        $parsed = self::parse($text, $purpose);
        $chosen = $request->filled('price_min') || $request->filled('price_max') || $request->filled('price_tier');
        if (! $chosen) {
            $budget = array_filter($parsed['budget'], fn ($value) => $value !== null);
            if ($budget !== []) {
                $request->merge($budget);
            }
        }
        // The words keep the visitor's own spelling (capitals included) in the box and in the popular-searches count.
        $typed = [];
        foreach (preg_split('/[\s,;\/]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            $typed[mb_strtolower(trim($token, " .!?\"'()[]"))] ??= trim($token, " .!?\"'()[]");
        }
        $request->merge(['q' => implode(' ', array_map(fn ($word) => $typed[$word] ?? $word, $parsed['words']))]);
    }

    /**
     * @return array{words: list<string>, budget: array{price_min: ?float, price_max: ?float, price_tier: ?string}}
     */
    public static function parse(string $text, string $purpose): array
    {
        $text = mb_strtolower(trim($text));
        $text = (string) preg_replace('/(\d),(\d{3})/u', '$1$2', $text);   // 1,500 -> 1500
        $text = str_replace(['₱', 'php ', ' pesos', ' peso'], [' ', ' ', ' ', ' '], $text);
        $budget = ['price_min' => null, 'price_max' => null, 'price_tier' => null];
        $pesos = BudgetRanges::for($purpose) !== null;

        // Amounts: "under 500", "below 1000", "500 pababa", "300-800", "from 1000", "1000 pataas", or a lone "₱300".
        $n = '(\d{1,7})';
        $patterns = [
            ['/\b(?:under|below|less than|max|maximum|up to|upto|hanggang|within|not more than)\s*'.$n.'\b/u', 'max'],
            ['/\b'.$n.'\s*(?:pababa|and below|or less|below|max)\b/u', 'max'],
            ['/\b(?:above|over|more than|at least|from|min|minimum)\s*'.$n.'\b/u', 'min'],
            ['/\b'.$n.'\s*(?:pataas|and above|or more|up|plus)\b/u', 'min'],
            ['/\b'.$n.'\s*(?:-|to|hangtod)\s*'.$n.'\b/u', 'range'],
        ];
        foreach ($patterns as [$pattern, $kind]) {
            if (preg_match($pattern, $text, $m)) {
                $text = str_replace($m[0], ' ', $text);
                if (! $pesos) {
                    continue;
                }
                if ($kind === 'range') {
                    [$a, $b] = [(float) $m[1], (float) $m[2]];
                    $budget['price_min'] = min($a, $b);
                    $budget['price_max'] = max($a, $b);
                } else {
                    $budget[$kind === 'max' ? 'price_max' : 'price_min'] = (float) $m[1];
                }
            }
        }
        // A lone amount ("beach 300") reads as a maximum.
        if ($budget['price_min'] === null && $budget['price_max'] === null && preg_match('/(?<![\w.])(\d{2,7})(?![\w.])/u', $text, $m)) {
            $text = str_replace($m[0], ' ', $text);
            if ($pesos) {
                $budget['price_max'] = (float) $m[1];
            }
        }

        // Phrases first ("walang bayad", "no entry fee"), then single words.
        if ($purpose === 'destinations') {
            foreach (self::FREE as $phrase) {
                if (preg_match('/\b'.preg_quote($phrase, '/').'\b/u', $text)) {
                    $text = (string) preg_replace('/\b'.preg_quote($phrase, '/').'\b/u', ' ', $text);
                    $budget['price_min'] = 0.0;
                    $budget['price_max'] = 0.0;
                }
            }
        }

        $words = [];
        foreach (preg_split('/[\s,;\/]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $word = trim($word, " .!?\"'()[]");
            if ($word === '' || in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $level = match (true) {
                in_array($word, self::CHEAP, true) => 'low',
                in_array($word, self::MIDDLE, true) => 'middle',
                in_array($word, self::PRICEY, true) => 'high',
                default => null,
            };
            if ($level !== null) {
                // A price word fills only what an amount has not already set; a catalogue without prices just drops it.
                if ($budget['price_min'] === null && $budget['price_max'] === null && $budget['price_tier'] === null) {
                    $budget = self::level($purpose, $level);
                }

                continue;
            }
            $words[] = $word;
        }

        return ['words' => array_slice(array_values(array_unique($words)), 0, self::MAX_WORDS), 'budget' => $budget];
    }

    /**
     * Requires every word, each in any of the columns, with its equivalents. Unless $ranked is false, places whose name
     * carries more of the words are put first (call it before the page's own ordering).
     *
     * @param  list<string>  $columns
     */
    public static function apply(Builder $query, string $text, array $columns, bool $ranked = true): Builder
    {
        $words = array_slice(preg_split('/\s+/u', mb_strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, self::MAX_WORDS);
        if ($words === []) {
            return $query;
        }

        foreach ($words as $word) {
            $variants = self::variants($word);
            $query->where(function ($inner) use ($variants, $columns) {
                foreach ($columns as $column) {
                    foreach ($variants as $variant) {
                        $inner->orWhereRaw('LOWER('.$column.') LIKE ? ESCAPE \''.self::ESCAPE.'\'', ['%'.self::escape($variant).'%']);
                    }
                }
            });
        }

        if ($ranked) {
            $parts = [];
            $bindings = [];
            foreach ($words as $word) {
                $cases = [];
                foreach (self::variants($word) as $variant) {
                    $cases[] = 'LOWER('.$columns[0].') LIKE ? ESCAPE \''.self::ESCAPE.'\'';
                    $bindings[] = '%'.self::escape($variant).'%';
                }
                $parts[] = '(CASE WHEN '.implode(' OR ', $cases).' THEN 1 ELSE 0 END)';
            }
            $query->orderByRaw('('.implode(' + ', $parts).') DESC', $bindings);
        }

        return $query;
    }

    /** @return list<string> the word, its singular form and its equivalents */
    public static function variants(string $word): array
    {
        $forms = [$word];
        if (mb_strlen($word) > 4 && str_ends_with($word, 's')) {
            $forms[] = mb_substr($word, 0, -1);
        }
        foreach (self::EQUIVALENTS as $group) {
            if (array_intersect($forms, $group) !== []) {
                $forms = [...$forms, ...$group];
            }
        }

        return array_values(array_unique($forms));
    }

    /** @return array{price_min: ?float, price_max: ?float, price_tier: ?string} what "cheap", "mid-range" or "luxury" means for a catalogue */
    private static function level(string $purpose, string $level): array
    {
        $none = ['price_min' => null, 'price_max' => null, 'price_tier' => null];

        if ($purpose === 'restaurants') {
            return ['price_tier' => ['low' => 'Budget-Friendly', 'middle' => 'Mid-range', 'high' => 'Premium'][$level]] + $none;
        }

        $brackets = BudgetRanges::for($purpose)['brackets'] ?? [];
        if ($purpose === 'destinations') {
            // Skip "Free entry" for "cheap": cheap includes free and low fees, up to the second bracket.
            $brackets = array_values(array_filter($brackets, fn ($b) => $b[0] !== 'free'));
            if ($level === 'low') {
                return ['price_min' => 0.0, 'price_max' => $brackets[0][3] ?? null] + $none;
            }
        }
        if ($brackets === []) {
            return $none;
        }
        // Low: the first bracket. High: the last. Middle: the one after the first (₱101–₱300, ₱1,500–₱2,500, ...).
        $bracket = match ($level) {
            'low' => $brackets[0],
            'high' => $brackets[count($brackets) - 1],
            default => $brackets[min(1, count($brackets) - 1)],
        };

        // Whole pesos in the chip ("Up to ₱1,500", not "₱1,499.99"): "cheap" is a rough word, not an exact bracket.
        return ['price_min' => $bracket[2] === null ? null : floor($bracket[2]), 'price_max' => $bracket[3] === null ? null : ceil($bracket[3])] + $none;
    }

    private static function escape(string $term): string
    {
        return str_replace([self::ESCAPE, '%', '_'], [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'], $term);
    }
}
