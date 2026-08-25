<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use App\Models\Region;
use App\Models\SavedDestination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        $nearby = Destination::publiclyVisible()->with('region', 'photos')
            ->where('region_id', $destination->region_id)
            ->where('id', '!=', $destination->id)
            ->orderByDesc('rating')
            ->take(3)
            ->get();

        $isSaved = false;
        if (Auth::guard('tourist')->check()) {
            $isSaved = SavedDestination::where('tourist_id', Auth::guard('tourist')->id())
                ->where('destination_id', $destination->id)
                ->exists();
        }

        return view('destinations.show', compact('destination', 'nearby', 'isSaved'));
    }

    public function toggleSave(Destination $destination)
    {
        $touristId = Auth::guard('tourist')->id();

        $existing = SavedDestination::where('tourist_id', $touristId)
            ->where('destination_id', $destination->id)
            ->first();

        if ($existing) {
            $existing->delete();
            $status = 'removed';
        } else {
            SavedDestination::create(['tourist_id' => $touristId, 'destination_id' => $destination->id]);
            $status = 'saved';
        }

        return back()->with('status', $status === 'saved'
            ? "Added {$destination->name} to your saved list."
            : "Removed {$destination->name} from your saved list.");
    }
}
