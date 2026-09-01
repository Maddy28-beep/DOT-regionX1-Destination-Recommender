<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\Itinerary;
use App\Models\PreferenceActivity;
use App\Models\PreferenceAmenity;
use App\Models\TouristHealthCondition;
use App\Models\TouristHealthProfile;
use App\Models\TouristPreference;
use App\Services\Geocoding\AddressSuggestionService;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The travel-preference survey and AI itinerary.
 *
 * Open to everyone, because there is nobody to log in as: traveler accounts
 * were removed for Data Privacy Act compliance. The survey answers and the
 * generated plan are stored as ordinary rows and reached through the session,
 * with no name, email, or account attached to them.
 *
 * The health and accessibility questions (2.2.1.14) are part of this survey
 * rather than a separate profile: they matter precisely because the recommender
 * weighs accessibility when ranking destinations, so they are asked where they
 * are used, kept optional and consent-gated, and live exactly as long as the
 * plan does.
 */
class TripPlannerController extends Controller
{
    /** Session keys holding this visitor's plan. */
    public const PREFERENCE_KEY = 'guest_preference_id';

    public const ITINERARY_KEY = 'guest_itinerary_id';

    /** Health/accessibility options offered in the survey (Table 38). */
    public const HEALTH_CONDITIONS = [
        'mobility' => 'Mobility difficulty (wheelchair, walker, limited walking)',
        'vision' => 'Vision impairment',
        'hearing' => 'Hearing impairment',
        'cardiac' => 'Heart or blood-pressure condition',
        'respiratory' => 'Asthma or other respiratory condition',
        'pregnancy' => 'Pregnancy',
        'senior' => 'Traveling with a senior citizen',
        'child' => 'Traveling with young children',
    ];

    public function __construct(
        private readonly ItineraryGenerationService $itineraryService,
        private readonly AddressSuggestionService $addresses,
    ) {}

    public function edit(Request $request): View
    {
        $preference = $this->currentPreference($request) ?? new TouristPreference();
        $healthProfile = $preference->exists
            ? $preference->healthProfile()->with('conditions')->first()
            : null;

        return view('plan.preferences', [
            'preference' => $preference,
            'healthProfile' => $healthProfile,
            'healthOptions' => self::HEALTH_CONDITIONS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'travel_days' => ['required', 'integer', 'min:1', 'max:30'],
            'travel_type' => ['required', 'string', 'max:20'],
            'travel_purpose' => ['nullable', 'string', 'max:30'],
            'visitor_type' => ['nullable', 'string', 'max:30'],
            'budget' => ['required', 'string', 'max:20'],
            'accommodation_pref' => ['required', 'string', 'max:20'],
            'distance_pref' => ['required', 'in:near,moderate,far'],
            'start_date' => ['nullable', 'date'],
            'arrival_time' => ['nullable', 'date_format:H:i'],
            // Bounds are the real ones, so a malformed or spoofed reading is
            // rejected rather than quietly sequencing the trip from the sea.
            'origin_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:origin_lng'],
            'origin_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:origin_lat'],
            'origin_label' => ['nullable', 'string', 'max:200'],
            'accessibility_notes' => ['nullable', 'string', 'max:1000'],
            'activities' => ['nullable', 'array'],
            'activities.*' => ['string', 'max:30'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['string', 'max:100'],
            'health_conditions' => ['nullable', 'array'],
            'health_conditions.*' => ['string', Rule::in(array_keys(self::HEALTH_CONDITIONS))],
            'health_other' => ['nullable', 'string', 'max:300'],
        ]);

        $preference = $this->currentPreference($request) ?? new TouristPreference();

        $preference->fill(collect($data)->except([
            'activities', 'amenities', 'health_conditions', 'health_other',
            'origin_lat', 'origin_lng', 'origin_label',
        ])->all());

        $this->applyOrigin($preference, $data);
        $preference->save();

        $preference->activities()->delete();
        foreach ($data['activities'] ?? [] as $activity) {
            PreferenceActivity::create(['preference_id' => $preference->id, 'activity' => $activity]);
        }

        $preference->amenities()->delete();
        foreach ($data['amenities'] ?? [] as $amenity) {
            PreferenceAmenity::create(['preference_id' => $preference->id, 'amenity' => $amenity]);
        }

        $this->saveHealthProfile($request, $preference, $data);

        // Itinerary generation follows the survey automatically (Sec. 2.3.4).
        $preference->load('activities', 'amenities');
        $itinerary = $this->itineraryService->generate($preference);

        $request->session()->put(self::PREFERENCE_KEY, $preference->id);
        $request->session()->put(self::ITINERARY_KEY, $itinerary->id);

        return redirect()->route('plan.itinerary')
            ->with('status', 'Your travel preferences are saved and your itinerary is ready.');
    }

    public function itinerary(Request $request): View|RedirectResponse
    {
        $preference = $this->currentPreference($request);

        if (! $preference) {
            return redirect()->route('plan.edit')
                ->with('status', 'Tell us about your trip first and we will build your itinerary.');
        }

        $itinerary = $this->currentItinerary($request)
            ?? $this->itineraryService->generate($preference);

        /*
         * Live counts for the provenance panel. Naming the algorithms is only
         * worth anything if the reader can see the size of the data each one
         * actually ran against -- a rule mined from 90 transactions and one
         * mined from 9,000 are not the same claim.
         */
        $provenance = [
            'destinations_ranked' => $itinerary->matches()->count(),
            'catalogue_size' => Destination::publiclyVisible()->count(),
            'transactions' => ExitSurvey::count(),
            'rules_applied' => $itinerary->items()->whereNotNull('rule_basis')->count(),
            'origin' => $preference->origin_label ?: 'Davao City centre',
        ];

        return view('plan.itinerary', compact('itinerary', 'preference', 'provenance'));
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $preference = $this->currentPreference($request);

        if (! $preference) {
            return redirect()->route('plan.edit')
                ->with('status', 'Tell us about your trip first and we will build your itinerary.');
        }

        $position = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);

        /*
         * A fresh reading taken here replaces the saved one rather than being
         * used once and discarded. Otherwise the plan would be sequenced from
         * where the traveller is now while the page still described distances
         * from where they were when they filled in the survey.
         */
        if (isset($position['lat'], $position['lng'])) {
            $this->applyOrigin($preference, [
                'origin_lat' => $position['lat'],
                'origin_lng' => $position['lng'],
            ]);
            $preference->save();
        }

        /*
         * Regenerating should actually show something new. Bumping the
         * variation reseeds the tie-break between destinations that scored
         * identically, so a traveller sees different options among equals
         * instead of the same plan again. Anything the score can tell apart
         * keeps its ranking.
         */
        $preference->increment('variation');

        $itinerary = $this->itineraryService->generate($preference->refresh());

        $request->session()->put(self::ITINERARY_KEY, $itinerary->id);

        return redirect()->route('plan.itinerary')->with('status', 'Your itinerary has been regenerated.');
    }

    /**
     * Record where the trip starts, if the traveller chose to share it.
     *
     * Coordinates are rounded to 3 decimal places (~110 m) before anything is
     * written. That is far finer than "which of these stops is nearest" needs,
     * and deliberately coarser than the reading the browser hands over: this
     * system holds no accounts precisely so it holds nothing that identifies
     * anyone, and a precise, timestamped position would undo that.
     *
     * Declining is a normal outcome, not an error. With no position the
     * generator falls back to the regional baseline and the trip still plans.
     */
    private function applyOrigin(TouristPreference $preference, array $data): void
    {
        $typed = trim((string) ($data['origin_label'] ?? ''));

        if (isset($data['origin_lat'], $data['origin_lng'])) {
            $preference->origin_lat = round((float) $data['origin_lat'], 3);
            $preference->origin_lng = round((float) $data['origin_lng'], 3);
            $preference->origin_label = $typed !== '' ? $typed : 'Your shared location';

            return;
        }

        /*
         * An address typed out in full without picking a suggestion. Geocode it
         * once here so the plan is still sequenced from where they said they
         * are -- the alternative is silently ignoring what they typed and
         * planning from the city centre while the page claims otherwise.
         */
        if ($typed !== '') {
            $match = $this->addresses->resolve($typed);

            $preference->origin_lat = $match ? round($match['lat'], 3) : null;
            $preference->origin_lng = $match ? round($match['lng'], 3) : null;
            $preference->origin_label = $typed;

            return;
        }

        $preference->origin_lat = null;
        $preference->origin_lng = null;
        $preference->origin_label = null;
    }

    /**
     * Store (or clear) the optional health and accessibility answers.
     *
     * Nothing is kept without the consent box, and unticking it on a later
     * pass deletes what was there — the same delete-anytime guarantee the
     * standalone profile used to offer, now reachable from the one form the
     * visitor already uses.
     */
    private function saveHealthProfile(Request $request, TouristPreference $preference, array $data): void
    {
        $conditions = $data['health_conditions'] ?? [];
        $other = $data['health_other'] ?? null;

        if (! $request->boolean('health_consent') || (empty($conditions) && blank($other))) {
            $preference->healthProfile()->delete();

            return;
        }

        $profile = TouristHealthProfile::updateOrCreate(
            ['preference_id' => $preference->id],
            ['consent' => true, 'consent_at' => now(), 'other_text' => $other],
        );

        $profile->conditions()->delete();
        foreach ($conditions as $condition) {
            TouristHealthCondition::create(['health_profile_id' => $profile->id, 'condition' => $condition]);
        }
    }

    /** The preference this browser's session points at. */
    private function currentPreference(Request $request): ?TouristPreference
    {
        $id = $request->session()->get(self::PREFERENCE_KEY);

        return $id
            ? TouristPreference::with('activities', 'amenities')->find($id)
            : null;
    }

    private function currentItinerary(Request $request): ?Itinerary
    {
        $id = $request->session()->get(self::ITINERARY_KEY);

        return $id
            ? Itinerary::with(['matches.destination', 'items.destination', 'items.accommodation'])->find($id)
            : null;
    }
}
