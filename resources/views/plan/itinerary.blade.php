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

    // Directions-only "Starting Point" widget below is package-specific and
    // only makes sense once we actually know where the package is -- see the
    // panel itself for why this doesn't fall back to a text-search guess.
    $packageCoords = ($itinerary->package && $itinerary->package->latitude && $itinerary->package->longitude)
        ? ['lat' => (float) $itinerary->package->latitude, 'lng' => (float) $itinerary->package->longitude]
        : null;
@endphp

<div class="dash-shell">
    <div class="dash-header">
        <div class="container">
            <div>
                <span class="poster-kicker" style="font-size:1.05rem;">ready to go</span>
                <h1 class="page-title" style="font-size:1.9rem; margin:0;">{{ $itinerary->title ?: 'My Itinerary' }}</h1>
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
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                @if ($itinerary->tourist_account_id)
                    <a href="{{ route('account.itineraries.show', $itinerary) }}" class="btn btn-outline">Saved to My Itineraries &check;</a>
                @else
                    <form method="POST" action="{{ route('plan.itinerary.save') }}">
                        @csrf
                        <button type="submit" class="btn btn-outline">Save Itinerary</button>
                    </form>
                @endif
                {{--
                    A package-adopted itinerary has no real preferences behind
                    it to edit (see PackageController::planWith()) -- the
                    correct way to move on from it is the "Start the trip
                    planner" link in the panel below, which says plainly that
                    it builds a fresh, different plan rather than implying
                    there is something of the tourist's own to refine here.
                --}}
                @unless ($itinerary->package)
                    <a href="{{ route('plan.edit') }}" class="btn btn-outline">Edit preferences</a>
                @endunless
            </div>
        </div>
    </div>

    <div class="dash-body">
        <div class="container">
            @if (session('pending_save_itinerary'))
                <div class="privacy-note" role="status">
                    <x-icon name="shield-check" />
                    <p>
                        <strong>Save your itinerary.</strong> Your current itinerary can now be saved to your
                        new account.
                        <form method="POST" action="{{ route('plan.itinerary.save') }}" style="display:inline;">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-xs" style="margin-left:6px;">Save Itinerary</button>
                        </form>
                    </p>
                </div>
            @endif

            {{-- Set expectations honestly: no account is needed to plan or view
                 a trip, by design -- this note is about what happens if you
                 don't create the optional one. Cream + dashed gold rather than
                 the internal console's blue x-banner, so a standing fact about
                 this page reads in its own brand voice instead of an
                 admin-console notice. --}}
            <div class="session-note" role="status">
                <x-icon name="alert-triangle" />
                <p>
                    This plan lives in your browser session, so it disappears when you close the tab
                    &mdash; unless you save it. <a href="{{ route('saved.index') }}">Heart the places you like</a>
                    and they will still be here when you come back, or
                    <a href="{{ route('account.register') }}">create a free account</a> to keep this whole itinerary.
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
                        @unless ($itinerary->package)
                            <button type="button" class="itinerary-explainer-trigger" id="itineraryExplainerOpen"
                                    aria-haspopup="dialog" aria-controls="itineraryExplainerModal" aria-expanded="false">
                                <x-icon name="info" />
                                How was this itinerary created?
                            </button>
                        @endunless
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

            @if ($packageCoords)
                {{--
                    Directions only -- never reorders, reschedules, or
                    resends this package through any recommendation or ML
                    step. Gated on the package actually having coordinates
                    (like every other proximity feature in this codebase,
                    e.g. partials/map-embed) rather than guessing a location
                    from its free-text region string.
                --}}
                <div class="panel">
                    <div class="panel-head">
                        <div>
                            <h2>Starting Point</h2>
                            <p>Get directions to {{ $itinerary->package->name }} from wherever your trip begins. This only sets up navigation &mdash; it doesn't change the package's itinerary.</p>
                        </div>
                    </div>
                    <div class="panel-body">
                        <div class="package-nav">
                            <input type="text" id="package-nav-origin" class="package-nav__input"
                                   placeholder="Hotel, airport, or address (optional)">
                            <div class="package-nav__actions">
                                <button type="button" class="btn btn-outline" id="package-nav-locate">Use my current location</button>
                                <a href="#" target="_blank" rel="noopener noreferrer" class="btn btn-primary" id="package-nav-open">Open Navigation</a>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

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

@unless ($itinerary->package)
    {{--
        A hidden-by-default explainer, not a permanent panel: the tourist sees
        the itinerary first, and only reaches this if they click "How was
        this itinerary created?" above. Structurally a sibling of .dash-shell
        (same reasoning as partials/header.blade.php's .mobile-menu) so it is
        never clipped or repositioned by an ancestor's own layout.
    --}}
    <div class="info-modal-overlay" id="itineraryExplainerOverlay"></div>
    <div class="info-modal" id="itineraryExplainerModal" role="dialog" aria-modal="true" aria-labelledby="itineraryExplainerTitle" inert>
        <div class="info-modal__card">
            <div class="info-modal__head">
                <h3 id="itineraryExplainerTitle">How was your itinerary created?</h3>
                <button type="button" class="info-modal__close" id="itineraryExplainerClose" aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
                </button>
            </div>
            <div class="info-modal__body">
                <p>Your itinerary is personalized using several recommendation and planning methods:</p>

                <ul class="explainer-list">
                    <li>
                        <span class="explainer-emoji" aria-hidden="true">🎯</span>
                        <div>
                            <strong>Personalized recommendations</strong>
                            <p>Destinations are ranked based on how well they match your travel preferences.</p>
                        </div>
                    </li>
                    <li>
                        <span class="explainer-emoji" aria-hidden="true">🔗</span>
                        <div>
                            <strong>Travel patterns</strong>
                            <p>The system identifies destinations and establishments that travelers commonly visit together.</p>
                        </div>
                    </li>
                    <li>
                        <span class="explainer-emoji" aria-hidden="true">📍</span>
                        <div>
                            <strong>Route planning</strong>
                            <p>Stops are arranged based on geographic proximity to create a practical travel sequence.</p>
                        </div>
                    </li>
                    <li>
                        <span class="explainer-emoji" aria-hidden="true">✨</span>
                        <div>
                            <strong>Smart day grouping</strong>
                            <p>A pretrained machine learning model helps organize the selected stops across your available travel days.</p>
                        </div>
                    </li>
                    <li>
                        <span class="explainer-emoji" aria-hidden="true">🕐</span>
                        <div>
                            <strong>Schedule planning</strong>
                            <p>The system assigns estimated travel, activity, meal, rest, and departure times to create your day-by-day schedule.</p>
                        </div>
                    </li>
                </ul>

                <details class="explainer-technical">
                    <summary>View technical details</summary>
                    <div class="explainer-technical__body">
                        <dl class="explainer-technical-list">
                            <div>
                                <dt>Content-Based Recommendation</dt>
                                <dd>Ranks destinations according to the tourist's stated preferences.</dd>
                            </div>
                            <div>
                                <dt>Apriori Association Rules</dt>
                                <dd>Identifies complementary destinations and establishments based on observed co-visitation patterns.</dd>
                            </div>
                            <div>
                                <dt>Haversine Distance + Nearest-Neighbor Heuristic</dt>
                                <dd>Creates the geographic travel sequence.</dd>
                            </div>
                            <div>
                                <dt>
                                    Pretrained ML &mdash; Phi-4-mini-instruct
                                    @if ($provenance['ml_applied'] === true)
                                        <span class="ml-status ml-status--applied">Status: Applied</span>
                                    @elseif ($provenance['ml_applied'] === false)
                                        <span class="ml-status ml-status--fallback">Status: Fallback used</span>
                                    @else
                                        <span class="ml-status ml-status--unknown">Status: Not recorded for this itinerary</span>
                                    @endif
                                </dt>
                                <dd>Assists with grouping the already-ranked and already-sequenced destinations across the available itinerary days.</dd>
                            </div>
                            <div>
                                <dt>Schedule Builder</dt>
                                <dd>Converts the resulting itinerary structure into practical arrival, activity, meal, travel, rest, and departure times.</dd>
                            </div>
                        </dl>
                        <p class="explainer-fallback-note">
                            If the pretrained ML model cannot provide a valid result, the system uses its
                            deterministic fallback logic so itinerary generation can still continue.
                        </p>
                    </div>
                </details>
            </div>
        </div>
    </div>
@endunless

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

    /*
     * "How was this itinerary created?" explainer -- same inert/overlay/Escape
     * pattern as partials/header.blade.php's mobile menu, just a centered
     * dialog instead of a slide-in drawer. Guarded on the trigger existing
     * since a package itinerary renders none of this markup at all.
     */
    (function () {
        var openBtn = document.getElementById('itineraryExplainerOpen');
        var modal = document.getElementById('itineraryExplainerModal');
        var overlay = document.getElementById('itineraryExplainerOverlay');
        var closeBtn = document.getElementById('itineraryExplainerClose');
        if (!openBtn || !modal || !overlay || !closeBtn) return;

        var open = function () {
            modal.classList.add('open');
            overlay.classList.add('open');
            document.body.classList.add('info-modal-open');
            modal.removeAttribute('inert');
            openBtn.setAttribute('aria-expanded', 'true');
            closeBtn.focus();
        };

        var close = function () {
            modal.classList.remove('open');
            overlay.classList.remove('open');
            document.body.classList.remove('info-modal-open');
            modal.setAttribute('inert', '');
            openBtn.setAttribute('aria-expanded', 'false');
            openBtn.focus();
        };

        openBtn.addEventListener('click', open);
        closeBtn.addEventListener('click', close);
        overlay.addEventListener('click', close);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('open')) close();
        });
    })();

    /*
     * "Starting Point" navigation widget for an adopted package (see the
     * Starting Point panel above). Entirely client-side: nothing here reads
     * from or writes to the package, the itinerary, or any server route --
     * it only ever builds a Google Maps directions link, so there is no way
     * for this to reorder, reschedule, or resend the package through the
     * recommendation/ML pipeline. Guarded on the button existing since it
     * only renders when the package has real coordinates.
     */
    (function () {
        var openLink = document.getElementById('package-nav-open');
        var locateBtn = document.getElementById('package-nav-locate');
        var originInput = document.getElementById('package-nav-origin');
        if (!openLink || !locateBtn || !originInput) return;

        var destination = @json($packageCoords ? $packageCoords['lat'].','.$packageCoords['lng'] : null);
        var originCoords = null;

        function mapsUrl() {
            var params = 'api=1&destination=' + encodeURIComponent(destination);
            var typed = originInput.value.trim();
            if (originCoords) {
                params += '&origin=' + encodeURIComponent(originCoords);
            } else if (typed) {
                params += '&origin=' + encodeURIComponent(typed);
            }
            // No origin at all is intentional, not an oversight: Google Maps
            // falls back to the visitor's current location on its own end.
            return 'https://www.google.com/maps/dir/?' + params;
        }

        function refreshHref() {
            openLink.href = mapsUrl();
        }

        originInput.addEventListener('input', function () {
            originCoords = null;
            refreshHref();
        });

        locateBtn.addEventListener('click', function () {
            if (!navigator.geolocation) return;
            locateBtn.disabled = true;
            locateBtn.textContent = 'Locating…';
            navigator.geolocation.getCurrentPosition(
                function (pos) {
                    originCoords = pos.coords.latitude + ',' + pos.coords.longitude;
                    originInput.value = 'Your current location';
                    locateBtn.disabled = false;
                    locateBtn.textContent = 'Use my current location';
                    refreshHref();
                },
                function () {
                    locateBtn.disabled = false;
                    locateBtn.textContent = 'Use my current location';
                },
                { timeout: 8000, maximumAge: 300000 }
            );
        });

        refreshHref();
    })();
</script>
@endsection
