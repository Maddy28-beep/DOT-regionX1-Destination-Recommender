@extends('layouts.app')

@section('title', 'My Itinerary — ExploreDVO')

@section('content')
@php
    $itemsByDay = $itinerary->items->sortBy(['day_number', 'sort_order'])->groupBy('day_number');
    // At least 5, but never fewer than the destinations actually scheduled
    // below -- a fixed take(5) let the day-by-day plan use a 6th (or later)
    // ranked destination that then never appeared in this summary table at
    // all, which read as the itinerary using a place it hadn't recommended.
    // distinctTopMatches() additionally keeps only the best-scoring branch
    // per business name, so a business with several accredited branches
    // (Elysia Wellness Spa, Rancho Palos Verdes) doesn't occupy more than
    // one of these slots.
    $scheduledDestinationCount = $itinerary->items->pluck('destination_id')->filter()->unique()->count();
    $topMatches = $itinerary->distinctTopMatches(max(5, $scheduledDestinationCount));
    $routeStops = $itinerary->routeStops();

    $rangeTierLabels = ['near' => 'Within the City', 'moderate' => 'Moderate distance', 'far' => 'Willing to travel farther'];
@endphp

<div class="dash-shell">
    <div class="dash-header">
        <div class="container">
            <div>
                <span class="poster-kicker" style="font-size:1.05rem;">ready to go</span>
                <h1 class="page-title" style="font-size:1.9rem; margin:0;">My Itinerary</h1>
                <div class="sub">
                    Generated {{ $itinerary->generated_at->format('F j, Y g:i A') }}
                    &middot; {{ $itinerary->total_days }} day{{ $itinerary->total_days === 1 ? '' : 's' }}
                    @if ($itinerary->package)
                        &middot; from the <a href="{{ route('packages.show', $itinerary->package) }}">{{ $itinerary->package->name }}</a> package
                    @else
                        {{-- Say what the ordering was actually measured from, so a
                             plan sequenced from the regional default is not mistaken
                             for one sequenced from where the traveller is. --}}
                        &middot; ordered from {{ $preference->origin_label ?: 'Davao City centre' }}
                        @if ($preference->arrival_time)
                            &middot; arriving {{ \Illuminate\Support\Carbon::parse($preference->arrival_time)->format('g:i A') }}
                        @endif
                    @endif
                </div>
            </div>
            <a href="{{ route('plan.edit') }}" class="btn btn-outline">Edit preferences</a>
        </div>
    </div>

    <div class="dash-body">
        <div class="container">
            {{-- Set expectations honestly: there is no account to keep this
                 in, by design. What a visitor CAN keep is the shortlist, so
                 that is what the note points at. Cream + dashed gold rather
                 than the internal console's blue x-banner, so a standing
                 fact about this page reads in its own brand voice instead of
                 an admin-console notice. --}}
            <div class="session-note" role="status">
                <x-icon name="alert-triangle" />
                <p>
                    This plan lives in your browser session, so it disappears when you close the tab
                    &mdash; there are no traveler accounts.
                    <a href="{{ route('saved.index') }}">Heart the places you like</a>
                    and they will still be here when you come back.
                </p>
            </div>

            @unless ($itinerary->package)
            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Recommended Destinations</h2>
                        <p>Ranked by how well each place matches your travel preferences.</p>
                    </div>
                    <form method="POST" action="{{ route('plan.regenerate') }}" id="regenerate-form">
                        @csrf
                        <input type="hidden" name="lat" id="regenerate-lat">
                        <input type="hidden" name="lng" id="regenerate-lng">
                        <button type="submit" class="btn btn-primary">Regenerate Itinerary</button>
                    </form>
                </div>
                <div class="panel-body">
                    <ul class="match-list">
                        @foreach ($topMatches as $match)
                            @php $tier = $match->match_score >= 3.5 ? 'strong' : 'fair'; @endphp
                            <li class="match-row">
                                <span class="match-rank">{{ $match->rank }}</span>
                                <div class="match-info">
                                    <a href="{{ route('destinations.show', $match->destination) }}">{{ $match->destination->name }}</a>
                                    <div class="match-bar-track">
                                        <div class="match-bar-fill match-bar-fill--{{ $tier }}" style="width: {{ min(100, max(0, $match->match_score / 5 * 100)) }}%;"></div>
                                    </div>
                                </div>
                                <span class="match-score"><span class="sr-only">Match Score: </span>{{ number_format($match->match_score, 2) }} / 5.00</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
            @endunless

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Day-by-Day Travel Plan</h2>
                        <p>
                            @if ($itinerary->package)
                                As published by {{ $itinerary->package->provider_name ?? 'the provider' }} for this package.
                            @else
                                Sequenced by geographic proximity, with complementary stops surfaced from what past travelers tend to pair together.
                            @endif
                        </p>
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

                                <div class="day-timeline">
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
                                        <div class="itinerary-item__body">
                                            {{-- A travel connector only ever needs the bare clock
                                                 time -- it isn't a scheduled stop with a slot of its
                                                 own, so pairing it with "AFTERNOON" read as a second,
                                                 phantom stop rather than the journey between two. --}}
                                            @if ($item->timeLabel() || ($item->kind !== 'travel' && $item->slot))
                                                <div class="itinerary-item__meta">
                                                    @if ($item->kind !== 'travel' && $item->slot)
                                                        {{ $item->slot }}
                                                        @if ($item->timeLabel()) &middot; @endif
                                                    @endif
                                                    {{ $item->timeLabel() }}
                                                </div>
                                            @endif
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
                                                {{ $item->travelSummary() }}
                                            </div>
                                            @if ($item->note)
                                                <div class="sub">{{ $item->note }}</div>
                                            @endif
                                            @if ($item->ruleExplanation())
                                                <div>
                                                    <span class="pairing-tag">Popular pairing with {{ $item->rule_basis }}</span>
                                                    <details class="pairing-why">
                                                        <summary>why this pick?</summary>
                                                        <p>{{ $item->ruleExplanation() }}</p>
                                                    </details>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                                </div>

                                @if ($stops)
                                    <div class="day-actions">
                                        @if ($mapsUrl)
                                            <a href="{{ $mapsUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline ext-link">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                                                    <path d="M15 3h6v6"/><path d="M10 14 21 3"/>
                                                </svg>
                                                Open Day {{ $day }} in Google Maps
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
                                            Not on the map above &mdash; we don't hold coordinates for
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

            @if ($itinerary->package)
            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>How This Plan Was Built</h2>
                        <p>This one isn't generated.</p>
                    </div>
                </div>
                <div class="panel-body">
                    <p class="sub">
                        This itinerary is the day-by-day schedule
                        <a href="{{ route('packages.show', $itinerary->package) }}">{{ $itinerary->package->name }}</a>'s
                        provider published for this package, copied here as-is &mdash; no recommendation
                        algorithm ranked or reordered any of it. Want a plan built around your own
                        preferences instead? <a href="{{ route('plan.edit') }}">Start the trip planner</a>.
                    </p>
                    <p class="sub" style="margin-top:14px;">
                        This is a recommended plan, not a booking. It performs no reservation or payment,
                        and it is yours to change to fit your time, budget and pace.
                    </p>
                </div>
            </div>
            @else
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
                                @if ($provenance['range_widened'])
                                    <br><strong>Note:</strong> you asked for
                                    &ldquo;{{ $rangeTierLabels[$provenance['range_requested']] ?? $provenance['range_requested'] }}&rdquo;,
                                    but there weren&rsquo;t enough destinations that close to
                                    {{ $provenance['origin'] }} to fill a {{ $itinerary->total_days }}-day
                                    plan, so the search was automatically widened to
                                    &ldquo;{{ $rangeTierLabels[$provenance['range_tier_used']] ?? $provenance['range_tier_used'] }}&rdquo;
                                    range. Some stops below may be farther than you expected.
                                @endif
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
            @endif

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
