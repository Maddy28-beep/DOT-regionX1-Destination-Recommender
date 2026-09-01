@extends('layouts.app')

@section('title', 'My Itinerary — ExploreDVO')

@section('content')
@php
    $itemsByDay = $itinerary->items->sortBy(['day_number', 'sort_order'])->groupBy('day_number');
    $topMatches = $itinerary->matches->sortBy('rank')->take(5);
    $routeStops = $itinerary->routeStops();
@endphp

<div class="dash-shell">
    <div class="dash-header">
        <div class="container">
            <div>
                <h1>My Itinerary</h1>
                <div class="sub">
                    Generated {{ $itinerary->generated_at->format('F j, Y g:i A') }}
                    &middot; {{ $itinerary->total_days }} day{{ $itinerary->total_days === 1 ? '' : 's' }}
                    {{-- Say what the ordering was actually measured from, so a
                         plan sequenced from the regional default is not mistaken
                         for one sequenced from where the traveller is. --}}
                    &middot; ordered from {{ $preference->origin_label ?: 'Davao City centre' }}
                    @if ($preference->arrival_time)
                        &middot; arriving {{ \Illuminate\Support\Carbon::parse($preference->arrival_time)->format('g:i A') }}
                    @endif
                </div>
            </div>
            <a href="{{ route('plan.edit') }}" class="btn btn-outline">Edit preferences</a>
        </div>
    </div>

    <div class="dash-body">
        <div class="container">
            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            {{-- Set expectations honestly: there is no account to keep this
                 in, by design. What a visitor CAN keep is the shortlist, so
                 that is what the banner points at. --}}
            <x-banner tone="info">
                This plan lives in your browser session, so it disappears when you close the tab.
                Nothing here is tied to your name &mdash; there are no traveler accounts.
                <a href="{{ route('saved.index') }}"><strong>Heart the places you like</strong></a>
                and they will still be here when you come back.
            </x-banner>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Recommended Destinations</h2>
                        <p>Ranked by Destination Recommendation Score, based on your travel preferences (Content-Based Recommendation).</p>
                    </div>
                    <form method="POST" action="{{ route('plan.regenerate') }}" id="regenerate-form">
                        @csrf
                        <input type="hidden" name="lat" id="regenerate-lat">
                        <input type="hidden" name="lng" id="regenerate-lng">
                        <button type="submit" class="btn btn-primary">Regenerate Itinerary</button>
                    </form>
                </div>
                <div class="panel-body">
                    <div class="table-scroll">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Destination</th>
                                    <th>Match Score</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($topMatches as $match)
                                    <tr>
                                        <td>{{ $match->rank }}</td>
                                        <td><a href="{{ route('destinations.show', $match->destination) }}">{{ $match->destination->name }}</a></td>
                                        <td>{{ number_format($match->match_score, 2) }} / 5.00</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Day-by-Day Travel Plan</h2>
                        <p>Sequenced by geographic proximity (Haversine distance + Nearest Neighbor heuristic), with complementary establishments surfaced via Association Rule Mining (Apriori Algorithm).</p>
                    </div>
                </div>
                <div class="panel-body">
                    @if ($itemsByDay->isEmpty())
                        <div class="empty-panel">
                            <div class="icon"><x-icon name="compass" /></div>
                            <h3>No itinerary items yet</h3>
                            <p>Try regenerating your itinerary above.</p>
                        </div>
                    @else
                        @foreach ($itemsByDay as $day => $items)
                            @php
                                // Every place of the day, in order, deduped -- see
                                // Itinerary::routeStops(). Only the ones we hold
                                // coordinates for can be drawn on the inline map;
                                // the rest still travel to Google Maps by name.
                                $stops = $routeStops[$day] ?? [];
                                $plottable = array_values(array_filter(
                                    $stops,
                                    fn ($s) => $s['lat'] !== null && $s['lng'] !== null
                                ));
                                // Unique by name: the hotel legitimately appears twice
                                // in a day's route (leaving it, returning to it), but
                                // naming it twice in one sentence reads as a bug.
                                $unplottable = collect($stops)
                                    ->filter(fn ($s) => $s['lat'] === null || $s['lng'] === null)
                                    ->pluck('label')
                                    ->unique()
                                    ->values();
                                $mapsUrl = \App\Models\Itinerary::googleMapsUrl($stops);
                            @endphp

                            <div class="itinerary-day">
                                <h3>Day {{ $day }}</h3>

                                @foreach ($items as $item)
                                    @php
                                        $listing = $item->listing();
                                        $route = match (true) {
                                            (bool) $item->destination_id => 'destinations.show',
                                            (bool) $item->accommodation_id => 'accommodations.show',
                                            (bool) $item->restaurant_id => 'restaurants.show',
                                            (bool) $item->souvenir_center_id => 'souvenir-centers.show',
                                            default => null,
                                        };
                                    @endphp
                                    <div class="itinerary-item itinerary-item--{{ $item->kind }}">
                                        <span class="badge">{{ $item->slot }}</span>
                                        <div class="itinerary-item__body">
                                            <strong>
                                                {{-- Most titles already name the place, so linking the
                                                     title itself avoids "Dinner at Acacia — Acacia". --}}
                                                @if ($listing && $route && str_contains($item->title, $listing->name))
                                                    <a href="{{ route($route, $listing) }}">{{ $item->title }}</a>
                                                @elseif ($listing && $route)
                                                    {{ $item->title }} &mdash;
                                                    <a href="{{ route($route, $listing) }}">{{ $listing->name }}</a>
                                                @else
                                                    {{ $item->title }}
                                                @endif
                                            </strong>
                                            <div class="sub">
                                                {{ $item->timeLabel() }}
                                                @if ($item->timeLabel()) &middot; @endif
                                                {{ $item->travelSummary() }}
                                            </div>
                                            @if ($item->ruleExplanation())
                                                <div class="sub itinerary-item__rule">{{ $item->ruleExplanation() }}</div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach

                                @if ($stops)
                                    <div class="day-actions">
                                        @if ($mapsUrl)
                                            <a href="{{ $mapsUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline ext-link">
                                                Open Day {{ $day }} in Google Maps
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                                                    <path d="M15 3h6v6"/><path d="M10 14 21 3"/>
                                                </svg>
                                                <span class="sr-only">(opens all {{ count($stops) }} stops as a route in a new tab)</span>
                                            </a>
                                        @endif

                                        @if (count($plottable) >= 2)
                                            <details>
                                                <summary class="btn btn-outline" style="display:inline-block; cursor:pointer;">View turn-by-turn directions for Day {{ $day }}</summary>
                                                <div style="margin-top:12px;">
                                                    @include('partials.route-map', ['stops' => $plottable, 'mapId' => 'route-day-'.$day])
                                                </div>
                                            </details>
                                        @endif
                                    </div>

                                    {{-- Say which stops the inline map is missing rather than
                                         quietly drawing an incomplete day. --}}
                                    @if ($unplottable->isNotEmpty())
                                        <p class="sub day-unmapped">
                                            Not on the map below &mdash; we don't hold coordinates for
                                            {{ $unplottable->join(', ', ' and ') }}.
                                            The Google Maps link includes {{ $unplottable->count() === 1 ? 'it' : 'them' }}.
                                        </p>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>How This Plan Was Built</h2>
                        <p>Which method produced which part, and the data each one ran against.</p>
                    </div>
                </div>
                <div class="panel-body">
                    <ol class="provenance">
                        <li>
                            <span class="provenance-step">Ranking</span>
                            <div>
                                <strong>Content-Based Recommendation</strong> scored
                                {{ $provenance['destinations_ranked'] }} of
                                {{ $provenance['catalogue_size'] }} accredited destinations against your
                                survey answers, combining five weighted factors into the Destination
                                Recommendation Score shown above (Sec. 2.3.3, Equations 1&ndash;3).
                            </div>
                        </li>
                        <li>
                            <span class="provenance-step">Order</span>
                            <div>
                                <strong>Nearest-neighbour sequencing</strong> on Haversine distance
                                arranged the highest-scoring stops into the shortest sensible run,
                                starting from {{ $provenance['origin'] }}. Journey times are planning
                                estimates, not routed directions.
                            </div>
                        </li>
                        <li>
                            <span class="provenance-step">Companions</span>
                            <div>
                                <strong>Apriori association rule mining</strong> over
                                {{ $provenance['transactions'] }} visitation
                                transaction{{ $provenance['transactions'] === 1 ? '' : 's' }} suggested
                                where to eat, shop and stay
                                @if ($provenance['rules_applied'] > 0)
                                    &mdash; {{ $provenance['rules_applied'] }}
                                    row{{ $provenance['rules_applied'] === 1 ? '' : 's' }} below carr{{ $provenance['rules_applied'] === 1 ? 'ies' : 'y' }}
                                    the rule and its confidence (Equations 8&ndash;9).
                                @else
                                    &mdash; no rule cleared the support threshold for these stops, so
                                    the suggestions below fall back to proximity and your stated
                                    preferences.
                                @endif
                            </div>
                        </li>
                    </ol>
                    <p class="sub" style="margin-top:14px;">
                        This is a recommended plan, not a booking. It performs no reservation or payment,
                        and it is yours to change to fit your time, budget and pace.
                    </p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>After Your Trip</h2>
                        <p>Once you're back, a couple of minutes of feedback helps DOT Region XI improve this plan for the next traveller.</p>
                    </div>
                </div>
                <div class="panel-body">
                    <p class="sub">
                        There's no account here, so nothing will remind you automatically &mdash;
                        bookmark this page or the link below now, and come back to it after your trip.
                        The exit survey takes about two minutes and, like everything else here, is
                        completely anonymous.
                    </p>
                    <a href="{{ route('exit-survey.create') }}" class="btn btn-outline" style="margin-top:6px;">
                        Open the exit survey
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    /*
     * Regenerating takes a fresh position if the traveller allows it, so a plan
     * rebuilt part-way through a trip is sequenced from where they are now
     * rather than where they started. Every failure path still submits: a
     * refused or unavailable location falls back to the saved starting point,
     * never to a blocked button.
     */
    document.getElementById('regenerate-form')?.addEventListener('submit', function (e) {
        if (!navigator.geolocation) return;
        e.preventDefault();
        const form = this;
        navigator.geolocation.getCurrentPosition(
            function (pos) {
                // Coarsened here as well as server-side; see
                // TripPlannerController::applyOrigin.
                document.getElementById('regenerate-lat').value = pos.coords.latitude.toFixed(3);
                document.getElementById('regenerate-lng').value = pos.coords.longitude.toFixed(3);
                form.submit();
            },
            function () { form.submit(); },
            { timeout: 8000, maximumAge: 300000 }
        );
    });
</script>
@endsection
