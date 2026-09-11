<?php

namespace App\Http\Controllers\Tourist;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TouristSavedDestination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use App\Support\Toast;

/**
 * The account-scoped favorites list -- same shape as SavedListingController,
 * kept as its own copy (matching how CheckInController/ReviewController each
 * define their own TYPES map too) rather than sharing one, since the owning
 * column differs (tourist_account_id here, visitor_token there) and the two
 * lists are never meant to merge.
 */
class TouristSavedDestinationController extends Controller
{
    public const TYPES = [
        'destinations' => ['model' => Destination::class, 'kind' => 'destination', 'label' => 'Destinations'],
        'accommodations' => ['model' => Accommodation::class, 'kind' => 'accommodation', 'label' => 'Accommodations'],
        'restaurants' => ['model' => Restaurant::class, 'kind' => 'restaurant', 'label' => 'Restaurants'],
        'souvenir-centers' => ['model' => SouvenirCenter::class, 'kind' => 'souvenir_center', 'label' => 'Souvenir Centers'],
    ];

    public static function segmentFor(object $listing): ?string
    {
        foreach (self::TYPES as $segment => $config) {
            if ($listing instanceof $config['model']) {
                return $segment;
            }
        }

        return null;
    }

    public function index(): View
    {
        $touristId = Auth::guard('tourist')->id();

        $saved = TouristSavedDestination::where('tourist_account_id', $touristId)->latest('saved_at')->get();

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

        return view('tourist.saved.index', compact('groups'));
    }

    public function toggle(Request $request, string $type, int $id): RedirectResponse|JsonResponse
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        $config = self::TYPES[$type];

        $listing = $config['model']::findOrFail($id);
        abort_unless($listing->is_accredited && ! $listing->archived_at, 404);

        $attributes = [
            'tourist_account_id' => Auth::guard('tourist')->id(),
            'listing_kind' => $config['kind'],
            'listing_id' => $listing->id,
        ];

        $existing = TouristSavedDestination::where($attributes)->first();

        if ($existing) {
            $existing->delete();
            $saved = false;
            $toast = Toast::success('Removed from Saved Places', "{$listing->name} is no longer in your list.");
        } else {
            TouristSavedDestination::create($attributes + ['saved_at' => now()]);
            $saved = true;
            $toast = Toast::success('Added to Saved Places', "{$listing->name} is now in your list.");
        }

        if ($request->wantsJson()) {
            return response()->json([
                'saved' => $saved,
                'title' => $toast['status'],
                'detail' => $toast['status_detail'],
                'name' => $listing->name,
            ]);
        }

        return back()->with($toast);
    }

    private const KEY_CACHE = 'tourist_saved_destination_keys';

    /** @return array<int, string> */
    public static function savedKeys(Request $request): array
    {
        if ($request->attributes->has(self::KEY_CACHE)) {
            return $request->attributes->get(self::KEY_CACHE);
        }

        $keys = TouristSavedDestination::where('tourist_account_id', Auth::guard('tourist')->id())
            ->get(['listing_kind', 'listing_id'])
            ->map(fn ($row) => $row->listing_kind.':'.$row->listing_id)
            ->all();

        $request->attributes->set(self::KEY_CACHE, $keys);

        return $keys;
    }
}
