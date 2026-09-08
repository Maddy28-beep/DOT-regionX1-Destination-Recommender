<?php

namespace App\Http\Controllers;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\ExitSurveyActivity;
use App\Models\ExitSurveyVisit;
use App\Models\Package;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use App\Support\Toast;

class ExitSurveyController extends Controller
{
    /** Kept in one place so the form's options and the store() validation can never drift apart. */
    public const TRAVEL_PURPOSES = [
        'Leisure', 'Business', 'Visiting Friends/Family', 'Educational', 'Medical', 'Religious/Pilgrimage', 'Other',
    ];

    public const ACTIVITIES = [
        'Beach & Island', 'Nature & Adventure', 'Cultural Heritage', 'Wildlife',
        'Food Tourism', 'Shopping & Souvenirs', 'Hiking & Trekking', 'Relaxation & Wellness',
    ];

    /** The listing kinds a tourist can actually report visiting -- deliberately narrower
     *  than the app's full morph map, which also carries 'admin' and 'establishment' for
     *  unrelated features (audit logs, accreditation) that have no place in this survey. */
    private const VISITABLE_KINDS = [
        'destination', 'accommodation', 'restaurant', 'package', 'souvenir_center', 'tour_operator',
    ];

    /** Upper bound on how many places/activities one survey can report, so a single malicious
     *  request can't trigger thousands of one-row-at-a-time inserts. */
    private const MAX_LIST_ITEMS = 50;

    public function create(): View
    {
        $placeGroups = [
            'destination' => ['label' => 'Destinations', 'items' => Destination::publiclyVisible()->orderBy('name')->get(['id', 'name'])],
            'accommodation' => ['label' => 'Accommodations', 'items' => Accommodation::publiclyVisible()->orderBy('name')->get(['id', 'name'])],
            'restaurant' => ['label' => 'Restaurants', 'items' => Restaurant::publiclyVisible()->orderBy('name')->get(['id', 'name'])],
            'package' => ['label' => 'Tour Packages', 'items' => Package::publiclyVisible()->orderBy('name')->get(['id', 'name'])],
            'souvenir_center' => ['label' => 'Souvenir Centers', 'items' => SouvenirCenter::publiclyVisible()->orderBy('name')->get(['id', 'name'])],
            'tour_operator' => ['label' => 'Tour Operators', 'items' => TourOperator::publiclyVisible()->orderBy('name')->get(['id', 'name'])],
        ];

        return view('exit-survey.create', [
            'placeGroups' => $placeGroups,
            'travelPurposes' => self::TRAVEL_PURPOSES,
            'activityOptions' => self::ACTIVITIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $visitableKinds = implode('|', self::VISITABLE_KINDS);

        $data = $request->validate([
            'residency_type' => ['nullable', 'in:Local Resident,Domestic Tourist,Foreign Tourist'],
            'visitor_type' => ['nullable', 'in:First-time Visitor,Returning Visitor,Regular / Local'],
            'origin' => ['nullable', 'string', 'max:150'],
            'travel_purpose' => ['nullable', 'in:'.implode(',', self::TRAVEL_PURPOSES)],
            'actual_days_stayed' => ['nullable', 'integer', 'min:1', 'max:365'],
            'places_visited' => ['nullable', 'array', 'max:'.self::MAX_LIST_ITEMS],
            'places_visited.*' => ['string', 'regex:/^('.$visitableKinds.'):\d+$/'],
            'activities' => ['nullable', 'array', 'max:'.self::MAX_LIST_ITEMS],
            'activities.*' => ['string', 'in:'.implode(',', self::ACTIVITIES)],
            'overall_rating' => ['required', 'integer', 'min:1', 'max:5'],
            'destination_relevant' => ['nullable', 'integer', 'min:1', 'max:5'],
            'itinerary_useful' => ['nullable', 'integer', 'min:1', 'max:5'],
            'attractions_quality' => ['nullable', 'integer', 'min:1', 'max:5'],
            'accommodation_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'transport_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'would_recommend' => ['required', 'in:Yes,No'],
            'comments' => ['nullable', 'string', 'max:500'],
        ]);

        /*
         * Tie the survey to the plan it is reporting back on, when the
         * traveller still has one in this session.
         *
         * This is what turns a survey into a labelled example: the preference
         * records what they asked for and what the recommender scored against,
         * the survey records where they actually went. Without the key, the
         * DRS weights in Equation 3 can only ever be hand-set, because nothing
         * can be checked against an outcome.
         *
         * Read straight from the session rather than required: the survey is
         * open to anyone, including a traveller who never made a plan, and it
         * must keep working for them.
         */
        $preferenceId = $request->session()->get(TripPlannerController::PREFERENCE_KEY);

        DB::transaction(function () use ($data, $preferenceId) {
            $survey = ExitSurvey::create(
                collect($data)->except(['places_visited', 'activities'])
                    ->put('preference_id', $preferenceId)
                    ->all()
            );

            foreach ($data['places_visited'] ?? [] as $place) {
                [$kind, $id] = explode(':', $place, 2);
                ExitSurveyVisit::create(['exit_survey_id' => $survey->id, 'listing_kind' => $kind, 'listing_id' => $id]);
            }

            foreach ($data['activities'] ?? [] as $activity) {
                ExitSurveyActivity::create(['exit_survey_id' => $survey->id, 'activity' => $activity]);
            }
        });

        return redirect()->route('exit-survey.create')->with(Toast::success('Thanks for your feedback', 'Your response helps DOT Region XI improve tourism services.'));
    }
}
