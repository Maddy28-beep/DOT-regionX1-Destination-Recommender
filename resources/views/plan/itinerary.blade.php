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

<link rel="stylesheet" href="{{ asset('css/itinerary-page.css') }}?v={{ filemtime(public_path('css/itinerary-page.css')) }}">
<link rel="stylesheet" href="{{ asset('css/itinerary-redesign.css') }}?v={{ filemtime(public_path('css/itinerary-redesign.css')) }}">
<div class="dash-shell itinerary-page itin">
    <div class="dash-body">
        <div class="container">
            {{-- The welcome banner. Davo is a picture, not a speaker: nothing here claims more than "your plan is ready". --}}
            <section class="itin-hero" aria-label="Your itinerary is ready">
                <img class="itin-hero__davo" src="{{ asset('images/davo-adventure-ready.webp') }}" alt="Davo celebrating with a travel map" width="450" height="478">
                <div class="itin-hero__copy">
                    <p class="itin-hero__title">Your adventure is ready!</p>
                    <p class="itin-hero__text">
                        @if ($itinerary->package)
                            Here&rsquo;s the day-by-day schedule for your package. Let&rsquo;s explore!
                        @else
                            Here&rsquo;s your personalized Davao itinerary. Let&rsquo;s explore!
                        @endif
                    </p>
                </div>
                <svg class="itin-hero__scene" viewBox="0 0 420 150" aria-hidden="true" focusable="false">
                    <circle cx="300" cy="46" r="26" fill="#f6d78f" opacity=".75"/>
                    <path d="M0 150V104c34-26 70-30 104-12 30-24 70-34 112-18 34-18 68-14 104 8 36-12 70-6 100 16v52z" fill="#cfe0c4" opacity=".7"/>
                    <path d="M0 150v-26c50-18 96-14 140 6 52-22 104-24 156-4 46-10 88-4 124 14v10z" fill="#aac8a4" opacity=".75"/>
                    <path d="M356 150V92M356 92c-14-2-24 6-30 14M356 92c10-8 22-8 32 0M356 92c-6-12-4-22 4-30M356 92c8-10 20-14 30-12" stroke="#4f8a5b" stroke-width="3" fill="none" stroke-linecap="round" opacity=".85"/>
                    <path d="M394 150V108M394 108c-9-1-16 4-20 10M394 108c7-6 15-6 22 0" stroke="#4f8a5b" stroke-width="3" fill="none" stroke-linecap="round" opacity=".8"/>
                </svg>
            </section>

            <header class="itin-head">
                <div class="itin-head__text">
                    <h1 class="itin-title">{{ $itinerary->title ?: ($itinerary->package ? $itinerary->package->name : 'Your Davao itinerary') }}</h1>
                    <p class="itin-sub">
                        {{ $itinerary->total_days }} {{ \Illuminate\Support\Str::plural('day', $itinerary->total_days) }}
                        @if ($itinerary->package)
                            &middot; from the <a href="{{ route('packages.show', $itinerary->package) }}">{{ $itinerary->package->name }}</a> package
                        @else
                            @php $pickedInterests = $preference->activities->pluck('activity')->filter()->values(); @endphp
                            @if ($pickedInterests->isNotEmpty())
                                &middot; {{ $pickedInterests->take(2)->join(', ') }}@if ($pickedInterests->count() > 2) +{{ $pickedInterests->count() - 2 }} more @endif
                            @endif
                            @if (filled($preference->budget))
                                &middot; {{ $preference->budget }}
                            @endif
                        @endif
                    </p>
                    @if (data_get($itinerary->day_themes, 'summary.regrouped'))
                        <span class="plan-badge plan-badge--ai" title="A pretrained embedding model grouped similar places into the same day, without adding more than a quarter to the travelling.">&#10024; Days grouped by theme</span>
                    @endif
                    <p class="itin-meta">
                        Generated {{ $itinerary->generated_at->format('F j, Y g:i A') }}
                        @unless ($itinerary->package)
                            {{-- Say what the ordering was actually measured from, so a plan sequenced from the regional
                                 default is not mistaken for one sequenced from where the traveller is. --}}
                            &middot; ordered from {{ $preference->origin_label ?: 'Davao City centre' }}
                            @if ($preference->arrival_time)
                                &middot; arriving {{ \Illuminate\Support\Carbon::parse($preference->arrival_time)->format('g:i A') }}
                            @endif
                        @endunless
                    </p>
                </div>

                <div class="itin-actions">
                    {{--
                        A package-adopted itinerary has no real preferences behind it to edit (see
                        PackageController::planWith()) -- the correct way to move on from it is the
                        "Start the trip planner" link in the panel below, which says plainly that it builds a
                        fresh, different plan rather than implying there is something of the tourist's own to refine here.
                    --}}
                    @unless ($itinerary->package)
                        <a href="{{ route('plan.edit') }}" class="btn btn-outline"><x-icon name="filter" /> Edit preferences</a>
                    @endunless
                    @if ($itinerary->tourist_account_id)
                        <a href="{{ route('account.itineraries.show', $itinerary) }}" class="btn btn-outline">Saved to My Itineraries &check;</a>
                    @else
                        <form method="POST" action="{{ route('plan.itinerary.save') }}">
                            @csrf
                            <button type="submit" class="btn btn-outline"><x-icon name="heart" /> Save Itinerary</button>
                        </form>
                    @endif
                </div>
            </header>

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
                 don't create the optional one. --}}
            <div class="session-note" role="status">
                <x-icon name="alert-triangle" />
                <p>
                    This plan lives in your browser session, so it disappears when you close the tab
                    &mdash; unless you save it. <a href="{{ route('saved.index') }}">Heart the places you like</a>
                    and they will still be here when you come back, or
                    <a href="{{ route('account.register') }}">create a free account</a> to keep this whole itinerary.
                </p>
            </div>

            <div class="itin-layout">
                <div class="itin-main">
                    <div class="itin-main__head">
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
                        @unless ($itinerary->package)
                            <button type="button" class="itinerary-explainer-trigger" id="itineraryExplainerOpen"
                                    aria-haspopup="dialog" aria-controls="itineraryExplainerModal" aria-expanded="false">
                                <x-icon name="info" />
                                How was this itinerary created?
                            </button>
                        @endunless
                    </div>

                    @if ($itemsByDay->isEmpty())
                        <div class="empty-panel">
                            <div class="icon"><x-icon name="compass" /></div>
                            <h3>No itinerary items yet</h3>
                            <p>Try regenerating your itinerary above.</p>
                        </div>
                    @else
                        <nav class="itin-tabs" aria-label="Jump to a day" data-day-tabs>
                            @foreach ($itemsByDay as $day => $items)
                                <a href="#itinerary-day-{{ $day }}" data-day-tab="{{ $day }}">Day {{ $day }}</a>
                            @endforeach
                            @unless ($itinerary->package)
                                <a href="#itinerary-recommendations" class="itin-tabs__more">Recommendations</a>
                            @endunless
                        </nav>

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

                                $theme = data_get($itinerary->day_themes, 'days.'.$day);
                                $dayStops = $items->where('kind', 'activity')->count();
                                $dayKm = round((float) $items->where('kind', 'travel')->sum('distance_km'), 1);
                                $dayDate = $preference?->start_date
                                    ? \Illuminate\Support\Carbon::parse($preference->start_date)->addDays($day - 1)->format('l, F j')
                                    : null;
                            @endphp

                            <section class="itin-day" id="itinerary-day-{{ $day }}" data-day-panel="{{ $day }}" aria-labelledby="itinerary-day-title-{{ $day }}">
                                <header class="itin-day__head">
                                    <div>
                                        <h3 id="itinerary-day-title-{{ $day }}">Day {{ $day }}@if ($theme && $theme['label']) <span>&middot; {{ $theme['label'] }}</span>@endif</h3>
                                        <p class="itin-day__tag">
                                            @if ($dayDate){{ $dayDate }} &middot; @endif
                                            {{ $dayStops }} {{ \Illuminate\Support\Str::plural('stop', $dayStops) }}
                                            @if ($dayKm > 0) &middot; about {{ rtrim(rtrim(number_format($dayKm, 1), '0'), '.') }} km of travel @endif
                                            @if ($theme && $theme['label'] && $theme['similarity'] !== null) &middot; these places are {{ $theme['similarity'] }}% alike @endif
                                        </p>
                                    </div>
                                    <a href="#" class="itinerary-back-top">Back to top ↑</a>
                                </header>

                                <div class="tl">
                                    @foreach ($items as $item)
                                        @include('partials.itinerary-timeline-row', ['item' => $item, 'swap' => $swap])
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
                            </section>
                        @endforeach
                    @endif
                </div>

                @include('partials.itinerary-overview', ['itinerary' => $itinerary, 'preference' => $preference, 'routeStops' => $routeStops])
            </div>

            @unless ($itinerary->package)
            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2 id="itinerary-recommendations">Recommended Destinations</h2>
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
                    <details class="itinerary-score-details">
                    <summary>View destinations and match scores</summary>
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
                    </details>
                </div>
            </div>
            @endunless

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
                        @if (data_get($itinerary->day_themes, 'summary'))
                            <li>
                                <span class="provenance-step">Themes</span>
                                <div>
                                    A <strong>pretrained embedding model</strong> compared what each place is about and
                                    @if (data_get($itinerary->day_themes, 'summary.regrouped'))
                                        grouped alike places into the same day (average similarity within a day
                                        {{ data_get($itinerary->day_themes, 'summary.mean_similarity_before') }}%
                                        &rarr; {{ data_get($itinerary->day_themes, 'summary.mean_similarity_after') }}%,
                                        travelling {{ data_get($itinerary->day_themes, 'summary.distance_before_km') }} km
                                        &rarr; {{ data_get($itinerary->day_themes, 'summary.distance_after_km') }} km, never more than 25% extra).
                                    @elseif (data_get($itinerary->day_themes, 'summary.guard'))
                                        tried grouping alike places into the same day, but that would have left a stop out of the plan, so the route order was kept.
                                    @else
                                        found that the route order already put alike places together, so the days were left as they were.
                                    @endif
                                </div>
                            </li>
                        @endif
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
                                <dt>Schedule Builder</dt>
                                <dd>Converts the resulting itinerary structure into practical arrival, activity, meal, travel, rest, and departure times.</dd>
                            </div>
                        </dl>
                    </div>
                </details>
            </div>
        </div>
    </div>
@endunless

<script src="{{ asset('js/itinerary-swap.js') }}?v={{ filemtime(public_path('js/itinerary-swap.js')) }}" defer></script>
<script src="{{ asset('js/itinerary-days.js') }}?v={{ filemtime(public_path('js/itinerary-days.js')) }}" defer></script>
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
