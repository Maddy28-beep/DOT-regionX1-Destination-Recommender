@extends('layouts.app')

@section('title', 'Travel Preferences — ExploreDVO')

@section('content')
@php
    $selectedActivities = $preference->relationLoaded('activities') ? $preference->activities->pluck('activity')->all() : [];
    $selectedAmenities = $preference->relationLoaded('amenities') ? $preference->amenities->pluck('amenity')->all() : [];
    $selectedConditions = $healthProfile ? $healthProfile->conditions->pluck('condition')->all() : [];
    $healthIcons = ['mobility' => 'accessibility', 'vision' => 'eye', 'hearing' => 'ear', 'cardiac' => 'heart', 'respiratory' => 'wind', 'pregnancy' => 'user', 'senior' => 'user', 'child' => 'family'];

    $activityOptions = ['Beach & Island', 'Nature & Adventure', 'Cultural Heritage', 'Wildlife', 'Food Tourism', 'Shopping & Souvenirs', 'Hiking & Trekking', 'Relaxation & Wellness'];
    // The same list every listing ticks its amenities from, so what a traveller asks for is what a place can carry.
    $amenityOptions = \App\Support\Amenities::plannerLabels();
    $travelTypes = ['Solo' => 'Solo', 'Couple' => 'Couple', 'Family' => 'Family', 'Friends' => 'Friends / Group', 'Business' => 'Business'];
    $travelPurposes = ['Leisure', 'Business', 'Visiting Friends/Family', 'Educational', 'Medical', 'Religious/Pilgrimage', 'Other'];
    $visitorTypes = ['First-time Visitor', 'Returning Visitor', 'Regular / Local'];
@endphp

<link rel="stylesheet" href="{{ asset('css/plan-wizard.css') }}?v={{ filemtime(public_path('css/plan-wizard.css')) }}">
<link rel="stylesheet" href="{{ asset('css/plan-comfort.css') }}?v={{ filemtime(public_path('css/plan-comfort.css')) }}">
<link rel="stylesheet" href="{{ asset('css/plan-compact.css') }}?v={{ filemtime(public_path('css/plan-compact.css')) }}">
<link rel="stylesheet" href="{{ asset('css/plan-trip-layout.css') }}?v={{ filemtime(public_path('css/plan-trip-layout.css')) }}">
<link rel="stylesheet" href="{{ asset('css/plan-headings.css') }}?v={{ filemtime(public_path('css/plan-headings.css')) }}">
<link rel="stylesheet" href="{{ asset('css/plan-sidebar.css') }}?v={{ filemtime(public_path('css/plan-sidebar.css')) }}">
@if (\App\Support\Features::tripLocation())
<script>document.documentElement.classList.add('plan-origin-gating');</script>
@endif
<div class="plan-shell plan-wizard" id="planWizard" data-draft-owner="{{ auth()->id() ?? 'guest' }}" @if (\App\Support\Features::tripLocation()) data-origin-pending @endif>
    <div class="section-tight">
        <div class="container plan-frame">
            {{-- The intro sits in a column beside the form (not a banner above it), so the form starts near the top of the screen. --}}
            <aside class="plan-side" aria-label="Trip planning guidance from Davo">
                <span class="plan-side__kicker">Your travel buddy</span>
                <div class="plan-side__davo plan-side__davo-media">
                    <video data-davo-guide-video muted playsinline preload="none" poster="{{ asset('images/davo-wave-green.webp') }}" width="568" height="760" aria-label="Davo, your animated eagle travel buddy">
                        <source src="{{ asset('video/davo-planner-guide.mp4') }}" type="video/mp4">
                        <img src="{{ asset('images/davo-wave-green.webp') }}" alt="Davo waving hello" width="374" height="504">
                    </video>
                </div>
                <div class="plan-side__bubble">
                    <h1 class="plan-side__title">
                        <span class="plan-side__guidance--trip">Hi, I’m Davo!</span>
                        <span class="plan-side__guidance--interests">What do you love?</span>
                        <span class="plan-side__guidance--stay">Let’s find your style.</span>
                        <span class="plan-side__guidance--transport">Ready to explore?</span>
                    </h1>
                    <p class="plan-side__text plan-side__guidance--trip">Let’s start with who’s coming, when you’ll visit, and where your adventure begins.</p>
                    <p class="plan-side__text plan-side__guidance--interests">Choose at least one interest so I know what you enjoy. Amenities are optional—pick the comforts that matter to you.</p>
                    <p class="plan-side__text plan-side__guidance--stay">Tell me your spending style and where you’d like to stay. If you choose a resort, you can pick its setting too.</p>
                    <p class="plan-side__text plan-side__guidance--transport">Choose how far you’d like to explore and how you’ll travel. You can also tell me about any access needs before creating your plan.</p>
                </div>
                <ol class="plan-side__checklist plan-side__checklist--trip" aria-label="Your trip basics">
                    <li>Choose your companions</li>
                    <li>Set your trip dates</li>
                    <li>Pick a starting point</li>
                </ol>
                <ol class="plan-side__checklist plan-side__checklist--interests" aria-label="Choosing your interests">
                    <li>Choose your travel purpose</li>
                    <li>Pick your interests</li>
                    <li>Add preferred amenities, if any</li>
                </ol>
                <ol class="plan-side__checklist plan-side__checklist--stay" aria-label="Choosing your stay and budget">
                    <li>Choose your spending style</li>
                    <li>Pick a type of stay</li>
                    <li>For resorts, explore the settings</li>
                </ol>
                <ol class="plan-side__checklist plan-side__checklist--transport" aria-label="Choosing how to get around">
                    <li>Choose your exploration range</li>
                    <li>Pick how you’ll travel</li>
                    <li>Review any optional access needs</li>
                </ol>
                <a href="{{ route('saved.index') }}" class="plan-side__saved"><x-icon name="heart" /> Saved Places</a>
            </aside>

            <nav class="plan-wizard-steps" aria-label="Trip planning steps" hidden>
                <button type="button" data-plan-step="0" aria-controls="plan-step-0"><b class="plan-step-num" aria-hidden="true">1</b> Your trip</button>
                <button type="button" data-plan-step="1" aria-controls="plan-step-1"><b class="plan-step-num" aria-hidden="true">2</b> Your interests</button>
                <button type="button" data-plan-step="2" aria-controls="plan-step-2"><b class="plan-step-num" aria-hidden="true">3</b> Stay &amp; budget</button>
                <button type="button" data-plan-step="3" aria-controls="plan-step-3"><b class="plan-step-num" aria-hidden="true">4</b> Getting around</button>
            </nav>

            <div class="plan-main">
            @if ($errors->any())
                <div class="alert alert-error">
                    <ul style="margin:0; padding-left:18px;">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <header class="plan-resume-intro">
                <p class="plan-side__kicker">Your Davao adventure</p>
                <h1>Welcome back, explorer.</h1>
                <p>Your next adventure is right where you left it.</p>
            </header>
            <section class="plan-resume" id="planResume" aria-labelledby="planResumeTitle" hidden>
                <div class="plan-resume-art">
                    <video class="plan-resume-davo" data-davo-resume-video muted playsinline preload="auto" poster="{{ asset('images/davo-resume-video-poster.webp') }}" width="568" height="746" aria-label="Davo welcoming you back">
                        <source src="{{ asset('video/davo-resume-guide.mp4') }}?v={{ (is_file(public_path('video/davo-resume-guide.mp4')) ? filemtime(public_path('video/davo-resume-guide.mp4')) : 0) }}" type="video/mp4">
                        <img src="{{ asset('images/davo-resume-video-poster.webp') }}" alt="Davo welcoming you back" width="230" height="300">
                    </video>
                </div>
                <div class="plan-resume-copy">
                    <span class="plan-resume-eyebrow"><x-icon name="check" /> Trip draft saved</span>
                    <h2 id="planResumeTitle">Ready to pick up where you left off?</h2>
                    <p>Your trip basics and interests are saved on this device.</p>
                    <p class="plan-resume-picks-label">Your saved picks</p>
                    <div class="plan-resume-summary" id="planResumeSummary">
                        <span hidden><x-icon name="calendar" /><span></span></span>
                        <span hidden><x-icon name="mountain" /><span></span></span>
                        <span hidden><x-icon name="tag" /><span></span></span>
                    </div>
                    <div class="plan-resume-actions">
                        <button type="button" id="planResumeContinue">Continue planning <span aria-hidden="true">&rarr;</span></button>
                        <button type="button" id="planResumeDiscard">Start over</button>
                    </div>
                    <p class="plan-resume-note"><x-icon name="shield-check" class="plan-resume-note__icon" /><span>You can review your answers before creating your itinerary. <br>Dates, location, and health details aren’t saved in this draft.</span></p>
                </div>
            </section>
            <div class="panel plan-panel">
                <div class="panel-head">
                    <div>
                        <h2>Your travel preferences</h2>
                        <p>Choose what works for you and we'll build your itinerary.</p>
                    </div>
                </div>
                <div class="panel-body">
                    {{-- POST, not a spoofed PUT: unlike the dashboard route this
                         was adapted from, submitting here creates a preference
                         and generates an itinerary rather than updating one
                         addressable resource. --}}
                    <form method="POST" action="{{ route('plan.update') }}" id="planPreferencesForm">
                        @csrf

                        @include('partials.plan-trip-basics')

                        @include('partials.plan-stay-budget')
                        @include('partials.plan-getting-around')

                        <div class="plan-section">
                            <h3 class="plan-section__title"><x-icon name="star" /> Interests &amp; Amenities</h3>

                            @include('partials.plan-interests')

                            <div class="field pc-other-needs" style="margin-top:18px;">
                                <label for="accessibility_notes">Anything else we should know? (optional)</label>
                                <textarea id="accessibility_notes" name="accessibility_notes" rows="3" placeholder="e.g. traveling with young children, prefer slower-paced itineraries">{{ old('accessibility_notes', $preference->accessibility_notes) }}</textarea>
                            </div>
                        </div>

                        {{--
                            Starting point. Distances and the order of stops are
                            measured from here, so a real position produces a
                            genuinely sequenced day instead of one planned from
                            the middle of Davao City.

                            Three ways in, all optional: type an address and pick
                            from the suggestions, type it out in full and let the
                            server geocode it, or share the device's location.
                            Coordinates are rounded to ~110 m before storage (see
                            TripPlannerController::applyOrigin).
                        --}}
                        @php
                            // The visitor explicitly chooses where to meet; home origin never supplies a pick-up point.
                            $pickupV2 = \App\Support\Features::tripLocation();
                            $pickupDefault = false;
                            $pickupLabel = $pickupDefault ? 'Davao International Airport' : old('origin_label', $preference->origin_label);
                            $pickupLat = $pickupDefault ? '7.13' : old('origin_lat', $preference->origin_lat);
                            $pickupLng = $pickupDefault ? '125.645' : old('origin_lng', $preference->origin_lng);
                        @endphp
                        <div class="plan-section pc-block pc-trip-start">
                            @if ($pickupV2)
                                <header class="pc-block__head"><h3>Pick-up location</h3><p>Your hotel, arrival point or address in Davao Region.</p></header>
                                <p class="plan-davo-tip"><x-icon name="map-pin" /> Choose where you will meet your rental provider or tour operator. Confirm collection and availability directly with them; this itinerary does not book transport.</p>
                            @else
                                <header class="pc-block__head"><h3>Where will you start each day?</h3><p>Optional. We order your stops from the nearest to the furthest.</p></header>
                            @endif

                            @if ($pickupV2)
                                <p class="pc-hint is-warning" role="status" data-pickup-lock-note hidden>Your device is outside Davao Region. Choose your preferred pick-up location inside the region, such as your hotel, airport or port.</p>
                            @endif

                            <input type="hidden" name="origin_lat" id="origin_lat" value="{{ $pickupLat }}">
                            <input type="hidden" name="origin_lng" id="origin_lng" value="{{ $pickupLng }}">

                            <div class="pc-grid">
                                <div class="field pc-field pc-col-8">
                                    <label for="origin_label" data-start-label>Hotel, airport or address</label>
                                    {{-- Combobox pattern: a text input that owns a listbox
                                         of suggestions, so it stays usable by keyboard and
                                         to a screen reader rather than being a div that
                                         happens to respond to clicks. --}}
                                    <div class="address-field">
                                        <x-icon name="map-pin" class="pc-origin__pin" />
                                        <input type="text"
                                               id="origin_label"
                                               name="origin_label"
                                               @required($pickupV2)
                                               value="{{ $pickupLabel }}"
                                               placeholder="Hotel, airport or address"
                                               autocomplete="off"
                                               role="combobox"
                                               aria-expanded="false"
                                               aria-autocomplete="list"
                                               aria-controls="origin-suggestions"
                                               aria-describedby="origin-status">
                                        <button type="button" class="btn btn-outline btn-sm" id="origin-clear" aria-label="Clear pick-up location"
                                                @if (! $pickupLat && ! $pickupLabel) hidden @endif>Clear</button>
                                        <ul id="origin-suggestions" class="address-suggestions" role="listbox" aria-label="Address suggestions" hidden></ul>
                                    </div>
                                </div>

                                <div class="field pc-field pc-col-4 pc-location-actions">
                                    <button type="button" class="btn btn-outline pc-locate" id="origin-btn" title="Use my current location" aria-label="Use my current location" @if (\App\Support\Features::tripLocation()) data-region-xi="{{ \App\Support\Geo\RegionXi::json() }}" @endif><x-icon name="locate" aria-hidden="true" /><span class="sr-only">Use my current location</span></button>

                                {{-- Where most visitors arrive. A tap fills the box and the two hidden coordinates, so the plan never depends on
                                     a lookup finding the place by name (the geocoder does not know all of them). The
                                     coordinates are what it returned for each place, rounded to the same 3 places the
                                     server stores (plan-origin.js). --}}
                                <div class="pc-arrivals" data-arrival-points hidden>
                                    <span class="pc-label" id="arrivalPointsLabel">Quick pick</span>
                                    <div class="pc-quick" role="group" aria-labelledby="arrivalPointsLabel">
                                        <button type="button" title="Davao Airport" aria-label="Davao Airport" data-start-pick="Davao International Airport" data-lat="7.130" data-lng="125.645"><x-icon name="plane" aria-hidden="true" /><span class="sr-only">Davao Airport</span></button>
                                        <button type="button" title="Bus terminal" aria-label="Bus terminal" data-start-pick="Davao City Overland Transport Terminal, Ecoland" data-lat="7.056" data-lng="125.602"><x-icon name="bus" aria-hidden="true" /><span class="sr-only">Bus terminal</span></button>
                                        <button type="button" title="Seaport" aria-label="Seaport" data-start-pick="Port of Sasa" data-lat="7.127" data-lng="125.663"><x-icon name="ship" aria-hidden="true" /><span class="sr-only">Seaport</span></button>
                                        <button type="button" title="City centre" aria-label="City centre" data-start-pick="San Pedro Street, Davao City" data-lat="7.066" data-lng="125.607"><x-icon name="building" aria-hidden="true" /><span class="sr-only">City centre</span></button>
                                    </div>
                                </div>
                                </div>

                                <div class="pc-col-12 pc-start-notes">
                                        <span class="sr-only" id="origin-status" role="status">
                                            @if ($pickupLabel)
                                                Starting from {{ $pickupLabel }}.
                                            @else
                                                @if ($pickupV2)Choose a pick-up location to continue.@else Not set &mdash; we'll plan from Davao City centre.@endif
                                            @endif
                                        </span>
                                    @error('origin_label')<p class="pc-hint is-error" role="alert">{{ $message }}</p>@enderror
                                </div>
                            </div>
                        </div>

                        {{--
                            Health and accessibility (2.2.1.14). These questions
                            live in the planning form, not a separate profile,
                            because that is where they are used: the recommender
                            weighs accessibility when it ranks destinations. They
                            are optional and consent-gated, and clearing the
                            consent box on a later pass deletes what was stored.
                        --}}
                        <div class="plan-section pc-health-section">
                            <h3 class="plan-section__title"><x-icon name="shield-check" /> Health &amp; Accessibility</h3>
                            <div class="field pc-health-options" style="margin-top:0;" role="group" aria-labelledby="healthChoicesLabel">
                                <label id="healthChoicesLabel">Health &amp; accessibility (optional)</label>
                                <div class="privacy-note">
                                    <x-icon name="shield-check" />
                                    <p>
                                        If you tell us, we will favour places that can accommodate you. Kept with this trip
                                        plan only, never linked to your name, and removed the moment you untick the box below.
                                    </p>
                                </div>
                                <div class="chip-checkbox-grid pc-health-grid">
                                    @foreach ($healthOptions as $key => $label)
                                        <label class="field-check">
                                            <input type="checkbox" name="health_conditions[]" value="{{ $key }}" @checked(in_array($key, old('health_conditions', $selectedConditions)))>
                                            <span class="pc-health-icon" aria-hidden="true"><x-icon :name="$healthIcons[$key] ?? 'shield-check'" /></span>
                                            <span class="pc-health-name">{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            <div class="field pc-health-other" style="margin-top:14px;">
                                <label for="health_other">Anything else about access needs? (optional)</label>
                                <input type="text" id="health_other" name="health_other" maxlength="300"
                                       value="{{ old('health_other', $healthProfile->other_text ?? '') }}"
                                       placeholder="e.g. needs step-free access to the shoreline">
                            </div>

                            <label class="field-check consent-check" style="margin-top:12px;">
                                <input type="checkbox" name="health_consent" value="1" @checked(old('health_consent', (bool) $healthProfile))>
                                <span>Use this to tailor my itinerary. I can clear it any time by unticking this box.</span>
                            </label>
                        </div>

                        <div class="plan-wizard-actions">
                            <button type="button" class="btn btn-outline" id="planBack" hidden>&larr; Back</button>
                            <span id="planStepStatus" role="status" hidden></span>
                            <button type="button" class="btn plan-next" id="planNext" hidden>Next: your interests &rarr;</button>
                            <button type="submit" class="btn plan-next plan-submit">Create my itinerary &rarr;</button>
                        </div>
                    </form>
                </div>
            </div>
            <section id="planLoading" class="plan-loading" role="status" aria-live="polite" aria-labelledby="planLoadingTitle" hidden tabindex="-1">
                <div class="plan-loading-art">
                    <video class="plan-loading-davo" data-davo-building-video muted playsinline preload="auto" poster="{{ asset('images/davo-planning.webp') }}" width="568" height="628" aria-label="Davo preparing your itinerary">
                        <source src="{{ asset('video/davo-itinerary-building.mp4') }}?v={{ (is_file(public_path('video/davo-itinerary-building.mp4')) ? filemtime(public_path('video/davo-itinerary-building.mp4')) : 0) }}" type="video/mp4">
                        <img src="{{ asset('images/davo-planning.webp') }}" alt="Davo preparing your itinerary" width="420" height="504">
                    </video>
                </div>
                <div class="plan-loading-copy">
                <div class="plan-loading-kicker">Your Davao adventure</div>
                <h2 id="planLoadingTitle">I’m putting your trip together.</h2>
                <p class="plan-loading-intro"><span class="plan-loading-pending">Sit tight while I turn your preferences into a day-by-day plan.</span><span class="plan-loading-ready">Your day-by-day adventure is ready to explore.</span></p>
                <h3 class="plan-loading-list-title">Your plan includes</h3>
                <ul class="plan-loading-workflow" aria-label="Your plan includes">
                    <li><x-icon name="map-pin" aria-hidden="true" /> Places matched to your interests</li>
                    <li><x-icon name="route" aria-hidden="true" /> A practical order for your stops</li>
                    <li><x-icon name="clock" aria-hidden="true" /> Opening hours considered</li>
                    <li><x-icon name="car" aria-hidden="true" /> Travel-time estimates between stops</li>
                </ul>
                </div>
                <div class="plan-loading-footer">
                    <div class="plan-loading-track" aria-hidden="true"><span></span></div>
                    <p class="plan-loading-status"><span class="plan-loading-spinner" aria-hidden="true"></span><span class="plan-loading-check" aria-hidden="true"><x-icon name="check" /></span><strong id="planLoadingStatus">Building your itinerary…</strong></p>
                    <p class="plan-loading-note"><span class="plan-loading-pending">Please keep this page open while your plan is being created.</span><span class="plan-loading-ready">Taking you to your itinerary now.</span></p>
                </div>
            </section>
            </div>{{-- /.plan-main --}}
        </div>
    </div>
</div>

<script>
    /*
     * Starting-point picker.
     *
     * Three ways to answer, all optional -- a visitor who ignores this field
     * still gets a plan, sequenced from the regional default. Nothing here can
     * block the form.
     *
     *   1. Type, and choose a suggestion (fills the coordinates exactly).
     *   2. Type an address out and submit; the server geocodes it once.
     *   3. Press the button and share the device's position.
     *
     * Suggestions come from our own endpoint rather than from a geocoder
     * directly -- see AddressSuggestionService for why that matters.
     */
    (function () {
        var input = document.getElementById('origin_label');
        var list = document.getElementById('origin-suggestions');
        var btn = document.getElementById('origin-btn');
        var clear = document.getElementById('origin-clear');
        var status = document.getElementById('origin-status');
        var lat = document.getElementById('origin_lat');
        var lng = document.getElementById('origin_lng');

        if (!input || !list) return;

        var NOT_SET = @json(\App\Support\Features::tripLocation() ? 'Choose a pick-up location to continue.' : "Not set — we'll plan from Davao City centre.");
        var SUGGEST_URL = @json(route('plan.address-suggest'));
        var results = [];
        var active = -1;
        var timer = null;
        var lastQuery = '';

        function say(message) { status.textContent = message; }

        function close() {
            list.hidden = true;
            list.innerHTML = '';
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            active = -1;
        }

        function choose(index) {
            var place = results[index];
            if (!place) return;

            input.value = place.label;
            // Rounded here as well as on the server, so an exact position is
            // never even put into the form.
            lat.value = place.lat.toFixed(3);
            lng.value = place.lng.toFixed(3);
            clear.hidden = false;
            say('Starting from ' + place.label + '.');
            close();
        }

        function highlight(index) {
            var items = list.querySelectorAll('[role="option"]');
            items.forEach(function (el, i) {
                var on = i === index;
                el.classList.toggle('is-active', on);
                el.setAttribute('aria-selected', on ? 'true' : 'false');
                if (on) {
                    input.setAttribute('aria-activedescendant', el.id);
                    el.scrollIntoView({ block: 'nearest' });
                }
            });
            active = index;
        }

        function render() {
            if (!results.length) { close(); return; }

            list.innerHTML = '';
            results.forEach(function (place, i) {
                var li = document.createElement('li');
                li.id = 'origin-suggestion-' + i;
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', 'false');
                li.textContent = place.label;
                // mousedown, not click: blur fires first on a click and would
                // close the list before the selection registered.
                li.addEventListener('mousedown', function (e) { e.preventDefault(); choose(i); });
                list.appendChild(li);
            });

            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            active = -1;
        }

        function search(query) {
            if (query.length < 3) { close(); return; }
            if (query === lastQuery) return;
            lastQuery = query;

            fetch(SUGGEST_URL + '?q=' + encodeURIComponent(query), {
                headers: { 'Accept': 'application/json' }
            })
                .then(function (res) { return res.ok ? res.json() : { results: [] }; })
                .then(function (data) {
                    results = data.results || [];
                    render();
                    if (!results.length) {
                        say('No matches — you can type the full address instead.');
                    }
                })
                .catch(function () {
                    // A geocoder being unreachable must not stop anyone
                    // planning: the typed text is still submitted, and resolved
                    // server-side.
                    close();
                });
        }

        input.addEventListener('input', function () {
            // Typing invalidates any previously chosen coordinates, because the
            // text no longer describes the place that was picked.
            lat.value = '';
            lng.value = '';
            clear.hidden = input.value === '';
            say(input.value ? 'Pick a suggestion, or just submit the address as typed.' : NOT_SET);

            clearTimeout(timer);
            var query = input.value.trim();
            timer = setTimeout(function () { search(query); }, 300);
        });

        input.addEventListener('keydown', function (e) {
            if (list.hidden) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                highlight((active + 1) % results.length);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                highlight(active <= 0 ? results.length - 1 : active - 1);
            } else if (e.key === 'Enter' && active >= 0) {
                // Enter is only swallowed while a suggestion is highlighted, so
                // it still submits the form the rest of the time.
                e.preventDefault();
                choose(active);
            } else if (e.key === 'Escape') {
                close();
            }
        });

        input.addEventListener('blur', function () { setTimeout(close, 120); });

        clear.addEventListener('click', function () {
            input.value = '';
            lat.value = '';
            lng.value = '';
            clear.hidden = true;
            close();
            say(NOT_SET);
        });

        if (!navigator.geolocation) {
            btn.disabled = true;
            return;
        }

        btn.addEventListener('click', function () {
            btn.disabled = true;
            say('Getting your location…');

            navigator.geolocation.getCurrentPosition(
                function (pos) {
                    var outsideNote = document.querySelector('[data-pickup-lock-note]');
                    // trip_location_v2: a position outside Davao Region is not used as the start point (the server checks too).
                    if (btn.dataset.regionXi) {
                        var poly = JSON.parse(btn.dataset.regionXi), inside = false, x = pos.coords.longitude, y = pos.coords.latitude;
                        for (var i = 0, j = poly.length - 1; i < poly.length; j = i++) {
                            if (((poly[i][1] > y) !== (poly[j][1] > y)) && (x < (poly[j][0] - poly[i][0]) * (y - poly[i][1]) / (poly[j][1] - poly[i][1]) + poly[i][0])) inside = !inside;
                        }
                        if (!inside) {
                            if (outsideNote) outsideNote.hidden = false;
                            btn.disabled = false;
                            say('Your location looks to be outside Davao Region, so we cannot start the trip from there. Choose where you will stay or arrive in the region instead.');
                            return;
                        }
                    }
                    if (outsideNote) outsideNote.hidden = true;
                    lat.value = pos.coords.latitude.toFixed(3);
                    lng.value = pos.coords.longitude.toFixed(3);
                    input.value = 'My current location';
                    btn.disabled = false;
                    clear.hidden = false;
                    close();
                    say('Using your current location.');
                },
                function (err) {
                    btn.disabled = false;
                    say(err && err.code === 1
                        ? 'Location permission was declined. Choose your pick-up address from the suggestions or arrival points.'
                        : "Couldn't get your location — type an address instead.");
                },
                { timeout: 8000, maximumAge: 300000 }
            );
        });
    })();
</script>

<script>window.planWizardErrors = {{ Illuminate\Support\Js::from($errors->keys()) }};</script>
<script src="{{ asset('js/plan-basics.js') }}?v={{ filemtime(public_path('js/plan-basics.js')) }}" defer></script>
<script src="{{ asset('js/plan-origin.js') }}?v={{ filemtime(public_path('js/plan-origin.js')) }}" defer></script>
<script src="{{ asset('js/plan-departure.js') }}?v={{ filemtime(public_path('js/plan-departure.js')) }}" defer></script>
<script src="{{ asset('js/plan-origin-select.js') }}?v={{ filemtime(public_path('js/plan-origin-select.js')) }}" defer></script>
<script src="{{ asset('js/plan-trip-window.js') }}?v={{ filemtime(public_path('js/plan-trip-window.js')) }}" defer></script>
<script src="{{ asset('js/plan-comfort.js') }}?v={{ filemtime(public_path('js/plan-comfort.js')) }}" defer></script>
<script src="{{ asset('js/plan-wizard.js') }}?v={{ filemtime(public_path('js/plan-wizard.js')) }}" defer></script>
{{-- After the wizard script: it builds the step panels this one folds parts of. --}}
<script src="{{ asset('js/plan-collapse.js') }}?v={{ filemtime(public_path('js/plan-collapse.js')) }}" defer></script>
<script src="{{ asset('js/plan-interest-summary.js') }}?v={{ filemtime(public_path('js/plan-interest-summary.js')) }}" defer></script>
<script src="{{ asset('js/davo-planner-video.js') }}?v={{ filemtime(public_path('js/davo-planner-video.js')) }}" defer></script>

@endsection
