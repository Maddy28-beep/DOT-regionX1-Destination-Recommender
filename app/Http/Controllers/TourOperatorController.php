<?php

namespace App\Http\Controllers;

use App\Models\Region;
use App\Models\TourOperator;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TourOperatorController extends Controller
{
    public function index(Request $request): View
    {
        $query = TourOperator::publiclyVisible()->with('region', 'photos')->withCount('reviews');

        if ($request->filled('q')) {
            $query->where('name', 'like', '%'.$request->string('q').'%');
        }

        if ($request->filled('region_id')) {
            $query->where('region_id', $request->integer('region_id'));
        }

        if ($request->filled('specialization')) {
            $query->where('specialization', $request->string('specialization'));
        }

        if ($request->filled('price_tier')) {
            $query->where('price_tier', $request->string('price_tier'));
        }

        match ($request->string('sort')->toString()) {
            'rating' => $query->orderByDesc('rating'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('rating'),
        };

        $tourOperators = $query->paginate(9)->withQueryString();

        $regions = Region::orderBy('name')->get();
        $specializations = TourOperator::query()->whereNotNull('specialization')->distinct()->orderBy('specialization')->pluck('specialization');

        return view('tour-operators.index', compact('tourOperators', 'regions', 'specializations'));
    }

    public function show(TourOperator $tourOperator): View
    {
        abort_if($tourOperator->archived_at || ! $tourOperator->is_accredited, 404);

        $tourOperator->load(['region', 'photos', 'reviews' => fn ($q) => $q->latest()->take(10), 'packages' => fn ($q) => $q->whereNull('archived_at')->where('is_accredited', true)]);

        $nearby = TourOperator::publiclyVisible()->with('region', 'photos')
            ->where('region_id', $tourOperator->region_id)
            ->where('id', '!=', $tourOperator->id)
            ->orderByDesc('rating')
            ->take(3)
            ->get();

        return view('tour-operators.show', compact('tourOperator', 'nearby'));
    }
}
