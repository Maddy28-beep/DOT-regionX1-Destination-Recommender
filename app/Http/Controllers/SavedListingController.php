<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureVisitorToken;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Restaurant;
use App\Models\SavedListing;
use App\Models\SouvenirCenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The heart control: places a visitor wants to keep.
 *
 * Saved against an opaque browser token rather than an account, so the list
 * survives across visits on the same device without the site knowing who the
 * visitor is.
 */
class SavedListingController extends Controller
{
    /** URL segment -> model, polymorphic kind, and detail route. */
    public const TYPES = [
        'destinations' => ['model' => Destination::class, 'kind' => 'destination', 'label' => 'Destinations', 'route' => 'destinations.show'],
        'accommodations' => ['model' => Accommodation::class, 'kind' => 'accommodation', 'label' => 'Accommodations', 'route' => 'accommodations.show'],
        'restaurants' => ['model' => Restaurant::class, 'kind' => 'restaurant', 'label' => 'Restaurants', 'route' => 'restaurants.show'],
        'souvenir-centers' => ['model' => SouvenirCenter::class, 'kind' => 'souvenir_center', 'label' => 'Souvenir Centers', 'route' => 'souvenir-centers.show'],
    ];

    /**
     * The URL segment for a listing, or null if its type isn't saveable.
     *
     * Packages and tour operators are deliberately absent: they are booked
     * through an operator rather than visited, so a heart on them would mean
     * something different from a heart on a place.
     */
    public static function segmentFor(object $listing): ?string
    {
        foreach (self::TYPES as $segment => $config) {
            if ($listing instanceof $config['model']) {
                return $segment;
            }
        }

        return null;
    }

    public function index(Request $request): View
    {
        $token = EnsureVisitorToken::get($request);

        $saved = SavedListing::where('visitor_token', $token)->latest('saved_at')->get();

        // Load the actual listings per kind, so one query per type rather than
        // one per row.
        $groups = [];
        foreach (self::TYPES as $segment => $config) {
            $ids = $saved->where('listing_kind', $config['kind'])->pluck('listing_id');

            if ($ids->isEmpty()) {
                continue;
            }

            $groups[$segment] = [
                'label' => $config['label'],
                'items' => $config['model']::with('region')->whereIn('id', $ids)->get(),
            ];
        }

        return view('saved.index', compact('groups'));
    }

    public function toggle(Request $request, string $type, int $id): RedirectResponse|JsonResponse
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        $config = self::TYPES[$type];

        $listing = $config['model']::findOrFail($id);
        abort_unless($listing->is_accredited && ! $listing->archived_at, 404);

        $attributes = [
            'visitor_token' => EnsureVisitorToken::get($request),
            'listing_kind' => $config['kind'],
            'listing_id' => $listing->id,
        ];

        $existing = SavedListing::where($attributes)->first();

        if ($existing) {
            $existing->delete();
            $saved = false;
            $status = "Removed {$listing->name} from your saved list.";
        } else {
            SavedListing::create($attributes + ['saved_at' => now()]);
            $saved = true;
            $status = "Saved {$listing->name}.";
        }

        // The heart is toggled in place by JS without a page reload; JSON keeps
        // that path from re-rendering anything. A plain form submit (no JS)
        // still gets the redirect it always has, so the control keeps working
        // with JavaScript off.
        if ($request->wantsJson()) {
            return response()->json(['saved' => $saved, 'status' => $status, 'name' => $listing->name]);
        }

        return back()->with('status', $status);
    }

    /** Where the per-request memo of savedKeys() lives on the request. */
    private const KEY_CACHE = 'saved_listing_keys';

    /**
     * Every listing this browser has saved, as "kind:id" strings.
     *
     * Memoized on the request: a listing grid renders the heart once per card,
     * and each of those must not become its own query. The request is the
     * right place for it rather than a static, which would outlive the request
     * under a persistent worker and hand a returning visitor a stale list.
     *
     * @return array<int, string>
     */
    public static function savedKeys(Request $request): array
    {
        if ($request->attributes->has(self::KEY_CACHE)) {
            return $request->attributes->get(self::KEY_CACHE);
        }

        $keys = SavedListing::where('visitor_token', EnsureVisitorToken::get($request))
            ->get(['listing_kind', 'listing_id'])
            ->map(fn ($row) => $row->listing_kind.':'.$row->listing_id)
            ->all();

        $request->attributes->set(self::KEY_CACHE, $keys);

        return $keys;
    }
}
