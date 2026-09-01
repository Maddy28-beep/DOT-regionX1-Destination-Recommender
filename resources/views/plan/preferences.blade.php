@extends('layouts.app')

@section('title', 'Travel Preferences — ExploreDVO')

@section('content')
@php
    $selectedActivities = $preference->relationLoaded('activities') ? $preference->activities->pluck('activity')->all() : [];
    $selectedAmenities = $preference->relationLoaded('amenities') ? $preference->amenities->pluck('amenity')->all() : [];
    $selectedConditions = $healthProfile ? $healthProfile->conditions->pluck('condition')->all() : [];

    $activityOptions = ['Beach & Island', 'Nature & Adventure', 'Cultural Heritage', 'Wildlife', 'Food Tourism', 'Shopping & Souvenirs', 'Hiking & Trekking', 'Relaxation & Wellness'];
    $amenityOptions = ['Parking Area', 'Restaurant', 'Swimming Pool', 'Wi-Fi', 'Restroom', 'Accessibility Ramp', 'Air Conditioning'];
    $travelTypes = ['Solo' => 'Solo', 'Couple' => 'Couple', 'Family' => 'Family', 'Friends' => 'Friends / Group', 'Business' => 'Business'];
    $travelPurposes = ['Leisure', 'Business', 'Visiting Friends/Family', 'Educational', 'Medical', 'Religious/Pilgrimage', 'Other'];
    $visitorTypes = ['First-time Visitor', 'Returning Visitor', 'Regular / Local'];
@endphp

<div class="dash-shell">
    <div class="dash-header">
        <div class="container">
            <div>
                <h1>Plan Your Trip</h1>
                <div class="sub">Tell us how you like to travel and we'll build a day-by-day itinerary across DOT-accredited places &mdash; no account needed.</div>
            </div>
            <a href="{{ route('saved.index') }}" class="btn btn-outline">Saved Places</a>
        </div>
    </div>

    <div class="dash-body">
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

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Your Travel Preference Survey</h2>
                        <p>Answer these and your itinerary is generated straight away. No sign-up, and none of it is tied to your name.</p>
                    </div>
                </div>
                <div class="panel-body">
                    {{-- POST, not a spoofed PUT: unlike the dashboard route this
                         was adapted from, submitting here creates a preference
                         and generates an itinerary rather than updating one
                         addressable resource. --}}
                    <form method="POST" action="{{ route('plan.update') }}">
                        @csrf

                        <div class="filter-inline" style="align-items:start;">
                            <div class="field" style="flex:1; min-width:160px;">
                                <label for="travel_days">Number of travel days</label>
                                <input type="number" id="travel_days" name="travel_days" min="1" max="30" value="{{ old('travel_days', $preference->travel_days ?? 3) }}" required>
                            </div>
                            <div class="field" style="flex:1; min-width:180px;">
                                <label for="start_date">Planned arrival date (optional)</label>
                                <input type="date" id="start_date" name="start_date" value="{{ old('start_date', optional($preference->start_date)->format('Y-m-d')) }}">
                            </div>
                            <div class="field" style="flex:1; min-width:160px;">
                                <label for="arrival_time">Arrival time (optional)</label>
                                <input type="time" id="arrival_time" name="arrival_time"
                                       value="{{ old('arrival_time', $preference->arrival_time ? substr((string) $preference->arrival_time, 0, 5) : '') }}">
                                <p class="field-hint">We won't put a morning stop on your first day if you land in the afternoon.</p>
                            </div>
                        </div>

                        <div class="filter-inline" style="align-items:start; margin-top:14px;">
                            <div class="field" style="flex:1; min-width:180px;">
                                <label for="travel_type">Who are you traveling with?</label>
                                <select id="travel_type" name="travel_type" required>
                                    @foreach ($travelTypes as $value => $label)
                                        <option value="{{ $value }}" @selected(old('travel_type', $preference->travel_type) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field" style="flex:1; min-width:180px;">
                                <label for="travel_purpose">Purpose of travel</label>
                                <select id="travel_purpose" name="travel_purpose">
                                    <option value="">Prefer not to say</option>
                                    @foreach ($travelPurposes as $purpose)
                                        <option value="{{ $purpose }}" @selected(old('travel_purpose', $preference->travel_purpose) === $purpose)>{{ $purpose }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field" style="flex:1; min-width:180px;">
                                <label for="visitor_type">Is this your first visit to Davao?</label>
                                <select id="visitor_type" name="visitor_type">
                                    <option value="">Prefer not to say</option>
                                    @foreach ($visitorTypes as $type)
                                        <option value="{{ $type }}" @selected(old('visitor_type', $preference->visitor_type) === $type)>{{ $type }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="filter-inline" style="align-items:start; margin-top:14px;">
                            <div class="field" style="flex:1; min-width:180px;">
                                <label for="budget">Budget</label>
                                <select id="budget" name="budget" required>
                                    @foreach (['Budget-Friendly', 'Mid-range', 'Premium'] as $b)
                                        <option value="{{ $b }}" @selected(old('budget', $preference->budget) === $b)>{{ $b }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field" style="flex:1; min-width:180px;">
                                <label for="accommodation_pref">Accommodation preference</label>
                                <select id="accommodation_pref" name="accommodation_pref" required>
                                    @foreach (['Any', 'Beach Resort', 'Hotel', 'Homestay', 'Hostel'] as $a)
                                        <option value="{{ $a }}" @selected(old('accommodation_pref', $preference->accommodation_pref) === $a)>{{ $a === 'Homestay' ? 'Homestay / Self-catering' : $a }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field" style="flex:1; min-width:180px;">
                                <label for="distance_pref">Preferred travel distance</label>
                                <select id="distance_pref" name="distance_pref" required>
                                    <option value="near" @selected(old('distance_pref', $preference->distance_pref) === 'near')>Nearby (within city)</option>
                                    <option value="moderate" @selected(old('distance_pref', $preference->distance_pref) === 'moderate')>Moderate distance</option>
                                    <option value="far" @selected(old('distance_pref', $preference->distance_pref) === 'far')>Far / willing to travel</option>
                                </select>
                            </div>
                        </div>

                        <div class="field" style="margin-top:18px;">
                            <label>Interests &amp; activities</label>
                            <div class="checkbox-grid">
                                @foreach ($activityOptions as $activity)
                                    <label class="field-check">
                                        <input type="checkbox" name="activities[]" value="{{ $activity }}" @checked(in_array($activity, old('activities', $selectedActivities)))>
                                        <span>{{ $activity }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="field" style="margin-top:18px;">
                            <label>Preferred amenities</label>
                            <div class="checkbox-grid">
                                @foreach ($amenityOptions as $amenity)
                                    <label class="field-check">
                                        <input type="checkbox" name="amenities[]" value="{{ $amenity }}" @checked(in_array($amenity, old('amenities', $selectedAmenities)))>
                                        <span>{{ $amenity }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="field" style="margin-top:18px;">
                            <label for="accessibility_notes">Anything else we should know? (optional)</label>
                            <textarea id="accessibility_notes" name="accessibility_notes" rows="3" placeholder="e.g. traveling with young children, prefer slower-paced itineraries">{{ old('accessibility_notes', $preference->accessibility_notes) }}</textarea>
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
                        <div class="field" style="margin-top:18px;">
                            <label for="origin_label">Where are you starting from? (optional)</label>
                            <p class="field-hint">
                                Your hotel, the airport, or any address. We order your stops from nearest to
                                furthest. Rounded to about 100 metres before we store it, never linked to your
                                name, and cleared when you clear the trip.
                            </p>

                            <input type="hidden" name="origin_lat" id="origin_lat" value="{{ old('origin_lat', $preference->origin_lat) }}">
                            <input type="hidden" name="origin_lng" id="origin_lng" value="{{ old('origin_lng', $preference->origin_lng) }}">

                            {{-- Combobox pattern: a text input that owns a listbox
                                 of suggestions, so it stays usable by keyboard and
                                 to a screen reader rather than being a div that
                                 happens to respond to clicks. --}}
                            <div class="address-field">
                                <input type="text"
                                       id="origin_label"
                                       name="origin_label"
                                       value="{{ old('origin_label', $preference->origin_label) }}"
                                       placeholder="Start typing an address, e.g. Francisco Bangoy"
                                       autocomplete="off"
                                       role="combobox"
                                       aria-expanded="false"
                                       aria-autocomplete="list"
                                       aria-controls="origin-suggestions"
                                       aria-describedby="origin-status">
                                <ul id="origin-suggestions" class="address-suggestions" role="listbox" aria-label="Address suggestions" hidden></ul>
                            </div>

                            <div class="origin-control">
                                <button type="button" class="btn btn-outline btn-sm" id="origin-btn">Use my current location</button>
                                <button type="button" class="btn btn-outline btn-sm" id="origin-clear"
                                        @if (! $preference->origin_lat && ! $preference->origin_label) hidden @endif>Clear</button>
                                <span class="origin-status" id="origin-status" role="status">
                                    @if ($preference->origin_label)
                                        Starting from {{ $preference->origin_label }}.
                                    @else
                                        Not set &mdash; we'll plan from Davao City centre.
                                    @endif
                                </span>
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
                        <div class="field" style="margin-top:22px;">
                            <label>Health &amp; accessibility (optional)</label>
                            <p class="field-hint">
                                If you tell us, we will favour places that can accommodate you. Kept with this trip
                                plan only, never linked to your name, and removed the moment you untick the box below.
                            </p>
                            <div class="checkbox-grid">
                                @foreach ($healthOptions as $key => $label)
                                    <label class="field-check">
                                        <input type="checkbox" name="health_conditions[]" value="{{ $key }}" @checked(in_array($key, old('health_conditions', $selectedConditions)))>
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="field" style="margin-top:14px;">
                            <label for="health_other">Anything else about access needs? (optional)</label>
                            <input type="text" id="health_other" name="health_other" maxlength="300"
                                   value="{{ old('health_other', $healthProfile->other_text ?? '') }}"
                                   placeholder="e.g. needs step-free access to the shoreline">
                        </div>

                        <label class="field-check" style="margin-top:12px;">
                            <input type="checkbox" name="health_consent" value="1" @checked(old('health_consent', (bool) $healthProfile))>
                            <span>Use this to tailor my itinerary. I can clear it any time by unticking this box.</span>
                        </label>

                        <button type="submit" class="btn btn-primary" style="margin-top:20px;">Build My Itinerary</button>
                    </form>
                </div>
            </div>
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

@endsection
