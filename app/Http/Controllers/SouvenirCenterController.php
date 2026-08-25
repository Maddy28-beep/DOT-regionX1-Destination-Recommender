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

        if ($request->filled('q')) {
            $query->where('name', 'like', '%'.$request->string('q').'%');
        }

        if ($request->filled('region_id')) {
            $query->where('region_id', $request->integer('region_id'));
        }

        match ($request->string('sort')->toString()) {
            'rating' => $query->orderByDesc('rating'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('rating'),
        };

        $souvenirCenters = $query->paginate(9)->withQueryString();

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
            ->take(3)
            ->get();

        return view('souvenir-centers.show', compact('souvenirCenter', 'nearby'));
    }
}
