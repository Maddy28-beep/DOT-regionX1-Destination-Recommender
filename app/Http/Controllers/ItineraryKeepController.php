<?php

namespace App\Http\Controllers;

use App\Models\Itinerary;
use App\Services\Itineraries\ItineraryKeep;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Keeping a plan without an account. A private link carries on the plan on any device, a second link only shows it, and a new
 * link replaces both. See ItineraryKeep for how the secrets are stored.
 */
class ItineraryKeepController extends Controller
{
    public function __construct(private readonly ItineraryKeep $keep) {}

    /** "Keep this plan": make the link for the plan this browser is holding. */
    public function store(Request $request): RedirectResponse
    {
        $itinerary = $this->current($request);
        $existing = $request->session()->get(ItineraryKeep::SESSION_KEY);

        // Pressing it again when the link is already here changes nothing; "make a new link" is the way to replace it.
        if ($this->keep->isKept($itinerary) && $this->keep->links($itinerary, $existing)) {
            return redirect()->to(route('plan.itinerary').'#keep')->with('show_keep_panel', true);
        }

        $request->session()->put(ItineraryKeep::SESSION_KEY, $this->keep->issue($itinerary));

        return redirect()->to(route('plan.itinerary').'#keep')
            ->with('show_keep_panel', true)
            ->with(Toast::success('Your plan has a private link', 'Copy it or scan the code. Anyone with the link can open this plan.'));
    }

    /** "Make a new link": the old edit and share links stop working. */
    public function renew(Request $request): RedirectResponse
    {
        $itinerary = $this->current($request);
        $request->session()->put(ItineraryKeep::SESSION_KEY, $this->keep->issue($itinerary));

        return redirect()->to(route('plan.itinerary').'#keep')
            ->with('show_keep_panel', true)
            ->with(Toast::success('New link made', 'The old links no longer open this plan.'));
    }

    /** Open a kept plan with its edit link: this browser carries on with it. */
    public function open(Request $request, string $secret): RedirectResponse
    {
        $itinerary = $this->keep->findByEdit($secret);
        abort_if(! $itinerary, 404);

        $this->keep->touch($itinerary);
        $request->session()->put(TripPlannerController::PREFERENCE_KEY, $itinerary->preference_id);
        $request->session()->put(TripPlannerController::ITINERARY_KEY, $itinerary->id);
        $request->session()->put(ItineraryKeep::SESSION_KEY, $secret);
        $request->session()->forget('show_keep_panel');

        return $this->private(redirect()->to(route('plan.itinerary')));
    }

    /** The share link: the plan to read, print or save as a PDF. It cannot be changed from here. */
    public function shared(Request $request, string $secret): View|Response
    {
        $itinerary = $this->keep->findByShare($secret);
        abort_if(! $itinerary, 404);

        $itinerary->load('items', 'package');
        $days = $itinerary->items->sortBy(['day_number', 'sort_order'])->groupBy('day_number');

        $response = response()->view('plan.shared', ['itinerary' => $itinerary, 'days' => $days, 'print' => $request->boolean('print')]);

        return $this->private($response);
    }

    private function current(Request $request): Itinerary
    {
        $id = $request->session()->get(TripPlannerController::ITINERARY_KEY);
        abort_if(! $id, 404);

        return Itinerary::with('package')->findOrFail($id);
    }

    /** Pages reached by a secret link are not indexed, not cached and do not leak the link to other sites. */
    private function private(Response|RedirectResponse $response): Response|RedirectResponse
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
