<?php

namespace App\Support;

use App\Http\Controllers\SearchController;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The filters switched on in a catalogue request, as "Showing" chips: a label per filter, keyed by the
 * query-string parameter that a chip's "x" removes.
 *
 * It only describes what is in the URL (the controllers do the actual filtering), so every listing page
 * can share one set of chips and one "Show results (N filters)" count.
 */
class ActiveFilters
{
    /** Labels for the budget tiles: the band in words, as the filter panel names it (no peso signs). */
    public const TIER_LABELS = [
        'Free' => 'Free',
        'Budget-Friendly' => 'Budget-friendly',
        'Mid-range' => 'Mid-range',
        'Premium' => 'Premium',
    ];

    /**
     * @param  Collection  $regions  models with id and name, to turn region_id into a name
     * @param  string|null  $categoryParam  the page's category parameter ("type", "cuisine_type", ...)
     * @return Collection<string, string> parameter => label, only for filters that are set
     */
    public static function from(Request $request, Collection $regions, ?string $categoryParam = null): Collection
    {
        $filters = collect([
            'q' => $request->filled('q') ? 'Search: '.$request->input('q') : null,
            'region_id' => $request->filled('region_id') ? $regions->firstWhere('id', (int) $request->input('region_id'))?->name : null,
            'price_tier' => $request->filled('price_tier')
                ? (self::TIER_LABELS[$request->input('price_tier')] ?? $request->input('price_tier'))
                : null,
        ]);

        if ($categoryParam) {
            $filters->put($categoryParam, $request->filled($categoryParam) ? (string) $request->input($categoryParam) : null);
        }

        $filters->put('interest', $request->filled('interest') ? 'Interest: '.$request->input('interest') : null);

        // What the hero search's per-catalogue boxes can add (see SearchController).
        [$min, $max] = BudgetRanges::bounds($request);
        $filters->put('price_min', $min !== null ? 'From '.BudgetRanges::peso($min) : null);
        $filters->put('price_max', $max !== null ? 'Up to '.BudgetRanges::peso($max) : null);
        $filters->put('stay_mode', SearchController::STAY_MODES[(string) $request->input('stay_mode')] ?? null);
        $filters->put('from', self::dateLabel($request->input('from'), 'Check-in'));
        $filters->put('to', self::dateLabel($request->input('to'), 'Check-out'));
        foreach (['adults' => 'adult', 'children' => 'child', 'seniors' => 'senior citizen', 'pwd' => 'PWD'] as $key => $noun) {
            $n = (int) $request->input($key);
            $filters->put($key, $n > 0 ? $n.' '.($key === 'children' ? 'children' : ($n === 1 || $key === 'pwd' ? $noun : $noun.'s')) : null);
        }
        $filters->put('meal', MealTimes::label((string) $request->input('meal')) ? 'Meal: '.MealTimes::label((string) $request->input('meal')) : null);
        $filters->put('date', self::dateLabel($request->input('date'), 'Date'));
        $travelers = (int) $request->input('travelers');
        $filters->put('travelers', $travelers > 1 ? $travelers.' travelers' : null);
        $filters->put('duration', match ((string) $request->input('duration')) {
            '1-2' => 'Trip: 1–2 days', '3-4' => 'Trip: 3–4 days', '5-plus' => 'Trip: 5+ days', default => null,
        });

        return $filters->filter(fn ($label) => $label !== null && $label !== '');
    }

    /**
     * The hero-search answers a page's own filter form has no box for, as hidden fields, so applying a filter
     * there keeps them (a price range, the dates and guests, a meal).
     *
     * @return list<array{name: string, value: string}>
     */
    public static function carry(Request $request, array $keys): array
    {
        $fields = [];
        foreach ($keys as $key) {
            if ($request->filled($key) && is_scalar($request->input($key))) {
                $fields[] = ['name' => $key, 'value' => (string) $request->input($key)];
            }
        }

        return $fields;
    }

    private static function dateLabel(mixed $value, string $prefix): ?string
    {
        $text = (string) $value;
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            return null;
        }
        try {
            return $prefix.': '.Carbon::createFromFormat('Y-m-d', $text)->format('j M Y');
        } catch (\Throwable) {
            return null;
        }
    }
}
