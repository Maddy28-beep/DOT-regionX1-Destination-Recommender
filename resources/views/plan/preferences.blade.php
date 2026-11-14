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
<div class="plan-shell plan-wizard" id="planWizard" data-draft-owner="{{ auth()->id() ?? 'guest' }}">
    <div class="page-head page-head--with-action">
        <div class="container plan-introduction">
            <img class="plan-davo" src="{{ asset('images/davo-wave-green.webp') }}" alt="Davo, your eagle travel buddy, waving hello" width="374" height="504">
            <div class="plan-introduction-copy">
                <span class="poster-kicker">Your Davao adventure starts here</span>
                <h1 class="page-title">Let’s plan your Davao adventure.</h1>
                <p>A few preferences. A trip that feels like you. No sign-up needed.</p>
            </div>
            <a href="{{ route('saved.index') }}" class="btn btn-outline plan-saved-link">
                <x-icon name="heart" /> Saved Places
            </a>
        </div>
    </div>

    <div class="section-tight">
        <div class="container">
            @if ($errors->any())
                <div class="alert alert-error">
                    <ul style="margin:0; padding-left:18px;">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <section class="plan-resume" id="planResume" aria-labelledby="planResumeTitle" hidden>
                <div class="plan-resume-art">
                    <div class="plan-resume-davo" role="img" aria-label="Davo waving hello" style="background-image:url('{{ asset('images/davo-resume-frames.webp') }}')"></div>
                </div>
                <div class="plan-resume-copy">
                    <span class="plan-resume-eyebrow"><x-icon name="check" /> Trip draft saved</span>
                    <h2 id="planResumeTitle">Pick up where you left off</h2>
                    <p>Your trip basics and interests are saved on this device.</p>
                    <div class="plan-resume-summary" id="planResumeSummary">
                        <span hidden><x-icon name="calendar" /><span></span></span>
                        <span hidden><x-icon name="mountain" /><span></span></span>
                        <span hidden><x-icon name="tag" /><span></span></span>
                    </div>
                    <div class="plan-resume-actions">
                        <button type="button" id="planResumeContinue">Continue planning <span aria-hidden="true">&rarr;</span></button>
                        <button type="button" id="planResumeDiscard">Start over</button>
                    </div>
                    <p class="plan-resume-note"><x-icon name="info" class="plan-resume-note__icon" /><span>You can review your answers before creating your itinerary. <br>Dates, location, and health details aren’t saved in this draft.</span></p>
                </div>
            </section>
            <nav class="plan-wizard-steps" aria-label="Trip planning steps" hidden>
                <button type="button" data-plan-step="0" aria-controls="plan-step-0"><b class="plan-step-num" aria-hidden="true">1</b> Your trip</button>
                <button type="button" data-plan-step="1" aria-controls="plan-step-1"><b class="plan-step-num" aria-hidden="true">2</b> Your interests</button>
                <button type="button" data-plan-step="2" aria-controls="plan-step-2"><b class="plan-step-num" aria-hidden="true">3</b> Stay &amp; budget</button>
                <button type="button" data-plan-step="3" aria-controls="plan-step-3"><b class="plan-step-num" aria-hidden="true">4</b> Getting around</button>
            </nav>
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
                            // trip_location_v2: this is the PICK-UP location, where the itinerary physically starts. A trip with no
                            // pick-up yet starts from Davao International Airport (the arrival point for the region), which the
                            // traveller can change to a hotel, a port or any address, or clear to plan from Davao City centre.
                            $pickupV2 = \App\Support\Features::tripLocation();
                            $pickupDefault = $pickupV2 && ! session()->hasOldInput() && blank($preference->origin_label) && $preference->origin_lat === null;
                            $pickupLabel = $pickupDefault ? 'Davao International Airport' : old('origin_label', $preference->origin_label);
                            $pickupLat = $pickupDefault ? '7.13' : old('origin_lat', $preference->origin_lat);
                            $pickupLng = $pickupDefault ? '125.645' : old('origin_lng', $preference->origin_lng);
                        @endphp
                        <div class="plan-section pc-block pc-trip-start">
                            @if ($pickupV2)
                                <header class="pc-block__head"><h3>Pick-up location</h3><p>Where your trip physically starts: your hotel, the airport or port you arrive at, or any address in Davao Region. Free text is fine. Your first stop is planned from here, and your last stop has to get you back here before your trip ends.</p></header>
                            @else
                                <header class="pc-block__head"><h3>Where will you start each day?</h3><p>Optional. We order your stops from the nearest to the furthest.</p></header>
                            @endif

                            @if ($pickupV2)
                                {{-- Shown (by plan-origin-select.js) while "Where are you travelling from?" is outside Davao Region. The pick-up
                                     is then switched off; the server ignores anything posted for it and plans from Davao City centre. --}}
                                <p class="pc-hint is-warning" role="status" data-pickup-lock-note hidden>Your current location is outside Davao Region. Pick-up location selection is not available for your location. If you wish to continue, please indicate your preferred pick-up location.</p>
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
                                               value="{{ $pickupLabel }}"
                                               placeholder="Hotel, airport or address"
                                               autocomplete="off"
                                               role="combobox"
                                               aria-expanded="false"
                                               aria-autocomplete="list"
                                               aria-controls="origin-suggestions"
                                               aria-describedby="origin-status">
                                        <ul id="origin-suggestions" class="address-suggestions" role="listbox" aria-label="Address suggestions" hidden></ul>
                                    </div>
                                </div>

                                <div class="field pc-field pc-col-4">
                                    <span class="pc-label" aria-hidden="true">&nbsp;</span>
                                    <button type="button" class="btn btn-outline pc-locate" id="origin-btn" @if (\App\Support\Features::tripLocation()) data-region-xi="{{ \App\Support\Geo\RegionXi::json() }}" @endif><x-icon name="locate" /> Use my current location</button>
                                </div>

                                {{-- Where most visitors arrive. A tap fills the box and the two hidden coordinates, so the plan never depends on
                                     a lookup finding the place by name (the geocoder does not know all of them). The
                                     coordinates are what it returned for each place, rounded to the same 3 places the
                                     server stores (plan-origin.js). --}}
                                <div class="pc-col-12 pc-arrivals" data-arrival-points hidden>
                                    <span class="pc-label" id="arrivalPointsLabel">Or start from a common arrival point</span>
                                    <div class="pc-quick" role="group" aria-labelledby="arrivalPointsLabel">
                                        <button type="button" data-start-pick="Davao International Airport" data-lat="7.130" data-lng="125.645">Davao Airport</button>
                                        <button type="button" data-start-pick="Davao City Overland Transport Terminal, Ecoland" data-lat="7.056" data-lng="125.602">Bus terminal</button>
                                        <button type="button" data-start-pick="Port of Sasa" data-lat="7.127" data-lng="125.663">Seaport</button>
                                        <button type="button" data-start-pick="San Pedro Street, Davao City" data-lat="7.066" data-lng="125.607">City centre</button>
                                    </div>
                                </div>

                                <div class="pc-col-12 pc-start-notes">
                                    <div class="origin-control">
                                        <button type="button" class="btn btn-outline btn-sm" id="origin-clear"
                                                @if (! $pickupLat && ! $pickupLabel) hidden @endif>Clear</button>
                                        <span class="origin-status" id="origin-status" role="status">
                                            @if ($pickupLabel)
                                                Starting from {{ $pickupLabel }}.
                                            @else
                                                Not set &mdash; we'll plan from Davao City centre.
                                            @endif
                                        </span>
                                    </div>
                                    @error('origin_label')<p class="pc-hint is-error" role="alert">{{ $message }}</p>@enderror
                                    @if (\App\Support\Features::tripLocation())
                                        <p class="pc-hint">Your start point has to be inside Davao Region. Leave it blank and we plan from Davao City centre.</p>
                                    @endif
                                    <p class="pc-privacy"><x-icon name="shield-check" /> Private: rounded to about 100 metres, never linked to your name, and cleared when you clear the trip.</p>
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
                <img class="plan-loading-davo" src="{{ asset('images/davo-planning.webp') }}" alt="" width="420" height="504">
                <div class="plan-loading-copy">
                <div class="plan-loading-kicker">Hang tight</div>
                <h2 id="planLoadingTitle">Davo is planning your trip…</h2>
                <ul class="plan-loading-workflow" aria-label="Itinerary preparation workflow">
                    <li><span aria-hidden="true"></span> Picking spots that match your interests</li>
                    <li><span aria-hidden="true"></span> Ordering your stops</li>
                    <li><span aria-hidden="true"></span> Checking opening hours</li>
                    <li><span aria-hidden="true"></span> Adding travel times between stops</li>
                </ul>
                <div class="plan-loading-track" aria-hidden="true"><span></span></div>
                </div>
            </section>
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

        var NOT_SET = "Not set — we'll plan from Davao City centre.";
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
                    // trip_location_v2: a position outside Davao Region is not used as the start point (the server checks too).
                    if (btn.dataset.regionXi) {
                        var poly = JSON.parse(btn.dataset.regionXi), inside = false, x = pos.coords.longitude, y = pos.coords.latitude;
                        for (var i = 0, j = poly.length - 1; i < poly.length; j = i++) {
                            if (((poly[i][1] > y) !== (poly[j][1] > y)) && (x < (poly[j][0] - poly[i][0]) * (y - poly[i][1]) / (poly[j][1] - poly[i][1]) + poly[i][0])) inside = !inside;
                        }
                        if (!inside) {
                            btn.disabled = false;
                            say('Your location looks to be outside Davao Region, so we cannot start the trip from there. Choose where you will stay or arrive in the region instead.');
                            return;
                        }
                    }
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
                        ? 'No problem — type an address instead, or leave it blank.'
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
<script src="{{ asset('js/plan-stay-summary.js') }}?v={{ filemtime(public_path('js/plan-stay-summary.js')) }}" defer></script>

@endsection
