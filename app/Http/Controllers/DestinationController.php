<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DestinationController extends Controller
{
    public function index(Request $request): View
    {
        $query = Destination::publiclyVisible()->with('region', 'tags', 'photos')->withCount('reviews');

        if ($request->filled('q')) {
            $query->where('name', 'like', '%'.$request->string('q').'%');
        }

        if ($request->filled('region_id')) {
            $query->where('region_id', $request->integer('region_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('price_tier')) {
            $query->where('price_tier', $request->string('price_tier'));
        }

        match ($request->string('sort')->toString()) {
            'rating' => $query->orderByDesc('rating'),
            'name' => $query->orderBy('name'),
            'nearest' => $query->orderBy('distance_km'),
            default => $query->orderByDesc('featured')->orderByDesc('rating'),
        };

        $destinations = $query->paginate(9)->withQueryString();

        $regions = Region::orderBy('name')->get();
        $types = Destination::query()->whereNotNull('type')->distinct()->orderBy('type')->pluck('type');

        return view('destinations.index', compact('destinations', 'regions', 'types'));
    }

    public function show(Destination $destination): View
    {
        abort_if($destination->archived_at || ! $destination->is_accredited, 404);

        $destination->load(['region', 'tags', 'photos', 'reviews' => fn ($q) => $q->latest()->take(10)]);

        $nearby = Destination::publiclyVisible()->with('region', 'tags')
            ->where('region_id', $destination->region_id)
            ->where('id', '!=', $destination->id)
            ->orderByDesc('rating')
            ->take(3)
            ->get();
        $nearbyIsSameRegion = $nearby->isNotEmpty();

        // Some regions (e.g. Samal, which is the only destination seeded in
        // Island Garden City of Samal) have no other same-region listing at
        // all -- fall back to top-rated destinations elsewhere rather than
        // silently hiding the section for those.
        if ($nearby->isEmpty()) {
            $nearby = Destination::publiclyVisible()->with('region', 'tags')
                ->where('id', '!=', $destination->id)
                ->orderByDesc('rating')
                ->take(3)
                ->get();
        }

        return view('destinations.show', compact('destination', 'nearby', 'nearbyIsSameRegion'));
    }
}
