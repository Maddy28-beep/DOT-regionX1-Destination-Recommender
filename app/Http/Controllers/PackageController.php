<?php

namespace App\Http\Controllers;

use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\Package;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ContentBasedRecommendationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use App\Support\Toast;

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

        $package->load(['region', 'inclusions', 'itineraryDays', 'photos', 'tourOperator', 'reviews' => fn ($q) => $q->latest()->take(10)]);

        $nearby = Package::publiclyVisible()->with('region', 'photos')
            ->where('region_id', $package->region_id)
            ->where('id', '!=', $package->id)
            ->orderByDesc('rating')
            ->take(3)
            ->get();

        return view('packages.show', compact('package', 'nearby'));
    }

    /**
     * Adopt this package's day-by-day breakdown as the traveller's plan,
     * skipping the preference survey and the recommender entirely.
     *
     * Reuses the ordinary Itinerary/ItineraryItem tables (and so the whole
     * My Itinerary page, unchanged) rather than a separate display path --
     * an ItineraryItem with no destination/accommodation/restaurant id
     * already renders as plain text with no map link, which is exactly what
     * a package day (a title and a description, not a real listing) is.
     *
     * A fresh TouristPreference is created each time rather than reusing
     * whatever the session already had: this is a deliberate "give me this
     * instead" action, not an edit to an existing custom plan, and the
     * fields below exist only to satisfy the session-based plan machinery
     * every other page reads -- they are never scored against anything.
     */
    public function planWith(Request $request, Package $package): RedirectResponse
    {
        abort_if($package->archived_at || ! $package->is_accredited, 404);

        $package->loadMissing('itineraryDays');

        if ($package->itineraryDays->isEmpty()) {
            return back()->with(Toast::success(
                'No itinerary yet',
                'This provider hasn\'t published a day-by-day schedule for this package yet.'
            ));
        }

        [$preference, $itinerary] = DB::transaction(function () use ($package) {
            $preference = TouristPreference::create([
                'travel_days' => $package->itineraryDays->max('day_number'),
                'travel_type' => 'Solo',
                'budget' => $package->price_tier ?? 'Mid-range',
                'accommodation_pref' => 'Any',
                'distance_pref' => 'moderate',
            ]);

            $itinerary = Itinerary::create([
                'preference_id' => $preference->id,
                'package_id' => $package->id,
                'total_days' => $package->itineraryDays->max('day_number'),
                'est_budget_total' => $package->price_per_pax,
                'generated_at' => now(),
            ]);

            foreach ($package->itineraryDays as $day) {
                ItineraryItem::create([
                    'itinerary_id' => $itinerary->id,
                    'day_number' => $day->day_number,
                    'sort_order' => 0,
                    'slot' => 'Full Day',
                    'kind' => 'activity',
                    'title' => $day->title,
                    'note' => $day->description,
                ]);
            }

            return [$preference, $itinerary];
        });

        $request->session()->put(TripPlannerController::PREFERENCE_KEY, $preference->id);
        $request->session()->put(TripPlannerController::ITINERARY_KEY, $itinerary->id);

        return redirect()->route('plan.itinerary')
            ->with(Toast::success('Package added to your plan', "\"{$package->name}\" is now your itinerary below."));
    }
}
