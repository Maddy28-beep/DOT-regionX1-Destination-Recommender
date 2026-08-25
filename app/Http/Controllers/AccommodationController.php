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
            'price_low' => $query->orderBy('price_per_night'),
            'price_high' => $query->orderByDesc('price_per_night'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('featured')->orderByDesc('rating'),
        };

        $accommodations = $query->paginate(9)->withQueryString();

        $regions = Region::orderBy('name')->get();
        $types = Accommodation::query()->whereNotNull('type')->distinct()->orderBy('type')->pluck('type');

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
