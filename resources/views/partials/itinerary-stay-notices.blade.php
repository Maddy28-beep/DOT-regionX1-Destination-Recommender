{{--
    What the stay planner has to tell the traveller (itineraries.stay_plan). Nothing here for an itinerary made before the
    column existed, or when there is nothing to say. Every sentence is a fact read from the saved plan: places that were
    set out for the trip and could not be placed (with why), nights with no accredited stay, and a strict one-stay range
    that could not be kept because no single accredited stay is open for every night.

    Expects $itinerary and $hasOvernight (does the trip have an overnight stay at all?).
--}}
@php
    $stayPlan = $itinerary->stay_plan;
    $stayNotices = collect(data_get($stayPlan, 'notices', []));
    $stayMode = data_get($stayPlan, 'mode');
    $unplaced = in_array($stayMode, ['near', 'moderate'], true) ? $stayNotices->where('type', 'unplaced_destination')->values() : collect();
    // When the trip has no stay at all, the page already says so; naming each night as well would repeat it.
    $uncoveredNights = $hasOvernight ? $stayNotices->where('type', 'no_stay_for_night')->pluck('night')->values() : collect();
    $noSingleStay = $stayNotices->contains('type', 'no_single_stay_covers_trip');
@endphp

@if ($unplaced->isNotEmpty())
    <div class="itin-notice" role="note" style="margin:0 0 16px; padding:12px 16px; border-radius:12px; background:#fff7e6; border:1px solid #f0c36d;">
        <strong>{{ $unplaced->count() }} {{ \Illuminate\Support\Str::plural('place', $unplaced->count()) }} we set out to include could not be fitted into your plan.</strong>
        @if ($stayMode === 'near')
            Your range keeps the same place to stay for the whole trip, so the plan was not changed to reach {{ $unplaced->count() === 1 ? 'it' : 'them' }}.
        @endif
        <ul style="margin:8px 0 0 18px; padding:0;">
            @foreach ($unplaced as $notice)
                <li>
                    <strong>{{ $notice['name'] }}</strong>:
                    @if (($notice['reason'] ?? null) === 'over_daily_driving_limit' && ! empty($notice['minutes']))
                        about {{ $notice['minutes'] }} minutes of driving from your stay and back, more than a day's driving limit for your range.
                    @else
                        it did not fit into your days before the {{ \App\Services\Recommendation\ItineraryScheduleBuilder::LAST_ACTIVITY_END }} sightseeing cutoff.
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif

@if ($noSingleStay)
    <div class="itin-notice" role="note" style="margin:0 0 16px; padding:12px 16px; border-radius:12px; background:#fff7e6; border:1px solid #f0c36d;">
        <strong>No single DOT-accredited stay is open for every night of your trip.</strong>
        Your plan uses the fewest stays possible. Check the <a href="{{ route('accommodations.index') }}">accommodation list</a> for the latest, or contact DOT Region XI.
    </div>
@endif

@if ($uncoveredNights->isNotEmpty())
    <div class="itin-notice" role="note" style="margin:0 0 16px; padding:12px 16px; border-radius:12px; background:#fff7e6; border:1px solid #f0c36d;">
        <strong>No DOT-accredited accommodation is available for {{ $uncoveredNights->count() === 1 ? 'night' : 'nights' }} {{ $uncoveredNights->implode(', ') }} of your trip.</strong>
        We could not find an accredited place to stay that is open for {{ $uncoveredNights->count() === 1 ? 'that night' : 'those nights' }}, so no stay is planned.
        Check the <a href="{{ route('accommodations.index') }}">accommodation list</a> for the latest, or contact DOT Region XI.
    </div>
@endif
