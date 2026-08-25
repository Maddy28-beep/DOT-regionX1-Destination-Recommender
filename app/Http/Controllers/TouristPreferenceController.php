<?php

namespace App\Http\Controllers;

use App\Models\PreferenceActivity;
use App\Models\PreferenceAmenity;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TouristPreferenceController extends Controller
{
    public function __construct(private readonly ItineraryGenerationService $itineraryService) {}

    public function edit(Request $request): View
    {
        $tourist = $request->user('tourist');

        $preference = $tourist->preferences()->with('activities', 'amenities')->latest('id')->first()
            ?? new TouristPreference();

        return view('dashboard.preferences', compact('preference'));
    }

    public function update(Request $request): RedirectResponse
    {
        $tourist = $request->user('tourist');

        $data = $request->validate([
            'travel_days' => ['required', 'integer', 'min:1', 'max:30'],
            'travel_type' => ['required', 'string', 'max:20'],
            'travel_purpose' => ['nullable', 'string', 'max:30'],
            'visitor_type' => ['nullable', 'string', 'max:30'],
            'budget' => ['required', 'string', 'max:20'],
            'accommodation_pref' => ['required', 'string', 'max:20'],
            'distance_pref' => ['required', 'in:near,moderate,far'],
            'start_date' => ['nullable', 'date'],
            'accessibility_notes' => ['nullable', 'string', 'max:1000'],
            'activities' => ['nullable', 'array'],
            'activities.*' => ['string', 'max:30'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['string', 'max:100'],
        ]);

        $preference = $tourist->preferences()->latest('id')->first()
            ?? $tourist->preferences()->make();

        $preference->fill(collect($data)->except(['activities', 'amenities'])->all());
        $preference->tourist_id = $tourist->id;
        $preference->save();

        $preference->activities()->delete();
        foreach ($data['activities'] ?? [] as $activity) {
            PreferenceActivity::create(['preference_id' => $preference->id, 'activity' => $activity]);
        }

        $preference->amenities()->delete();
        foreach ($data['amenities'] ?? [] as $amenity) {
            PreferenceAmenity::create(['preference_id' => $preference->id, 'amenity' => $amenity]);
        }

        // The itinerary generation process is automatically initiated after the tourist
        // submits the travel preference survey (manuscript Sec. 2.3.4).
        $preference->load('activities', 'amenities');
        $this->itineraryService->generate($tourist, $preference);

        return redirect()->route('tourist.itinerary.show')->with('status', 'Your travel preferences have been saved and your itinerary is ready.');
    }
}
