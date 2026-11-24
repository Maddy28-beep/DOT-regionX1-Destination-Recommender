<?php

namespace App\Http\Controllers;

use App\Models\Accommodation;
use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccommodationController extends Controller
{
    /** Wording that says a stay can be used for the day. See index(). */
    private const DAY_USE_PHRASES = ['day use', 'day-use', 'day tour', 'day pass', 'day guest'];

    public function index(Request $request): View
    {
        $query = Accommodation::publiclyVisible()->with('region', 'photos')->withCount('reviews');

        // Keywords, not one exact phrase: budget words fill the budget, the other words are matched in any of these columns.
        \App\Support\KeywordSearch::prepare($request, $request->input('stay_mode') === 'day_use' ? 'accommodations_day_use' : 'accommodations');
        if ($request->filled('q')) {
            \App\Support\KeywordSearch::apply($query, (string) $request->string('q'), ['accommodations.name', 'accommodations.location', 'accommodations.type', 'accommodations.description'], ! $request->filled('sort'));
        }

        if ($request->filled('region_id')) {
            $query->where('region_id', $request->integer('region_id'));
        }

        if ($request->filled('type')) {
            $typeFilter = $request->string('type')->toString();
            $settingMap = [
                'Beach Resort' => 'Beach',
                'Mountain Resort' => 'Mountain',
                'Inland Resort' => 'Inland',
                'Island Resort' => 'Island',
                'Lake Resort' => 'Lake',
                'Hot Spring Resort' => 'Hot Spring',
                'All Inclusive Resort' => 'All-Inclusive',
                'All-Inclusive Resort' => 'All-Inclusive',
            ];
            if (isset($settingMap[$typeFilter])) {
                $query->whereIn('type', ['Resort', 'Beach Resort'])->withResortSetting($settingMap[$typeFilter]);
            } elseif ($typeFilter === 'Resort') {
                $query->whereIn('type', ['Resort', 'Beach Resort']);
            } else {
                $query->where('type', $typeFilter);
            }
        }

        if ($request->filled('price_tier')) {
            $query->where('price_tier', $request->string('price_tier'));
        }

        // Daily budgets must never fall back to the nightly rate. Unknown daily rates
        // remain visible without a budget, but cannot match a specific daily amount.
        $dayUse = $request->input('stay_mode') === 'day_use';
        [$priceMin, $priceMax] = \App\Support\BudgetRanges::bounds($request);
        if ($priceMin !== null || $priceMax !== null) {
            if ($dayUse) {
                $query->whereNotNull('price_per_day');
                if ($priceMin !== null) $query->where('price_per_day', '>=', $priceMin);
                if ($priceMax !== null) $query->where('price_per_day', '<=', $priceMax);
            } else {
                \App\Support\StayPricing::applyNightlyBudget($query, $priceMin, $priceMax);
            }
        }

        // Kind of stay. Every accredited lodging type is somewhere to sleep, so "overnight" adds no limit.
        // A recorded daily rate explicitly offers day use; older records may say so only in their description.
        if ($request->string('stay_mode')->toString() === 'day_use') {
            $query->where(function ($q) {
                $q->whereNotNull('price_per_day');
                foreach (self::DAY_USE_PHRASES as $phrase) {
                    $q->orWhereRaw('LOWER(description) LIKE ?', ['%'.$phrase.'%']);
                }
            });
        }

        // Stays with a live promo (the "Deals" chip).
        if ($request->boolean('deals')) {
            $query->whereIn('id', \App\Models\Promotion::listingIdsWithDeal('accommodation'));
        }

        // The filters this page's panel offers beyond search, province and budget: rating, amenities, and its own extras.
        \App\Support\ListingFilters::apply($query, $request, 'accommodation');

        match ($request->string('sort')->toString()) {
            'rating' => $query->orderByDesc('rating'),
            'price_low' => $query->orderBy('price_per_night'),
            'price_high' => $query->orderByDesc('price_per_night'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('featured')->orderByDesc('rating'),
        };

        // Last, so rows that tie on the chosen sort keep one fixed order: without it a page can repeat or skip a place.
        $query->orderBy('id');

        $accommodations = $query->paginate(12)->withQueryString();
        \App\Support\SearchLog::record($request, 'accommodations', $accommodations->total());

        $regions = Region::orderBy('name')->get();
        $selectedType = $request->input('type');
        $baseTypes = \App\Support\CategoryChips::visible(Accommodation::publiclyVisible(), 'type', $selectedType);
        // The resort kinds ("Beach Resort", "Lake Resort"...) come from each resort's setting; one with too few resorts is left out.
        $resortCategoryTypes = collect(Accommodation::RESORT_SETTINGS)
            ->map(fn ($s) => ($s === 'All-Inclusive' ? 'All Inclusive' : $s).' Resort' === $selectedType
                ? [$s, PHP_INT_MAX]
                : [$s, Accommodation::publiclyVisible()->whereIn('type', ['Resort', 'Beach Resort'])->withResortSetting($s)->count()])
            ->filter(fn ($pair) => $pair[1] >= \App\Support\CategoryChips::MIN)
            ->map(fn ($pair) => ($pair[0] === 'All-Inclusive' ? 'All Inclusive' : $pair[0]).' Resort');
        $types = $baseTypes->merge($resortCategoryTypes)->unique()->values();

        // The Deals chip is only offered while some visible stay has a live promo (or while it is the chosen filter).
        $dealsCount = Accommodation::publiclyVisible()->whereIn('id', \App\Models\Promotion::listingIdsWithDeal('accommodation'))->count();

        return view('accommodations.index', compact('accommodations', 'regions', 'types', 'dealsCount'));
    }

    public function show(Accommodation $accommodation): View
    {
        abort_if($accommodation->archived_at || ! $accommodation->is_accredited, 404);

        $accommodation->load(['region', 'roomTypes', 'photos', 'reviews' => fn ($q) => $q->latest()->take(10)]);

        $nearby = Accommodation::publiclyVisible()->with('region', 'photos')
            ->where('region_id', $accommodation->region_id)
            ->where('id', '!=', $accommodation->id)
            ->orderByDesc('rating')
            ->take(12)
            ->get()
            ->pipe(fn ($found) => \App\Support\RelatedListings::pick($found));

        return view('accommodations.show', compact('accommodation', 'nearby'));
    }
}
