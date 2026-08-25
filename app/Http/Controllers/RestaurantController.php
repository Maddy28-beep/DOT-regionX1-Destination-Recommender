<?php

namespace App\Http\Controllers;

use App\Models\Region;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RestaurantController extends Controller
{
    public function index(Request $request): View
    {
        $query = Restaurant::publiclyVisible()->with('region', 'photos')->withCount('reviews');

        if ($request->filled('q')) {
            $query->where('name', 'like', '%'.$request->string('q').'%');
        }

        if ($request->filled('region_id')) {
            $query->where('region_id', $request->integer('region_id'));
        }

        if ($request->filled('cuisine_type')) {
            $query->where('cuisine_type', $request->string('cuisine_type'));
        }

        if ($request->filled('price_tier')) {
            $query->where('price_tier', $request->string('price_tier'));
        }

        match ($request->string('sort')->toString()) {
            'rating' => $query->orderByDesc('rating'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('rating'),
        };

        $restaurants = $query->paginate(9)->withQueryString();

        $regions = Region::orderBy('name')->get();
        $cuisineTypes = Restaurant::query()->whereNotNull('cuisine_type')->distinct()->orderBy('cuisine_type')->pluck('cuisine_type');

        return view('restaurants.index', compact('restaurants', 'regions', 'cuisineTypes'));
    }

    public function show(Restaurant $restaurant): View
    {
        abort_if($restaurant->archived_at || ! $restaurant->is_accredited, 404);

        $restaurant->load(['region', 'photos', 'reviews' => fn ($q) => $q->latest()->take(10)]);

        $nearby = Restaurant::publiclyVisible()->with('region', 'photos')
            ->where('region_id', $restaurant->region_id)
            ->where('id', '!=', $restaurant->id)
            ->orderByDesc('rating')
            ->take(3)
            ->get();

        return view('restaurants.show', compact('restaurant', 'nearby'));
    }
}
