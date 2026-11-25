<?php

namespace App\Http\Controllers;

use App\Models\Region;
use App\Models\SouvenirCenter;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SouvenirCenterController extends Controller
{
    public function index(Request $request): View
    {
        $query = SouvenirCenter::publiclyVisible()->with('region', 'photos')->withCount('reviews');

        // Keywords, not one exact phrase: budget words fill the budget, the other words are matched in any of these columns.
        \App\Support\KeywordSearch::prepare($request, 'souvenir_centers');
        if ($request->filled('q')) {
            \App\Support\KeywordSearch::apply($query, (string) $request->string('q'), ['souvenir_centers.name', 'souvenir_centers.location', 'souvenir_centers.description'], ! $request->filled('sort'));
        }

        if ($request->filled('region_id')) {
            $query->where('region_id', $request->integer('region_id'));
        }

        // The filters this page's panel offers beyond search, province and budget: rating, amenities, and its own extras.
        \App\Support\ListingFilters::apply($query, $request, 'souvenir_center');

        match ($request->string('sort')->toString()) {
            'rating' => $query->orderByDesc('rating'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('rating'),
        };

        // Last, so rows that tie on the chosen sort keep one fixed order: without it a page can repeat or skip a place.
        $query->orderBy('id');

        $souvenirCenters = $query->paginate(12)->withQueryString();
        \App\Support\SearchLog::record($request, 'souvenir-centers', $souvenirCenters->total());

        $regions = Region::orderBy('name')->get();

        return view('souvenir-centers.index', compact('souvenirCenters', 'regions'));
    }

    public function show(SouvenirCenter $souvenirCenter): View
    {
        abort_if($souvenirCenter->archived_at || ! $souvenirCenter->is_accredited, 404);

        $souvenirCenter->load(['region', 'photos', 'reviews' => fn ($q) => $q->latest()->take(10)]);

        $nearby = SouvenirCenter::publiclyVisible()->with('region', 'photos')
            ->where('region_id', $souvenirCenter->region_id)
            ->where('id', '!=', $souvenirCenter->id)
            ->orderByDesc('rating')
            ->take(12)
            ->get()
            ->pipe(fn ($found) => \App\Support\RelatedListings::pick($found));

        return view('souvenir-centers.show', compact('souvenirCenter', 'nearby'));
    }
}
