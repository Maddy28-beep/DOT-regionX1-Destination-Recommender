<?php

namespace App\Http\Controllers;

use App\Models\Accommodation;
use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccommodationController extends Controller
{
    public function index(Request $request): View
    {
        $query = Accommodation::publiclyVisible()->with('region', 'photos')->withCount('reviews');

        if ($request->filled('q')) {
            \App\Support\SearchTerm::contains($query, 'name', (string) $request->string('q'));
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

        match ($request->string('sort')->toString()) {
            'rating' => $query->orderByDesc('rating'),
            'price_low' => $query->orderBy('price_per_night'),
            'price_high' => $query->orderByDesc('price_per_night'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('featured')->orderByDesc('rating'),
        };

        // Last, so rows that tie on the chosen sort keep one fixed order: without it a page can repeat or skip a place.
        $query->orderBy('id');

        $accommodations = $query->paginate(9)->withQueryString();
        \App\Support\SearchLog::record($request, 'accommodations', $accommodations->total());

        $regions = Region::orderBy('name')->get();
        $baseTypes = Accommodation::query()->whereNotNull('type')->distinct()->orderBy('type')->pluck('type');
        $filedSettings = Accommodation::query()
            ->where('is_accredited', true)->whereNull('archived_at')
            ->whereNotNull('resort_settings')
            ->pluck('resort_settings')
            ->flatMap(fn ($encoded) => array_filter(explode('|', (string) $encoded)))
            ->unique()
            ->all();
        $resortCategoryTypes = collect(Accommodation::RESORT_SETTINGS)
            ->filter(fn ($s) => in_array($s, $filedSettings, true))
            ->map(fn ($s) => ($s === 'All-Inclusive' ? 'All Inclusive' : $s).' Resort');
        $types = $baseTypes->merge($resortCategoryTypes)->unique()->values();

        return view('accommodations.index', compact('accommodations', 'regions', 'types'));
    }

    public function show(Accommodation $accommodation): View
    {
        abort_if($accommodation->archived_at || ! $accommodation->is_accredited, 404);

        $accommodation->load(['region', 'roomTypes', 'photos', 'reviews' => fn ($q) => $q->latest()->take(10)]);

        $nearby = Accommodation::publiclyVisible()->with('region', 'photos')
            ->where('region_id', $accommodation->region_id)
            ->where('id', '!=', $accommodation->id)
            ->orderByDesc('rating')
            ->take(3)
            ->get();

        return view('accommodations.show', compact('accommodation', 'nearby'));
    }
}
