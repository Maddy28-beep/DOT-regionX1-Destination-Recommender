<?php

namespace App\Http\Controllers;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\Itinerary;
use App\Models\PreferenceActivity;
use App\Models\PreferenceAmenity;
use App\Models\TouristHealthCondition;
use App\Models\TouristHealthProfile;
use App\Models\TouristPreference;
use App\Services\Geocoding\AddressSuggestionService;
use App\Services\Embeddings\SimilarDestinationService;
use App\Services\Recommendation\ContentBasedRecommendationService;
use App\Services\Recommendation\FreeTimeIdeas;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Services\Recommendation\TourOperatorSuggester;
use App\Services\Recommendation\TransportMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use App\Services\Recommendation\ItineraryScheduleBuilder;
use App\Support\Features;
use App\Support\TripWindow;
use App\Support\Geo\Countries;
use App\Support\Geo\PhProvinces;
use App\Support\Geo\RegionXi;
use App\Support\ItineraryCalendar;
use App\Support\Toast;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Http\Response;

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

    /**
     * The fork in the road the site-wide "Plan My Trip" button now leads to
     * first: personalized survey, or a tour operator's predefined package.
     * Pure presentation -- it reads nothing, writes nothing, and both links
     * on it go to routes that already existed (plan.edit, packages.index).
     */
    public function choose(): View
    {
        return view('plan.choose');
    }

    public function edit(Request $request): View
    {
        /*
         * A package-adopted itinerary's TouristPreference is synthetic --
         * PackageController::planWith() fabricates it purely to satisfy the
         * session-based plan machinery every other page reads (a travel_days
         * count, the package's own price tier standing in for "budget"), and
         * per that method's own doc comment it is "never scored against
         * anything." Pre-filling this survey from it would show the tourist
         * answers they never actually gave -- Solo, the package's budget
         * tier, "Any" accommodation -- as if editing their own real
         * preferences, when submitting the form as shown would silently
         * discard the package and replace it with a generated itinerary
         * built from those fabricated values instead.
         */
        $preference = $this->currentItinerary($request)?->package_id
            ? new TouristPreference()
            : ($this->currentPreference($request) ?? new TouristPreference());

        $healthProfile = $preference->exists
            ? $preference->healthProfile()->with('conditions')->first()
            : null;

        return view('plan.preferences', [
            'preference' => $preference,
            'healthProfile' => $healthProfile,
            'healthOptions' => self::HEALTH_CONDITIONS,
            /*
             * The radii the range gate actually applies, so the select can show
             * what each choice means in kilometres. Read from the service
             * rather than restated here: a label that quietly disagreed with
             * the gate would be worse than no label at all.
             */
            'distanceLabels' => [
                'near' => ContentBasedRecommendationService::rangeLabel('near'),
                'moderate' => ContentBasedRecommendationService::rangeLabel('moderate'),
                'far' => ContentBasedRecommendationService::rangeLabel('far'),
            ],
            // Only the resort settings a listing actually has, so no card leads to an empty answer.
            'resortSettings' => $this->availableResortSettings(),
            'transportComparison' => TransportMode::comparison(),
        ]);
    }

    public function update(Request $request): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        // The form shows "Public Transport" as one card with Jeepney / Taxi under it; the planner stores the kind.
        if ($request->input('transport_pref') === 'public') {
            $kind = $request->input('public_kind');
            $request->merge(['transport_pref' => in_array($kind, [TransportMode::JEEP, TransportMode::TAXI], true) ? $kind : TransportMode::JEEP]);
        }

        $v2 = Features::tripLocation();

        $data = $request->validate([
            // With trip_location_v2 on there is no number of days to enter: the days follow from the start and the end below.
            'travel_days' => $v2 ? ['nullable'] : ['required', 'integer', 'min:1', 'max:30'],
            // The trip's real start and end, in Philippine time (trip_location_v2). Read as one browser date-time each.
            'start_at' => $v2 ? ['required', 'string', 'max:16'] : ['nullable'],
            'end_at' => $v2 ? ['required', 'string', 'max:16'] : ['nullable'],
            'travel_type' => ['required', 'string', 'max:20'],
            /*
             * Both required as of the forced-input pass: travel_purpose and
             * visitor_type each feed a real, non-zero weight in
             * ContentBasedRecommendationService::PM_WEIGHTS (15% and 5% of
             * Preference Match respectively), but were previously skippable
             * via a "Prefer not to say" option -- silently leaving every
             * skipped traveller scored as a weak partial match (travel_purpose)
             * or a neutral non-signal (visitor_type) instead of contributing
             * real personalization. Health/accessibility stays optional
             * and consent-gated; those are sensitive by nature, not merely
             * skippable convenience fields, and forcing disclosure of them
             * would undermine the RA 10173 consent design elsewhere in this
             * form.
             */
            'travel_purpose' => ['required', 'string', 'max:30'],
            'visitor_type' => ['required', 'string', 'max:30'],
            /*
             * DOT Region XI asked for this specifically because the exit
             * survey's own origin question only reaches whoever finishes that
             * optional, post-trip survey. Required here so it lands for every
             * itinerary generated, not just the minority who complete a
             * survey afterward -- except for a traveller who already said
             * they're local (Regular / Local), for whom "where are you
             * visiting from" isn't a question that makes sense to force.
             */
            // With the trip_location_v2 switch on, the visitor's home place is the country / province / city answer below and
            // this free-text box is no longer on the form.
            'place_of_origin' => $v2
                ? ['nullable', 'string', 'max:150']
                : ['nullable', 'string', 'max:150', 'required_unless:visitor_type,Regular / Local'],
            // Visitor origin (switch on). The country is a code from our own list, or "none" for "Prefer not to say". The
            // province is checked against our list in applyVisitorOrigin; the city or municipality is typed.
            'visitor_origin_country' => $v2 ? ['nullable', 'string', 'max:10', 'required_unless:visitor_type,Regular / Local'] : ['nullable', 'string', 'max:10'],
            'visitor_origin_province' => ['nullable', 'string', 'max:80'],
            'visitor_origin_city' => ['nullable', 'string', 'max:100'],
            // Not on the form with the switch on (the end is end_at); an old form may still post it, and it is not read.
            'end_time' => $v2 ? ['nullable'] : ['nullable', 'date_format:H:i'],
            'budget' => ['required', 'string', 'max:20'],
            'accommodation_pref' => ['required', 'string', 'max:20'],
            // Which kind of resort, when a resort was asked for; ignored (and cleared) for anything else.
            'resort_setting' => ['nullable', Rule::in(Accommodation::RESORT_SETTINGS)],
            'distance_pref' => ['required', 'in:near,moderate,far'],
            // How they will get around. Optional: no answer plans as a personal car, as every trip did before this.
            'transport_pref' => ['nullable', Rule::in(TransportMode::keys())],
            // Not more than two years ahead: a typo such as 2062 would otherwise be planned as written.
            'start_date' => $v2 ? ['nullable'] : ['nullable', 'date', 'before_or_equal:'.now('Asia/Manila')->addYears(2)->toDateString()],
            'arrival_time' => $v2 ? ['nullable'] : ['nullable', 'date_format:H:i'],
            /*
             * Leaving (all optional). The time is what the planner uses. There is no departure date to enter: the traveller
             * leaves on the last day of the trip, arrival date + (days - 1), derived in TouristPreference::departureDate().
             * A departure_date posted by an old form is not validated, so it is never read.
             * The departure point is the traveller's own choice, never inferred from the starting point.
             */
            'departure_time' => ['nullable', 'date_format:H:i'],
            'departure_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:departure_lng'],
            'departure_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:departure_lat'],
            'departure_label' => ['nullable', 'string', 'max:200'],
            // Bounds are the real ones, so a malformed or spoofed reading is
            // rejected rather than quietly sequencing the trip from the sea.
            'origin_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:origin_lng'],
            'origin_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:origin_lat'],
            'origin_label' => ['nullable', 'string', 'max:200'],
            'accessibility_notes' => ['nullable', 'string', 'max:1000'],
            'activities' => ['required', 'array', 'min:1'],
            'activities.*' => ['string', 'max:30'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['string', 'max:100'],
            'health_conditions' => ['nullable', 'array'],
            'health_conditions.*' => ['string', Rule::in(array_keys(self::HEALTH_CONDITIONS))],
            'health_other' => ['nullable', 'string', 'max:300'],
        ]);

        $window = null;
        if ($v2) {
            $window = $this->validateTripWindow($data);
            $this->validateTripLocation($request, $data);
            // The planner is fed from the same columns as before: the days, the arrival date and time and the end time are
            // all derived from the two moments, never entered.
            $data['travel_days'] = TripWindow::calendarDays($window[0], $window[1]);
            $data['start_date'] = $window[0]->toDateString();
            $data['arrival_time'] = $window[0]->format('H:i');
            $data['end_time'] = $window[1]->format('H:i');
        } else {
            $this->validateDeparture($request, $data);
        }
        unset($data['start_at'], $data['end_at']);

        // A setting only means something for a resort, and an unanswered field must clear a previous answer.
        $data['resort_setting'] = $data['accommodation_pref'] === 'Resort' ? ($data['resort_setting'] ?? null) : null;
        $data['transport_pref'] = $data['transport_pref'] ?? null;

        $preference = $this->currentPreference($request) ?? new TouristPreference();
        $isExisting = $preference->exists;
        $listsBefore = $isExisting ? $this->answerLists($preference) : null;

        $preference->fill(collect($data)->except([
            'activities', 'amenities', 'health_conditions', 'health_other',
            'origin_lat', 'origin_lng', 'origin_label',
            'departure_lat', 'departure_lng', 'departure_label',
            'visitor_origin_country', 'visitor_origin_province', 'visitor_origin_city',
        ])->all());

        $this->applyOrigin($preference, $data);
        if ($v2) {
            // No departure point any more: the old columns are left as they were and are not read (see TouristPreference::departure).
            $preference->forceFill([
                'start_at' => TripWindow::toStorage($window[0]),
                'end_at' => TripWindow::toStorage($window[1]),
                'duration_days' => TripWindow::durationDays($window[0], $window[1]),
                'end_date' => $window[1]->toDateString(),
            ]);
            $this->applyVisitorOrigin($preference, $data);
        } else {
            $this->applyDeparture($preference, $data);
        }

        /*
         * "Regenerate" bumps `variation`, which only breaks ties between equally good plans. It belongs to the answers it
         * was asked for: when the traveller changes an answer here the plan is a new question and starts from the standard
         * result again, instead of inheriting a variation chosen for different answers. Saving the form without changing
         * anything keeps the variation, so Generate shows the plan that is already on screen.
         */
        if ($isExisting && $preference->variation > 0 && ($preference->isDirty() || $listsBefore !== $this->answerListsFromInput($data, $request))) {
            $preference->variation = 0;
        }

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

        if ($request->expectsJson()) {
            return response()->json(['redirect' => route('plan.itinerary')]);
        }

        return redirect()->route('plan.itinerary')
            ->with(Toast::success('Preferences saved', 'Your itinerary is ready below.'));
    }

    public function itinerary(Request $request): View|RedirectResponse
    {
        $preference = $this->currentPreference($request);

        if (! $preference) {
            return redirect()->route('plan.edit')
                ->with(Toast::success('Tell us about your trip', 'Answer a few questions and we will build your itinerary.'));
        }

        $itinerary = $this->currentItinerary($request)
            ?? $this->itineraryService->generate($preference);

        /*
         * Live counts for the provenance panel. Naming the algorithms is only
         * worth anything if the reader can see the size of the data each one
         * actually ran against -- a rule mined from 90 transactions and one
         * mined from 9,000 are not the same claim.
         */
        $catalogue = app(\App\Support\SampleData::class)->split(Destination::publiclyVisible());

        $provenance = [
            'destinations_ranked' => $itinerary->matches()->count(),
            'catalogue_size' => $catalogue['total'],
            'catalogue_real' => $catalogue['real'],
            'catalogue_sample' => $catalogue['sample'],
            'catalogue_unverified' => $catalogue['unverified'],
            'transactions' => ExitSurvey::count(),
            'transactions_demo' => ExitSurvey::where('data_source', 'demo')->count(),
            'rules_applied' => $itinerary->items()->whereNotNull('rule_basis')->count(),
            'origin' => $preference->startLabel(),
            'range_requested' => $preference->distance_pref,
            'range_tier_used' => $itinerary->range_tier_used,
            'range_widened' => $itinerary->range_widened,
            'range_capped_places' => (int) $itinerary->range_capped_places,
        ];

        // The timeline shows each stop's photo and opening hours, so load them together rather than per row.
        $itinerary->load([
            'items.destination.photos', 'items.accommodation.photos', 'items.restaurant.photos', 'items.souvenirCenter.photos',
            'items.destination.region', 'items.accommodation.region', 'items.restaurant.region', 'items.souvenirCenter.region',
        ]);

        $swap = $this->swapData($itinerary);

        // Optional ideas for Day 1's free time before dinner: read-only, never part of the schedule.
        $ideas = app(FreeTimeIdeas::class)->forItinerary($itinerary, $preference);

        // Suggest nearby operators for tour pick-up or car rental; packages already name their provider.
        $operators = ! $itinerary->package_id && TransportMode::suggestsOperators($preference->transport_pref)
            ? app(TourOperatorSuggester::class)->near(
                $preference->origin_lat !== null ? (float) $preference->origin_lat : null,
                $preference->origin_lng !== null ? (float) $preference->origin_lng : null,
                TourOperatorSuggester::LIMIT,
                $preference->transport_pref,
            )
            : collect();

        return view('plan.itinerary', compact('itinerary', 'preference', 'provenance', 'swap', 'ideas', 'operators'));
    }

    /**
     * The current itinerary as a calendar file. It needs the arrival date, since a stop with no date cannot be put
     * in a calendar; without one the traveller is sent to add it.
     */
    public function calendar(Request $request): Response|RedirectResponse
    {
        $preference = $this->currentPreference($request);
        $itinerary = $this->currentItinerary($request);

        if (! $preference || ! $itinerary) {
            return redirect()->route('plan.edit');
        }

        $itinerary->load([
            'items.destination', 'items.accommodation', 'items.restaurant', 'items.souvenirCenter',
        ]);

        $file = app(ItineraryCalendar::class)->build($itinerary, $preference->start_date);

        if ($file === null) {
            return redirect()->route('plan.edit')
                ->with(Toast::success('Add an arrival date', 'A calendar needs the day your trip starts. Add it, then download again.'));
        }

        return response($file, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="exploredavao-itinerary.ics"',
        ]);
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $preference = $this->currentPreference($request);

        if (! $preference) {
            return redirect()->route('plan.edit')
                ->with(Toast::success('Tell us about your trip', 'Answer a few questions and we will build your itinerary.'));
        }

        // A package-adopted itinerary is a fixed schedule the provider
        // published, not something the recommender produced -- there is
        // nothing here for it to regenerate. The button is hidden for this
        // case; this guards the route itself against a direct POST.
        if ($this->currentItinerary($request)?->package_id) {
            return redirect()->route('plan.itinerary')
                ->with(Toast::success('Nothing to regenerate', 'This itinerary comes from a package, not the trip planner.'));
        }

        $regenerateNote = null;
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
        if (isset($position['lat'], $position['lng']) && Features::tripLocation() && ! RegionXi::contains((float) $position['lat'], (float) $position['lng'])) {
            // A reading from outside Davao Region is never used as the start point: keep the one already saved.
            unset($position['lat'], $position['lng']);
            $regenerateNote = 'Your current location is outside Davao Region, so your saved start point was kept.';
        }

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

        return redirect()->route('plan.itinerary')->with(Toast::success('Itinerary regenerated', $regenerateNote ?? 'Rebuilt from your current preferences.'));
    }

    /**
     * The checks added by the trip_location_v2 switch (13 Nov 2026), in one place so the old path stays untouched:
     *
     *  - the arrival date may not be before today in the Philippines (a date already saved with this trip is left alone, so
     *    editing a trip that is under way does not fail on its own date);
     *  - the start point must be inside Region XI (the typed place is looked up once, the same lookup that plans from it);
     *  - "finish by" must come after the start on a one-day trip, and the allowance before it must fit.
     *
     * @param  array<string, mixed>  $data  the validated input
     */
    private function validateTripLocation(Request $request, array $data): void
    {
        $errors = [];

        $point = null;
        if (isset($data['origin_lat'], $data['origin_lng'])) {
            $point = [(float) $data['origin_lat'], (float) $data['origin_lng']];
        } elseif (trim((string) ($data['origin_label'] ?? '')) !== '') {
            $match = $this->addresses->resolve(trim((string) $data['origin_label']));
            $point = $match ? [(float) $match['lat'], (float) $match['lng']] : null;
        }
        if ($point !== null && ! RegionXi::contains($point[0], $point[1])) {
            $errors['origin_label'] = RegionXi::MESSAGE;
        }

        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    /**
     * The trip's real start and end (trip_location_v2). Both have to be real dates and times in Philippine time; the start
     * cannot be in the past (an administrator who is signed in, or TRIP_ALLOW_PAST_START for a demo, can); the end is at
     * least 3 hours after it and the trip at most 30 days (TripWindow).
     *
     * @param  array<string, mixed>  $data  the validated input
     * @return array{0: Carbon, 1: Carbon}
     */
    private function validateTripWindow(array $data): array
    {
        $start = TripWindow::parse($data['start_at'] ?? null);
        $end = TripWindow::parse($data['end_at'] ?? null);

        $errors = [];
        if ($start === null) {
            $errors['start_at'] = 'Enter a real start date and time.';
        }
        if ($end === null) {
            $errors['end_at'] = 'Enter a real end date and time.';
        }

        $allowPast = (bool) config('itinerary.trip_window.allow_past_start') || auth('admin')->check();
        $errors += TripWindow::errors($start, $end, $allowPast);

        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        return [$start, $end];
    }

    /**
     * Where the visitor comes from: a country from our list (or "Prefer not to say"), a province for the Philippines and a
     * typed city or municipality. Only these names are stored, never a position. Anything not on our lists is refused, so
     * free text cannot reach the statistics.
     */
    private function applyVisitorOrigin(TouristPreference $preference, array $data): void
    {
        $country = strtoupper(trim((string) ($data['visitor_origin_country'] ?? '')));
        $reset = ['visitor_origin_country_code' => null, 'visitor_origin_region' => null, 'visitor_origin_province' => null, 'visitor_origin_city' => null, 'visitor_origin_source' => null];

        if ($country === '') {
            $preference->forceFill($reset);   // a local who was not asked, or nothing chosen
            $preference->place_of_origin = null;   // the free-text box is gone with the switch on: nothing answered means no origin, not last trip's

            return;
        }

        if ($country === 'NONE') {
            $preference->forceFill(array_merge($reset, ['visitor_origin_source' => 'unspecified']));
            $preference->place_of_origin = 'Prefer not to say';

            return;
        }

        if (! Countries::has($country)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['visitor_origin_country' => 'Choose a country from the list, or "Prefer not to say".']);
        }

        $province = trim((string) ($data['visitor_origin_province'] ?? ''));
        $region = null;
        if ($country === 'PH' && $province !== '') {
            $region = PhProvinces::regionOf($province);
            if ($region === null) {
                throw \Illuminate\Validation\ValidationException::withMessages(['visitor_origin_province' => 'Choose a province from the list.']);
            }
        } else {
            $province = '';
        }

        // A typed place: plain text only, one line, no markup.
        $city = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($data['visitor_origin_city'] ?? ''))) ?? '');
        $city = Str::limit(preg_replace('/[\x00-\x1F\x7F]/u', '', $city) ?? '', 100, '');

        $preference->forceFill([
            'visitor_origin_country_code' => $country,
            'visitor_origin_region' => $region,
            'visitor_origin_province' => $province !== '' ? $province : null,
            'visitor_origin_city' => $city !== '' ? $city : null,
            'visitor_origin_source' => 'chosen',
        ]);
        // Kept readable for anything that still reads the old text column.
        $preference->place_of_origin = implode(', ', array_filter([$city !== '' ? $city : null, $province !== '' ? $province : null, Countries::name($country)]));
    }

    /**
     * The checks on leaving that need more than one field. Nothing here is guessed: a time needs a departure point the
     * traveller chose, and a one-day trip cannot leave before it arrives. The departure date is not a field: it is the
     * last day of the trip.
     *
     * @param  array<string, mixed>  $data  the validated input
     */
    private function validateDeparture(Request $request, array $data): void
    {
        $errors = [];
        $time = $data['departure_time'] ?? null;
        $days = (int) $data['travel_days'];

        if ($time !== null && ! $this->hasDeparturePoint($data)) {
            $errors['departure_label'] = 'Tell us where you leave from (for example Davao Airport) so we can plan the journey there.';
        }

        // A one-day trip leaves on the day it arrives: the departure has to come after the arrival.
        if ($time !== null && $days === 1 && filled($data['arrival_time'] ?? null)
            && $time <= $data['arrival_time']) {
            $errors['departure_time'] = 'Your departure time has to be after your arrival time on a one-day trip.';
        }

        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    /** A located departure point: coordinates (a tapped place or shared position) or a typed place the geocoder knows. */
    private function hasDeparturePoint(array $data): bool
    {
        if (isset($data['departure_lat'], $data['departure_lng'])) {
            return true;
        }

        $typed = trim((string) ($data['departure_label'] ?? ''));

        return $typed !== '' && $this->addresses->resolve($typed) !== null;
    }

    /**
     * Record where the traveller leaves from. Same rounding as the starting point (about 110 m), and nothing is kept
     * when no departure time was given, so a cleared field really clears.
     */
    private function applyDeparture(TouristPreference $preference, array $data): void
    {
        $typed = trim((string) ($data['departure_label'] ?? ''));

        if (blank($data['departure_time'] ?? null)) {
            $preference->departure_date = $preference->departure_time = null;
            $preference->departure_lat = $preference->departure_lng = $preference->departure_label = null;

            return;
        }

        // Always the derived day (null without an arrival date): a date an old form or a hand-made request posts is
        // not in $data, and an earlier saved one is overwritten here.
        $preference->departure_date = $preference->departureDate();

        if (isset($data['departure_lat'], $data['departure_lng'])) {
            $preference->departure_lat = round((float) $data['departure_lat'], 3);
            $preference->departure_lng = round((float) $data['departure_lng'], 3);
            $preference->departure_label = $typed !== '' ? $typed : 'Your departure point';

            return;
        }

        $match = $this->addresses->resolve($typed);
        $preference->departure_lat = $match ? round($match['lat'], 3) : null;
        $preference->departure_lng = $match ? round($match['lng'], 3) : null;
        $preference->departure_label = $typed;
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
    /**
     * The list-valued answers (kinds of activity, amenities, health conditions) in a form that can be compared.
     *
     * @return array{activities: list<string>, amenities: list<string>, health: list<string>}
     */
    private function answerLists(TouristPreference $preference): array
    {
        $preference->loadMissing('activities', 'amenities', 'healthProfile.conditions');
        $profile = $preference->healthProfile;

        return $this->sortedLists(
            $preference->activities->pluck('activity')->all(),
            $preference->amenities->pluck('amenity')->all(),
            $profile ? [...$profile->conditions->pluck('condition')->all(), 'other:'.trim((string) $profile->other_text)] : [],
        );
    }

    /** The same lists as they would be saved from this request. */
    private function answerListsFromInput(array $data, Request $request): array
    {
        $health = [];
        $other = trim((string) ($data['health_other'] ?? ''));
        if ($request->boolean('health_consent') && ! (empty($data['health_conditions'] ?? []) && $other === '')) {
            $health = [...($data['health_conditions'] ?? []), 'other:'.$other];
        }

        return $this->sortedLists($data['activities'] ?? [], $data['amenities'] ?? [], $health);
    }

    private function sortedLists(array $activities, array $amenities, array $health): array
    {
        $sort = static function (array $list): array {
            $list = array_values(array_unique(array_map('strval', $list)));
            sort($list);

            return $list;
        };

        return ['activities' => $sort($activities), 'amenities' => $sort($amenities), 'health' => $sort($health)];
    }

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

    /**
     * What the itinerary page needs for similar-place swaps: whether to show
     * the Swap buttons, and for any stop under a closing advisory, the notice
     * plus the closest open substitute (so the page can offer it straight away).
     *
     * @return array{available: bool, closed: array<int, array{advisory: string, substitute: ?array}>}
     */
    private function swapData(Itinerary $itinerary): array
    {
        $similar = app(SimilarDestinationService::class);
        $swap = ['available' => false, 'closed' => []];

        if ($itinerary->package_id || ! $similar->isAvailable()) {
            return $swap;
        }

        $swap['available'] = true;
        $window = $similar->windowFor($itinerary);
        $closedIds = $similar->unavailableDestinationIds($window);

        foreach ($itinerary->items->pluck('destination_id')->filter()->unique() as $destinationId) {
            if (! in_array((int) $destinationId, $closedIds, true)) {
                continue;
            }

            $destination = Destination::find($destinationId);
            $closure = $destination ? $similar->closure($destination, $window) : null;
            $best = $destination ? $similar->alternatives($destination, $itinerary, 1)->first() : null;

            $swap['closed'][(int) $destinationId] = [
                'advisory' => $closure['reason'] ?? 'Not available on your dates',
                'substitute' => $best ? [
                    'id' => $best['destination']->id,
                    'name' => $best['destination']->name,
                    'similarity' => (int) round($best['similarity'] * 100),
                    'distance_km' => $best['distance_km'],
                    'sample' => app(\App\Support\SampleData::class)->isSample($best['destination']),
                ] : null,
            ];
        }

        return $swap;
    }

    /**
     * The resort settings at least one bookable resort is filed under, in the order the planner offers them.
     *
     * @return list<string>
     */
    private function availableResortSettings(): array
    {
        $filed = Accommodation::query()
            ->where('is_accredited', true)->whereNull('archived_at')
            ->whereNotNull('resort_settings')
            ->pluck('resort_settings')
            ->flatMap(fn ($encoded) => array_filter(explode('|', (string) $encoded)))
            ->unique()
            ->all();

        if (Accommodation::query()->where('is_accredited', true)->whereNull('archived_at')->where('type', 'Beach Resort')->exists()) {
            $filed[] = 'Beach';
        }

        return array_values(array_filter(Accommodation::RESORT_SETTINGS, fn ($setting) => in_array($setting, $filed, true)));
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
            ? Itinerary::with(['matches.destination', 'items.destination', 'items.accommodation', 'package'])->find($id)
            : null;
    }
}
