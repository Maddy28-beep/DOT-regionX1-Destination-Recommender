<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\Region;
use App\Services\Recommendation\ContentBasedRecommendationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PackageController extends Controller
{
    /**
     * The day range a duration band covers, as [min, max]; a null max means
     * open-ended. Keyed by the values the homepage search bar submits.
     *
     * @return array{0: int, 1: int|null}
     */
    private static function durationBounds(string $band): array
    {
        return match ($band) {
            '1-2' => [1, 2],
            '3-4' => [3, 4],
            '5-plus' => [5, null],
            default => [1, null],
        };
    }

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

        // See DestinationController::index() -- one interest spans several
        // stored types, resolved through the recommender so both agree.
        if ($request->filled('interest')) {
            $query->whereIn('type', ContentBasedRecommendationService::typesForInterest(
                (string) $request->string('interest')
            ));
        }

        /*
         * How long the traveller wants to be away. Packages are the only
         * listing that records a length, which is why the homepage search
         * bar's duration only ever reaches this catalogue.
         */
        if ($request->filled('duration')) {
            [$min, $max] = self::durationBounds((string) $request->string('duration'));
            $query->whereNotNull('duration_days')->where('duration_days', '>=', $min);

            if ($max !== null) {
                $query->where('duration_days', '<=', $max);
            }
        }

        if ($request->filled('price_tier')) {
            $query->where('price_tier', $request->string('price_tier'));
        }

        match ($request->string('sort')->toString()) {
            // See RanksByRating / DestinationController::index().
            'rating' => $query->orderByWeightedRating(),
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
