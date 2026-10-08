<?php

namespace App\Support;

use Illuminate\Http\Request;
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
    /** Labels for the budget tiles; the peso signs match the tiles in the filter panel. */
    public const TIER_LABELS = [
        'Free' => 'Free',
        'Budget-Friendly' => 'Budget ₱',
        'Mid-range' => 'Mid-range ₱₱',
        'Premium' => 'Premium ₱₱₱',
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

        return $filters->filter(fn ($label) => $label !== null && $label !== '');
    }
}
