<?php

namespace App\Http\Controllers\Tourist;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TripPlannerController;
use App\Models\Itinerary;
use App\Models\TouristHealthProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use App\Support\Toast;

/**
 * Lets a tourist optionally keep a generated itinerary past the browser
 * session. A saved itinerary is not a new kind of record -- it is the same
 * Itinerary/ItineraryItem rows TripPlannerController already produces, just
 * claimed by setting tourist_account_id. An unclaimed itinerary is completely
 * unaffected and keeps working exactly as it does for a guest today.
 */
class TouristItineraryController extends Controller
{
    /**
     * Claim the browser session's current itinerary. Deliberately not behind
     * auth:tourist -- a guest must be able to click Save, get sent to create
     * an account, and land back here to finish the job (see
     * TouristAuthController::postAuthRedirect()).
     */
    public function store(Request $request): RedirectResponse
    {
        $itineraryId = $request->session()->get(TripPlannerController::ITINERARY_KEY);
        abort_if(! $itineraryId, 404);

        $itinerary = Itinerary::findOrFail($itineraryId);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:150'],
        ]);

        if (! Auth::guard('tourist')->check()) {
            $request->session()->put('pending_save_itinerary', true);

            return redirect()->route('account.register')
                ->with(Toast::success('Create a free account', 'An alias and password is all it takes to keep this itinerary.'));
        }

        /*
         * Health and accessibility answers are explicitly excluded from what
         * gets saved permanently -- they exist so the recommender can favor
         * suitable stops for THIS trip, "attached to that one trip plan,
         * never to an identity" (see the privacy policy). Saving the plan
         * must not quietly turn a session-lifetime health disclosure into an
         * indefinitely-retained one.
         */
        $healthProfile = TouristHealthProfile::where('preference_id', $itinerary->preference_id)->first();
        $healthProfile?->conditions()->delete();
        $healthProfile?->delete();

        $itinerary->tourist_account_id = Auth::guard('tourist')->id();
        $itinerary->title = ($data['title'] ?? null) ?: $this->defaultTitle($itinerary);
        $itinerary->save();

        $request->session()->forget('pending_save_itinerary');

        return redirect()->route('account.itineraries.show', $itinerary)
            ->with(Toast::success('Itinerary saved', "\"{$itinerary->title}\" is now in My Itineraries."));
    }

    public function index(): View
    {
        $itineraries = Auth::guard('tourist')->user()->savedItineraries()
            ->with(['items', 'package'])
            ->get();

        return view('tourist.itineraries.index', compact('itineraries'));
    }

    /** Load a saved itinerary into the session and hand off to the ordinary itinerary page -- no separate view to keep in sync. */
    public function show(Request $request, Itinerary $itinerary): RedirectResponse
    {
        $this->authorizeOwnership($itinerary);

        $request->session()->put(TripPlannerController::PREFERENCE_KEY, $itinerary->preference_id);
        $request->session()->put(TripPlannerController::ITINERARY_KEY, $itinerary->id);

        return redirect()->route('plan.itinerary');
    }

    /** "Edit" means adjusting the preferences that produced this plan and regenerating -- there is no per-item editor. */
    public function edit(Request $request, Itinerary $itinerary): RedirectResponse
    {
        $this->authorizeOwnership($itinerary);

        $request->session()->put(TripPlannerController::PREFERENCE_KEY, $itinerary->preference_id);
        $request->session()->forget(TripPlannerController::ITINERARY_KEY);

        return redirect()->route('plan.edit');
    }

    public function destroy(Itinerary $itinerary): RedirectResponse
    {
        $this->authorizeOwnership($itinerary);

        $title = $itinerary->title;
        $itinerary->delete();

        return redirect()->route('account.itineraries')
            ->with(Toast::success('Itinerary removed', "\"{$title}\" has been deleted."));
    }

    private function authorizeOwnership(Itinerary $itinerary): void
    {
        abort_unless($itinerary->tourist_account_id === Auth::guard('tourist')->id(), 403);
    }

    private function defaultTitle(Itinerary $itinerary): string
    {
        if ($itinerary->package) {
            return $itinerary->package->name;
        }

        return "{$itinerary->total_days}-Day Davao Region Trip";
    }
}
