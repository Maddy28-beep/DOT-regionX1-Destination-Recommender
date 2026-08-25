<?php

namespace App\Http\Controllers;

use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ItineraryController extends Controller
{
    public function __construct(private readonly ItineraryGenerationService $itineraryService) {}

    public function show(Request $request): View|RedirectResponse
    {
        $tourist = $request->user('tourist');

        $preference = $tourist->preferences()->with('activities', 'amenities')->latest('id')->first();
        if (! $preference) {
            return redirect()->route('tourist.preferences.edit')
                ->with('status', 'Set your travel preferences first so we can generate your itinerary.');
        }

        $itinerary = $tourist->itineraries()
            ->with(['matches.destination', 'items.destination', 'items.accommodation'])
            ->latest('generated_at')
            ->first();

        if (! $itinerary) {
            $itinerary = $this->itineraryService->generate($tourist, $preference);
        }

        return view('dashboard.itinerary', compact('itinerary', 'preference'));
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $tourist = $request->user('tourist');

        $preference = $tourist->preferences()->with('activities', 'amenities')->latest('id')->first();
        if (! $preference) {
            return redirect()->route('tourist.preferences.edit')
                ->with('status', 'Set your travel preferences first so we can generate your itinerary.');
        }

        $lat = $request->float('lat') ?: null;
        $lng = $request->float('lng') ?: null;

        $this->itineraryService->generate($tourist, $preference, $lat, $lng);

        return redirect()->route('tourist.itinerary.show')->with('status', 'Your itinerary has been regenerated.');
    }
}
