<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PackageController extends Controller
{
    public function index(Request $request): View
    {
        $query = Package::publiclyVisible()->with('region', 'photos')->withCount('reviews');

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
            'price_low' => $query->orderBy('price_per_pax'),
            'price_high' => $query->orderByDesc('price_per_pax'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('featured')->orderByDesc('rating'),
        };

        $packages = $query->paginate(9)->withQueryString();

        $regions = Region::orderBy('name')->get();
        $types = Package::query()->whereNotNull('type')->distinct()->orderBy('type')->pluck('type');

        return view('packages.index', compact('packages', 'regions', 'types'));
    }

    public function show(Package $package): View
    {
        abort_if($package->archived_at || ! $package->is_accredited, 404);

        $package->load(['region', 'inclusions', 'photos', 'tourOperator', 'reviews' => fn ($q) => $q->latest()->take(10)]);

        $nearby = Package::publiclyVisible()->with('region', 'photos')
            ->where('region_id', $package->region_id)
            ->where('id', '!=', $package->id)
            ->orderByDesc('rating')
            ->take(3)
            ->get();

        return view('packages.show', compact('package', 'nearby'));
    }
}
