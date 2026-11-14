<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Official-only mode
    |--------------------------------------------------------------------------
    |
    | Off (the default): sample and unverified listings take part in planning, swaps, suggestions and the chatbot, always
    | labelled as such. On (ITINERARY_OFFICIAL_ONLY=true): every candidate the system recommends or plans with must hold
    | a genuine DOT accreditation record (App\Support\SampleData::restrictForPlanner). Browsing pages still list
    | everything, with the labels. Turning it on shrinks the pool a lot until more official places are on file.
    |
    */

    'official_only' => (bool) env('ITINERARY_OFFICIAL_ONLY', false),

    /*
    |--------------------------------------------------------------------------
    | Where the traveller sleeps
    |--------------------------------------------------------------------------
    |
    | How the itinerary planner chooses a stay for each night of a multi-day trip
    | (ItineraryGenerationService::planStays). All times are minutes of estimated
    | driving, the same slow-end estimate the schedule uses for every journey.
    |
    */

    'stays' => [

        /*
         * What one change of stay costs the traveller (packing, checking out, finding the next place), in minutes of
         * driving. A change is made only when it saves more than this over the nights it covers. Close Proximity never
         * changes stay (strict), and Commutable keeps one stay unless a real comparison of two itineraries shows the
         * one-stay plan is impractical, so this value decides Remote trips and the flexible comparison plan.
         *
         * 30 is the value the planner has always used (the old "keep the stay unless another saves 30 minutes").
         * The value to keep is chosen on the audit: see itinerary-audit/PROPOSAL_stage1_stay_continuity_v2.md, section 6.
         */
        'transfer_penalty_minutes' => [
            'far' => (int) env('ITINERARY_STAY_TRANSFER_PENALTY_FAR', 30),
        ],

        /*
         * A stay that past travellers paired with one of the trip's stops (an Apriori rule) is preferred over the
         * cheapest stay of the same nights when it costs no more than this many extra minutes in total.
         */
        'apriori_band_minutes' => 15,

        /*
         * A rebuilt itinerary replaces the first build only if it is better by at least this many minutes, and a
         * one-stay plan is called impractical only if it is worse by more than this. 15 is the step the travel
         * estimator rounds every journey to, so anything smaller is noise.
         */
        'acceptance_tolerance_minutes' => 15,

        /*
         * The most extra builds one itinerary may cost after the first: one rebuild on the stops actually placed, plus
         * one comparison build for a Commutable trip. A fixed bound, never a loop.
         */
        'max_extra_builds' => 2,

        /*
         * For calibration and measurement only: treats every trip as this distance preference (near, moderate or far)
         * when choosing stays, so the transfer penalty can be measured on all trips and not just the Remote ones.
         * Leave empty. The stops, the ranges and the drive budgets are never affected.
         */
        'force_mode' => env('ITINERARY_STAY_FORCE_MODE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notes on a saved itinerary
    |--------------------------------------------------------------------------
    |
    | Wording the itinerary page and the followability report (itinerary:followability) show about a plan that already
    | exists. These are MESSAGE-ONLY lines: nothing under app/Services/Recommendation reads them, no stop is moved or
    | removed because of them, and they are never a cap on driving.
    |
    */

    'notes' => [
        /*
         * A day whose slow-end driving adds up to more than this many minutes (8 h) is called a long driving day. The page
         * has always carried a gentler tip from 4 h of travel; this is the stronger wording above 8 h.
         */
        'long_driving_day_minutes' => 480,
    ],

    /*
    |--------------------------------------------------------------------------
    | Leaving: the departure time a traveller may give
    |--------------------------------------------------------------------------
    |
    | With a departure time and a departure point, the last day is planned so the journey from the last stop to that
    | point still arrives this many minutes before the departure (ItineraryScheduleBuilder). It is a planning allowance
    | stated on the itinerary, not an airline or airport rule: check-in and security need differ by carrier and
    | route. The journey itself is the planner's own estimate (straight line x 1.3 detour, slowest pace; no routing
    | service), so it is stated as an estimate too. Set ITINERARY_DEPARTURE_BUFFER_MINUTES to change the allowance.
    |
    */

    'departure' => [
        'airport_buffer_minutes' => (int) env('ITINERARY_DEPARTURE_BUFFER_MINUTES', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trip start and end (trip_location_v2)
    |--------------------------------------------------------------------------
    |
    | The traveller gives the real start (when they are free at the pick-up location) and the real end (when the trip has
    | to be finished), both in Philippine time. The end has to be at least min_hours after the start and the trip at most
    | max_days long (days counted both ways: the length, and the calendar days it touches).
    |
    | allow_past_start (TRIP_ALLOW_PAST_START=true) lets a start in the past through for everyone, for a demo. An
    | administrator who is signed in is always allowed. Visitors are anonymous, so there is no "demo account" to key on.
    |
    */

    'trip_window' => [
        'min_hours' => (int) env('TRIP_MIN_HOURS', 3),
        'max_days' => (int) env('TRIP_MAX_DAYS', 30),
        'allow_past_start' => (bool) env('TRIP_ALLOW_PAST_START', false),
    ],
];
