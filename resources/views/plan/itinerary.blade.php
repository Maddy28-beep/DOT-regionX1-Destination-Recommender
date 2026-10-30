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

    // How the traveller said they get around, for the strip, the pick-up rows and the long-day note.
    $transport = $preference?->transport_pref;
    $byOperator = ! $itinerary->package && \App\Services\Recommendation\TransportMode::isTourOperator($transport);
    $fmtMinutes = fn (int $m) => $m >= 60 ? intdiv($m, 60).' h'.($m % 60 ? ' '.($m % 60).' min' : '') : $m.' min';
    $longDayMinutes = 240;

    // Each day's stops and distance, for its tab and the map beside it.
    $dayMeta = $itemsByDay->map(fn ($items) => [
        'stops' => $items->where('kind', 'activity')->count(),
        'km' => round((float) $items->where('kind', 'travel')->sum('distance_km'), 1),
    ])->all();

    $rangeTierLabels = ['near' => 'Close Proximity', 'moderate' => 'Commutable', 'far' => 'Remote'];

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
            {{-- One compact banner: the welcome, what the plan is, and what to do with it. Davo is a picture, not a speaker:
                 nothing here claims more than "your plan is ready". --}}
            @php
                $pickedInterests = $itinerary->package ? collect() : ($preference?->activities?->pluck('activity')->filter()->values() ?? collect());
                $startingFrom = $preference?->startLabel() ?? 'Davao City centre';
            @endphp
            <section class="itin-banner" aria-label="Your itinerary is ready">
                <img class="itin-banner__davo" src="{{ asset('images/davo-adventure-ready.webp') }}" alt="Davo celebrating with a travel map" width="450" height="478">

                <div class="itin-banner__copy">
                    <h1 class="itin-banner__title">Your adventure is ready!</h1>
                    @if ($itinerary->title || $itinerary->package)
                        <p class="itin-banner__name">{{ $itinerary->title ?: $itinerary->package->name }}</p>
                    @endif
                    <p class="itin-banner__sub">
                        {{ $itinerary->total_days }} {{ \Illuminate\Support\Str::plural('day', $itinerary->total_days) }}
                        @if ($itinerary->package)
                            &middot; from the <a href="{{ route('packages.show', $itinerary->package) }}">{{ $itinerary->package->name }}</a> package
                        @else
                            @if (filled($preference?->travel_type))&middot; {{ $preference->travel_type }}@endif
                            @if ($pickedInterests->isNotEmpty())
                                &middot; {{ $pickedInterests->take(2)->join(', ') }}@if ($pickedInterests->count() > 2) +{{ $pickedInterests->count() - 2 }} more @endif
                            @endif
                            @if (filled($preference?->budget))&middot; {{ $preference->budget }}@endif
                            &middot; starting from {{ $startingFrom }}@if ($preference?->arrival_time), arriving {{ \Illuminate\Support\Carbon::parse($preference->arrival_time)->format('g:i A') }}@endif
                        @endif
                    </p>
                    <p class="itin-banner__meta">Generated {{ $itinerary->generated_at->timezone('Asia/Manila')->format('F j, Y g:i A') }}</p>
                    @if (data_get($itinerary->day_themes, 'summary.regrouped'))
                        <span class="plan-badge plan-badge--ai" title="A pretrained embedding model grouped similar places into the same day, without adding more than a quarter to the travelling.">&#10024; Days grouped by theme</span>
                    @endif
                </div>

                <div class="itin-banner__actions">
                    <div class="itin-banner__buttons">
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
                            <a href="{{ route('account.itineraries.show', $itinerary) }}" class="btn btn-primary">Saved to My Itineraries &check;</a>
                        @else
                            <form method="POST" action="{{ route('plan.itinerary.save') }}">
                                @csrf
                                <button type="submit" class="btn btn-primary"><x-icon name="heart" /> Save Itinerary</button>
                            </form>
                        @endif
                    </div>
                    {{-- Set expectations honestly: no account is needed to plan or view a trip, by design -- this is what
                         happens if you do not save it. --}}
                    @unless ($itinerary->tourist_account_id)
                        <p class="itin-banner__unsaved" role="status">Not saved yet &mdash; this plan disappears when you close the tab.</p>
                    @endunless
                </div>
            </section>

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

            @if ($itemsByDay->isNotEmpty())
                <div class="itin-bar">
                    <nav class="itin-tabs" aria-label="Jump to a day" data-day-tabs>
                        @foreach ($itemsByDay as $day => $items)
                            <a href="#itinerary-day-{{ $day }}" data-day-tab="{{ $day }}">
                                <strong>Day {{ $day }}</strong>
                                <small>{{ $dayMeta[$day]['stops'] }} {{ \Illuminate\Support\Str::plural('stop', $dayMeta[$day]['stops']) }}@if ($dayMeta[$day]['km'] >= 1) &middot; {{ round($dayMeta[$day]['km']) }} km @endif</small>
                            </a>
                        @endforeach
                    </nav>
                    @unless ($itinerary->package)
                        <div class="itin-bar__links">
                            <button type="button" class="itinerary-explainer-trigger" id="itineraryExplainerOpen"
                                    aria-haspopup="dialog" aria-controls="itineraryExplainerModal" aria-expanded="false">
                                <x-icon name="info" />
                                How was this made?
                            </button>
                            <a href="#itinerary-recommendations" class="itin-more"><x-icon name="compass" /> More ideas</a>
                            <button type="button" class="itin-more" data-print><x-icon name="printer" /> Print / PDF</button>
                            <a href="{{ route('plan.itinerary.calendar') }}" class="itin-more"
                               @unless (filled($preference?->start_date)) title="Add an arrival date in your preferences first; a calendar needs the day your trip starts." @endunless>
                                <x-icon name="calendar" /> Add to calendar
                            </a>
                        </div>
                    @endunless
                </div>
            @endif

            <div class="itin-layout">
                <div class="itin-main">
                    <h2 class="sr-only">Day-by-Day Travel Plan</h2>

                    @if ($itemsByDay->isEmpty())
                        <div class="empty-panel">
                            <div class="icon"><x-icon name="compass" /></div>
                            <h3>No itinerary items yet</h3>
                            <p>Try regenerating your itinerary above.</p>
                        </div>
                    @else
                        @php
                            // A day between the first and the last with no sightseeing at all: the catalogue ran out of
                            // places that fit the picks (a long trip, a narrow range). Say so instead of showing bare days.
                            $lastDay = $itemsByDay->keys()->max();
                            $freeDays = $itemsByDay->filter(fn ($items, $d) => $d !== 1 && $d !== $lastDay && $items->where('kind', 'activity')->isEmpty())->count();
                        @endphp
                        @if ($freeDays > 0 && ! $itinerary->package)
                            <div class="itin-notice" role="note" style="margin:0 0 16px; padding:12px 16px; border-radius:12px; background:#fff7e6; border:1px solid #f0c36d;">
                                <strong>{{ $freeDays }} {{ \Illuminate\Support\Str::plural('day', $freeDays) }} of this trip {{ $freeDays === 1 ? 'has' : 'have' }} no sightseeing planned.</strong>
                                We ran out of accredited places that fit your interests and range. Try adding interests, choosing a farther range, or planning fewer days.
                            </div>
                        @endif

                        {{-- A trip of two or more days with no overnight stay at all: no accommodation was DOT-accredited and open for these
                             dates, so none could be offered. Say so, instead of leaving "your accommodation" to look like a choice we made. --}}
                        @if ($itemsByDay->count() > 1 && ! $itinerary->package && ! $itemsByDay->contains(fn ($rows) => $rows->contains('kind', 'overnight')))
                            <div class="itin-notice" role="note" style="margin:0 0 16px; padding:12px 16px; border-radius:12px; background:#fff7e6; border:1px solid #f0c36d;">
                                <strong>No DOT-accredited accommodation is available for your trip.</strong>
                                We could not find an accredited place to stay that is open for your dates, so no stay is planned. Each day starts and ends
                                wherever you are staying. Check the <a href="{{ route('accommodations.index') }}">accommodation list</a> for the latest, or contact DOT Region XI.
                            </div>
                        @endif

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
                                $dayStops = $dayMeta[$day]['stops'];
                                $dayKm = $dayMeta[$day]['km'];
                                // Where the day is: its theme when there is one, otherwise the area most of its stops are in.
                                $dayArea = $items->where('kind', 'activity')
                                    ->map(fn ($row) => $row->destination?->region?->name)->filter()->countBy()->sortDesc()->keys()->first();
                                // The night's stay, for "back at your resort by evening".
                                $night = $items->firstWhere('kind', 'overnight');
                                $stayWord = $night?->accommodation
                                    ? (in_array(strtolower((string) $night->accommodation->type), ['resort', 'hotel', 'homestay', 'hostel', 'inn'], true) ? strtolower($night->accommodation->type) : 'stay')
                                    : null;
                                // When the day's first journey leaves: for a tour operator, the planned pick-up.
                                $pickupAt = $items->firstWhere('kind', 'travel')?->starts_at;
                                $dayDate = $preference?->start_date
                                    ? \Illuminate\Support\Carbon::parse($preference->start_date)->addDays($day - 1)->format('l, F j')
                                    : null;
                            @endphp

                            <section class="itin-day" id="itinerary-day-{{ $day }}" data-day-panel="{{ $day }}" aria-labelledby="itinerary-day-title-{{ $day }}">
                                <header class="itin-day__head">
                                    <div>
                                        <h3 id="itinerary-day-title-{{ $day }}">Day {{ $day }}@if ($theme && $theme['label']) <span>&middot; {{ $theme['label'] }}</span>@elseif ($dayArea) <span>&middot; {{ $dayArea }}</span>@endif</h3>
                                        <p class="itin-day__tag">
                                            @if ($dayDate){{ $dayDate }} &middot; @endif
                                            @if ($dayStops === 0 && $day !== 1 && $day !== $lastDay)Free day, no sightseeing planned
                                            @else{{ $dayStops }} {{ \Illuminate\Support\Str::plural('stop', $dayStops) }}@endif
                                            @if ($dayKm > 0) &middot; {{ rtrim(rtrim(number_format($dayKm, 1), '0'), '.') }} km {{ ! $itinerary->package && filled($transport) ? \App\Services\Recommendation\TransportMode::phrase($transport) : 'of travel' }} @endif
                                            @if ($stayWord) &middot; back at your {{ $stayWord }} by evening @endif
                                            @if ($theme && $theme['label'] && $theme['similarity'] !== null) &middot; these places are {{ $theme['similarity'] }}% alike @endif
                                        </p>
                                    </div>
                                </header>

                                @php $dayTravelMax = (int) $items->where('kind', 'travel')->sum('travel_max_minutes'); @endphp
                                @if (! $itinerary->package && $dayTravelMax > $longDayMinutes)
                                    <p class="itin-longday" role="note">
                                        <x-icon name="alert-triangle" />
                                        <span>This day has up to {{ $fmtMinutes($dayTravelMax) }} of travel {{ \App\Services\Recommendation\TransportMode::phrase($transport) }}.
                                        You can swap a stop below, or change how you travel in <a href="{{ route('plan.edit') }}">your preferences</a>.</span>
                                    </p>
                                @endif

                                @php
                                    // Dinner at the place you sleep, followed by the night there: one card, "Dinner & overnight stay".
                                    $rows = $items->values();
                                    $stayAfterDinner = [];
                                    foreach ($rows as $i => $row) {
                                        $following = $rows[$i + 1] ?? null;
                                        if ($row->kind === 'meal' && $row->accommodation_id && $following?->kind === 'overnight'
                                            && $following->accommodation_id === $row->accommodation_id && str_starts_with(strtolower($row->title), 'dinner')) {
                                            $stayAfterDinner[$row->id] = $following;
                                        }
                                    }
                                    $mergedNights = collect($stayAfterDinner)->pluck('id')->all();
                                @endphp
                                <div class="tl">
                                    @foreach ($rows as $item)
                                        @continue(in_array($item->id, $mergedNights, true))
                                        @if ($byOperator && $loop->first && $item->kind !== 'baseline')
                                            @include('partials.itinerary-pickup-row', ['at' => $pickupAt])
                                        @endif
                                        @include('partials.itinerary-timeline-row', ['item' => $item, 'swap' => $swap, 'ideas' => ($ideas ?? [])[$item->id] ?? [], 'preference' => $preference, 'stay' => $stayAfterDinner[$item->id] ?? null])
                                        @if ($byOperator && $item->kind === 'baseline')
                                            @include('partials.itinerary-pickup-row', ['at' => $pickupAt])
                                        @endif
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

                @include('partials.itinerary-aside', ['itinerary' => $itinerary, 'preference' => $preference, 'routeStops' => $routeStops, 'dayMeta' => $dayMeta, 'operators' => $operators ?? collect()])
            </div>

            @unless ($itinerary->package)
            <section class="itinerary-refine" aria-label="Adjust your itinerary">
                <h2>Your itinerary, your way</h2>
                <div class="itinerary-refine-actions">
                    <form method="POST" action="{{ route('plan.regenerate') }}" id="regenerate-form">
                        @csrf
                        <input type="hidden" name="lat" id="regenerate-lat">
                        <input type="hidden" name="lng" id="regenerate-lng">
                        <button type="submit" class="btn btn-outline"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 7v5h-5M20 12a8 8 0 1 0-2 5"/></svg> Regenerate itinerary</button>
                    </form>
                </div>
            </section>
            <div class="panel itinerary-support-panel">
                <div class="panel-head">
                    <div>
                        <h2 id="itinerary-recommendations">Recommended Destinations</h2>
                        <p>Ranked by how well each place fits your picked interests first, then by its recommendation score.</p>
                    </div>
                </div>
                <div class="panel-body">
                    <details class="itinerary-score-details">
                    <summary><span class="itinerary-disclosure-icon" aria-hidden="true"><x-icon name="map-pin" /></span><span>Explore other matches <span class="itinerary-disclosure-hint">View destinations and match scores</span></span><span class="itinerary-disclosure-chevron" aria-hidden="true"></span></summary>
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
            <div class="panel itinerary-support-panel">
                <div class="panel-head">
                    <div>
                        <h2>How your plan was built</h2>
                        <p>We matched destinations to your preferences and arranged nearby stops together.</p>
                        <p class="itinerary-method-note"><x-icon name="info" aria-hidden="true" /> Travel times are planning estimates{{ $preference->transport_pref ? ', worked out for '.\App\Services\Recommendation\TransportMode::label($preference->transport_pref) : '' }}. Check opening hours before visiting.</p>
                        @if ($provenance['range_widened'])
                            <p class="itinerary-method-note">We widened your preferred travel range to find enough stops for your trip. Some destinations may be farther away than requested.</p>
                        @endif
                    </div>
                </div>
                <div class="panel-body">
                    <details class="itinerary-score-details itinerary-method-details">
                    <summary><span class="itinerary-disclosure-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M6 3h8l4 4v14H6zM14 3v5h4M9 12h6M9 16h6"/></svg></span><span>View technical details <span class="itinerary-disclosure-hint">Recommendation methods, match scores, and data used</span></span><span class="itinerary-disclosure-chevron" aria-hidden="true"></span></summary>
                    <ol class="provenance">
                        <li>
                            <span class="provenance-step">Ranking</span>
                            <div>
                                <strong>Content-Based Recommendation</strong> scored
                                {{ $provenance['destinations_ranked'] }} of
                                @if (($provenance['catalogue_sample'] ?? 0) > 0 || ($provenance['catalogue_unverified'] ?? 0) > 0)
                                    {{ $provenance['catalogue_size'] }} destinations ({{ $provenance['catalogue_real'] }} DOT Accredited,
                                    {{ $provenance['catalogue_sample'] }} Sample Data{{ ($provenance['catalogue_unverified'] ?? 0) > 0 ? ', '.$provenance['catalogue_unverified'].' Not Yet Verified' : '' }}) against your
                                @else
                                    {{ $provenance['catalogue_size'] }} accredited destinations against your
                                @endif
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
                                transaction{{ $provenance['transactions'] === 1 ? '' : 's' }}
                                @if ($provenance['transactions_demo'] > 0)
                                    ({{ $provenance['transactions_demo'] }} simulated for demonstration,
                                    {{ $provenance['transactions'] - $provenance['transactions_demo'] }} from real tourist surveys)
                                @endif
                                suggested
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
                    </details>
                </div>
            </div>
            @endif

            <section class="itinerary-feedback" aria-labelledby="itinerary-feedback-title">
                <img src="{{ asset('images/davo-survey-v2.webp') }}" alt="" width="128" height="154" loading="lazy">
                <div>
                    <h2 id="itinerary-feedback-title">How was your Davao adventure?</h2>
                    <p>After your trip, share your experience to help improve future recommendations.</p>
                    <a href="{{ route('exit-survey.create') }}" class="btn btn-primary">Share feedback</a>
                    <p class="itinerary-feedback-note">Optional &middot; About 2 minutes</p>
                </div>
            </section>
            <p class="itinerary-plan-note">This itinerary is a suggested plan. No reservations or payments are made.</p>
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
