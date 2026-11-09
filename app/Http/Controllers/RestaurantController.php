<?php

namespace App\Http\Controllers;

use App\Models\Region;
use App\Models\Restaurant;
use App\Support\MealTimes;
use App\Support\OpeningHours;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RestaurantController extends Controller
{
    public function index(Request $request): View
    {
        $query = Restaurant::publiclyVisible()->with('region', 'photos')->withCount('reviews');

        if ($request->filled('q')) {
            \App\Support\SearchTerm::contains($query, 'name', (string) $request->string('q'));
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

        // A meal (and, optionally, a date): the restaurants open at the hour the planner schedules that meal.
        // Opening hours are free text, so this is read in PHP and kept to the matching ids. See MealTimes.
        $meal = $request->string('meal')->toString();
        if (MealTimes::valid($meal)) {
            $date = null;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('date'))) {
                try {
                    $date = Carbon::createFromFormat('Y-m-d', (string) $request->input('date'), OpeningHours::TIMEZONE)->startOfDay();
                } catch (\Throwable) {
                    $date = null;
                }
            }
            $ids = (clone $query)->reorder()->get(['restaurants.id', 'restaurants.opening_hours'])
                ->filter(fn ($r) => MealTimes::servedAt($r->opening_hours, $meal, $date))
                ->pluck('id');
            $query->whereIn('restaurants.id', $ids);
        }

        match ($request->string('sort')->toString()) {
            'rating' => $query->orderByDesc('rating'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('rating'),
        };

        // Last, so rows that tie on the chosen sort keep one fixed order: without it a page can repeat or skip a place.
        $query->orderBy('id');

        $restaurants = $query->paginate(9)->withQueryString();
        \App\Support\SearchLog::record($request, 'restaurants', $restaurants->total());

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
