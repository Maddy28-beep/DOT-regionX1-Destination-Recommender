{{--
    Step 1 of Plan My Trip, in three blocks on one 12-column grid: who is coming, when, and where they are from.
    ("Where you start" is the fourth block, in the form itself, because the address picker belongs to the page.)

    Every choice is a real form control drawn as a stepper, cards or chips: the days are a number input, the
    companions and the kind of visit are radio groups, and the arrival time is still the time input (the chips only
    fill it in, see public/js/plan-basics.js). Names and values are unchanged, so the server, the saved draft and the
    browser's own validation work as before. Every control is one height (--pc-h) so each row lines up, and each
    field keeps one line for its hint so neighbours end level.

    $preference, $travelTypes, $visitorTypes
--}}
@php
    // trip_location_v2 (13 Nov 2026): no past arrival date, a finish-by time instead of a departure point, and the visitor's
    // origin as country / province / city. Off = the form below is exactly the one that was there before.
    $v2 = \App\Support\Features::tripLocation();
    $todayManila = \Illuminate\Support\Carbon::now('Asia/Manila')->toDateString();
    // The trip's real start and end (Philippine time), one date-time each. A trip saved before they existed shows the
    // start and end it works out to (arrival date and time; the last day to its finish-by time or 23:59).
    $inputFormat = \App\Support\TripWindow::INPUT_FORMAT;
    $startAtValue = old('start_at', $preference->tripStart()?->format($inputFormat));
    $endAtValue = old('end_at', $preference->tripEnd()?->format($inputFormat));
    $nowManilaInput = \App\Support\TripWindow::now()->format($inputFormat);
    $allowPastStart = (bool) config('itinerary.trip_window.allow_past_start') || auth('admin')->check();
    // A saved trip whose start has passed: do not pre-fill a date that can no longer be chosen, ask for a new one instead.
    $savedStart = $preference->tripStart();
    $startHasPassed = ! $allowPastStart && ! session()->hasOldInput() && $savedStart !== null && $savedStart->format($inputFormat) < $nowManilaInput;
    if ($startHasPassed) {
        $startAtValue = '';
        $endAtValue = '';
    }
    $tripMinHours = \App\Support\TripWindow::minHours();
    $tripMaxDays = \App\Support\TripWindow::maxDays();
    $typeIcons = ['Solo' => 'user', 'Couple' => 'users', 'Family' => 'family', 'Friends' => 'group', 'Business' => 'briefcase'];
    $visitorIcons = ['First-time Visitor' => 'plane', 'Returning Visitor' => 'repeat', 'Regular / Local' => 'home'];
    $chosenType = old('travel_type', $preference->travel_type) ?: 'Solo';
    $chosenVisitor = old('visitor_type', $preference->visitor_type);
    $isLocal = $chosenVisitor === 'Regular / Local';
    $arrivalValue = old('arrival_time', $preference->arrival_time ? substr((string) $preference->arrival_time, 0, 5) : '');
    $slots = ['08:00' => ['Morning', 'about 8 AM'], '12:00' => ['Midday', 'about noon'], '18:00' => ['Evening', 'about 6 PM']];

    // Quoted from the scheduler so the help can never disagree with what the planner does.
    $prepMinutes = \App\Services\Recommendation\ItineraryScheduleBuilder::ARRIVAL_PREPARATION_MINUTES;
    $earliestStart = \Illuminate\Support\Carbon::parse(\App\Services\Recommendation\ItineraryScheduleBuilder::EARLIEST_DEPARTURE)->format('g:i A');
    $lastSightseeing = \Illuminate\Support\Carbon::parse(\App\Services\Recommendation\ItineraryScheduleBuilder::LAST_ACTIVITY_END)->format('g:i A');
    // Leaving (optional). The allowance is quoted from configuration, so the help can never disagree with the planner.
    $departureBuffer = (int) config('itinerary.departure.airport_buffer_minutes', 120);
    $departureBufferText = $departureBuffer % 60 === 0 ? ($departureBuffer / 60).' '.($departureBuffer === 60 ? 'hour' : 'hours') : $departureBuffer.' minutes';
    $departureValue = old('departure_time', $preference->departure_time ? substr((string) $preference->departure_time, 0, 5) : '');
    $departureHelp = "Tell us when you leave and from where, and your last day is planned to get you there on time: we allow {$departureBufferText} at the departure point before your departure (a planning allowance, not an airline rule) and the travel time is an estimate, not a routed time. A stop that would risk the departure is left out, and with little time left the day is just the journey. Leave it blank and nothing about your last day changes.";
    // The day you leave is derived (arrival date + days - 1), so there is no date to enter.
    $startShown = old('start_date', optional($preference->start_date)->format('Y-m-d'));
    $daysShown = min(30, max(1, (int) old('travel_days', $preference->travel_days ?? 3)));
    // Rejected input comes back as typed, so an unreadable date is treated as no date.
    $leavingDay = $startShown ? rescue(fn () => \Illuminate\Support\Carbon::parse($startShown)->addDays($daysShown - 1), null, false) : null;
    $leavingLine = $leavingDay ? 'Leaving '.$leavingDay->format('F j') : 'Add your arrival date to see your departure date.';
    $daysHelp = 'Days include your arrival and departure day; nights are one fewer.';
    $arrivalHelp = "When you expect to arrive in Davao. We allow about {$prepMinutes} minutes before your first outing and never start an outing before {$earliestStart}. A later arrival leaves less of your first day, so it may have fewer stops, or none if you arrive in the evening. Destination sightseeing ends by {$lastSightseeing}. Meals fall at 7:00 to 8:30 AM, 11:30 AM to 1:00 PM (usually 12:00) and 6:00 to 7:30 PM; if you arrive during one, we plan that meal first. Leave it blank and we'll assume {$earliestStart}.";
@endphp

<div class="plan-section pc-block pc-trip-companions">
    <header class="pc-block__head"><h3>Who&rsquo;s coming</h3></header>
    <fieldset class="pc-group">
        <legend class="sr-only">Who are you traveling with?</legend>
        <div class="pc-cards pc-cards--5">
            @foreach ($travelTypes as $value => $label)
                <label class="pc-card pc-card--center">
                    <input type="radio" name="travel_type" value="{{ $value }}" @checked($chosenType === $value) required>
                    <span class="pc-card__icon"><x-icon :name="$typeIcons[$value] ?? 'user'" /></span>
                    <strong>{{ $label }}</strong>
                </label>
            @endforeach
        </div>
    </fieldset>
</div>

@if ($v2)
{{-- The trip's real start and end, in Philippine time. Nothing else says how long the trip is: there is no number of days,
     arrival date, arrival time or finish-by to fill in. The days follow from these two moments (public/js/plan-trip-window.js
     shows the length as you type; the server checks it again). The wizard places this block after the pick-up location. --}}
<div class="plan-section pc-block pc-trip-dates pc-trip-window" data-trip-window data-min-hours="{{ $tripMinHours }}" data-max-days="{{ $tripMaxDays }}" data-now="{{ $nowManilaInput }}" data-allow-past="{{ $allowPastStart ? '1' : '0' }}">
    <header class="pc-block__head"><h3>When does your trip start and end?</h3><p>Philippine time. The start is when you are free at your pick-up location; the end is when you have to be finished, for example back at the airport.</p></header>
    @if ($startHasPassed)
        <p class="pc-hint is-error" role="alert" data-start-passed>Your saved trip started on {{ $savedStart->format('D, j M Y, g:i A') }} (Philippine time), which has passed. Please choose a new start and end date and time.</p>
    @endif
    <div class="pc-grid">
        <div class="field pc-field pc-col-6">
            <label for="start_at">Start date &amp; time</label>
            <input type="datetime-local" id="start_at" name="start_at" value="{{ $startAtValue }}" step="60" required @unless ($allowPastStart) min="{{ $nowManilaInput }}" @endunless>
            @error('start_at')<p class="pc-hint is-error" role="alert">{{ $message }}</p>@enderror
            <p class="pc-hint">Nothing is planned before this moment. We count the travel from your pick-up location to the first stop.</p>
        </div>
        <div class="field pc-field pc-col-6">
            <label for="end_at">End date &amp; time
                <span class="field-help" tabindex="0" title="The moment your trip has to be finished. Your last stop ends early enough to get you back to your pick-up location, with {{ $departureBufferText }} to spare, a planning allowance and not a routed time." aria-label="The moment your trip has to be finished. Your last stop ends early enough to get you back to your pick-up location, with {{ $departureBufferText }} to spare."><x-icon name="info" /></span>
            </label>
            <input type="datetime-local" id="end_at" name="end_at" value="{{ $endAtValue }}" step="60" required>
            @error('end_at')<p class="pc-hint is-error" role="alert">{{ $message }}</p>@enderror
            <p class="pc-hint">At least {{ $tripMinHours }} hours after the start, and at most {{ $tripMaxDays }} days.</p>
        </div>
        <div class="pc-col-12">
            <p class="pc-hint pc-trip-duration" data-trip-duration aria-live="polite">Choose a start and an end to see how long your trip is.</p>
        </div>
    </div>
</div>
@else
<div class="plan-section pc-block pc-trip-dates">
    <header class="pc-block__head"><h3>When are you visiting?</h3><p>Only the number of days is required. Everything else is optional.</p></header>
    <div class="pc-grid">
        <div class="field pc-field pc-col-3">
            <label for="travel_days">Number of travel days
                <span class="field-help" tabindex="0" title="{{ $daysHelp }}" aria-label="{{ $daysHelp }}"><x-icon name="info" /></span>
            </label>
            <div class="pc-stepper" data-stepper>
                <button type="button" data-step="-1" aria-label="One day fewer"><x-icon name="minus" /></button>
                <span class="pc-stepper__value">
                    <input type="number" id="travel_days" name="travel_days" min="1" max="30" value="{{ old('travel_days', $preference->travel_days ?? 3) }}" required>
                    <span data-stepper-unit>days</span>
                </span>
                <button type="button" data-step="1" aria-label="One day more"><x-icon name="plus" /></button>
            </div>
            <p class="pc-hint" data-trip-dates>Add an arrival date to see your dates.</p>
        </div>

        <div class="field pc-field pc-col-3">
            <label for="start_date">Arrival date (optional)</label>
            <input type="date" id="start_date" name="start_date" value="{{ old('start_date', optional($preference->start_date)->format('Y-m-d')) }}" @if ($v2) min="{{ $todayManila }}" @endif>
            @if ($v2)
                @error('start_date')<p class="pc-hint is-error" role="alert">{{ $message }}</p>@enderror
                <p class="pc-hint">Not sure yet? Leave it blank. Weekday opening hours are then not checked. Dates are in Philippine time.</p>
            @else
                <p class="pc-hint">Not sure yet? Leave it blank.</p>
            @endif
        </div>

        <div class="field pc-field pc-col-6" data-quick-dates hidden>
            <span class="pc-label" id="quickDatesLabel">Or pick quickly</span>
            <div class="pc-quick" role="group" aria-labelledby="quickDatesLabel">
                <button type="button" data-quick="weekend">This weekend</button>
                <button type="button" data-quick="week">Next week</button>
                <button type="button" data-quick="month">Next month</button>
            </div>
            <p class="pc-hint">Sets the arrival date for you.</p>
        </div>

        <div class="field pc-field pc-col-12" data-arrival>
            <label for="arrival_time">Arrival time (optional)
                <span class="field-help" tabindex="0" title="{{ $arrivalHelp }}" aria-label="{{ $arrivalHelp }}"><x-icon name="info" /></span>
            </label>
            {{-- The chips fill the time in; Exact time takes the time input into its own chip. Without JavaScript the
                 chips stay hidden and the time input does the job where it is. --}}
            <div class="pc-slots" data-arrival-slots>
                @foreach ($slots as $time => [$name, $about])
                    <button type="button" class="pc-slot" data-time="{{ $time }}" aria-pressed="false"><strong>{{ $name }}</strong><small>{{ $about }}</small></button>
                @endforeach
                <div class="pc-slot pc-slot--exact" data-exact>
                    <button type="button" class="pc-slot__open" data-exact-open aria-pressed="false"><strong>Exact time</strong><small data-exact-sub>pick hour &amp; minute</small></button>
                </div>
            </div>
            <input type="time" id="arrival_time" name="arrival_time" value="{{ $arrivalValue }}">
            <p class="pc-hint">Helps us plan your first day. Leave it blank and we assume {{ $earliestStart }}.</p>
        </div>
    </div>

    @if ($v2)
        {{-- The trip's end. The date is the last day of the trip (arrival date + days - 1) and is only shown; the time is the
             traveller's own "finish by". The allowance before it covers the journey back to where the trip started. --}}
        <div class="field pc-field pc-trip-finish">
            <label for="end_time">Finish by <span class="pc-hint">(optional)</span>
                <span class="field-help" tabindex="0" title="The latest you want to be finished on your last day, in Philippine time. We keep {{ $departureBufferText }} free before it for the journey back to where you started, so your last stops end earlier. Leave it blank and your last day ends at its usual time." aria-label="The latest you want to be finished on your last day, in Philippine time. We keep {{ $departureBufferText }} free before it for the journey back to where you started."><x-icon name="info" /></span>
            </label>
            <input type="time" id="end_time" name="end_time" value="{{ $endValue }}">
            @error('end_time')<p class="pc-hint is-error" role="alert">{{ $message }}</p>@enderror
            <p class="pc-hint" data-finish-date aria-live="polite">{{ $leavingDay ? 'Your trip ends '.$leavingDay->format('l, F j') : 'Add your arrival date to see when your trip ends.' }}</p>
            <p class="pc-hint">Philippine time. We keep {{ $departureBufferText }} free before this time for the journey back to where you started.</p>
        </div>
    @else
    <fieldset class="pc-trip-departure">
        <legend>Leaving Davao <span>Optional</span></legend>
        <div class="pc-departure-grid">
            {{-- Leaving: all optional. The point is chosen here, never guessed from where the trip starts. The buttons only
                 fill the box and the two hidden coordinates (public/js/plan-departure.js); the server also reads a typed place. --}}
            <div class="field pc-field" data-departure>
                <label for="departure_time">Departure time
                    <span class="field-help" tabindex="0" title="{{ $departureHelp }}" aria-label="{{ $departureHelp }}"><x-icon name="info" /></span>
                </label>
                <input type="time" id="departure_time" name="departure_time" value="{{ $departureValue }}">
                @error('departure_time')<p class="pc-hint is-error" role="alert">{{ $message }}</p>@enderror
                {{-- You leave on the last day of the trip: the arrival date + (days - 1). Nothing to enter; plan-basics.js keeps it live. --}}
                <p class="pc-hint" data-departure-date aria-live="polite">{{ $leavingLine }}</p>
            </div>

            <div class="field pc-field">
                <label for="departure_label">Leaving from <span class="pc-hint">(required if you enter a time)</span></label>
                <input type="text" id="departure_label" name="departure_label" value="{{ old('departure_label', $preference->departure_label) }}" placeholder="Airport, seaport or terminal" autocomplete="off">
                <input type="hidden" name="departure_lat" id="departure_lat" value="{{ old('departure_lat', $preference->departure_lat) }}">
                <input type="hidden" name="departure_lng" id="departure_lng" value="{{ old('departure_lng', $preference->departure_lng) }}">
                @error('departure_label')<p class="pc-hint is-error" role="alert">{{ $message }}</p>@enderror
            </div>
        </div>
        <div class="pc-departure-shortcuts">
            <div class="pc-quick" role="group" aria-label="Common departure points" data-departure-points hidden>
                <button type="button" data-departure-pick="Davao International Airport" data-lat="7.130" data-lng="125.645">Davao Airport</button>
                <button type="button" data-departure-pick="Port of Sasa" data-lat="7.127" data-lng="125.663">Seaport</button>
                <button type="button" data-departure-pick="Davao City Overland Transport Terminal, Ecoland" data-lat="7.056" data-lng="125.602">Bus terminal</button>
                <button type="button" data-departure-same>Same as where I start</button>
            </div>
            <p class="pc-hint">Add this only if you have a flight or bus to catch. We allow {{ $departureBufferText }} there before you leave.</p>
        </div>
    </fieldset>
    @endif
</div>

@endif

<div class="plan-section pc-block pc-trip-visitor">
    <header class="pc-block__head"><h3>About you</h3></header>
    <div class="pc-grid">
        <fieldset class="pc-group pc-field pc-col-8">
            <legend>Is this your first visit to Davao?</legend>
            <div class="pc-cards pc-cards--3 pc-cards--rows">
                @foreach ($visitorTypes as $type)
                    <label class="pc-card pc-card--row">
                        <input type="radio" name="visitor_type" value="{{ $type }}" data-local-toggle @checked($chosenVisitor === $type) required>
                        <span class="pc-card__icon"><x-icon :name="$visitorIcons[$type] ?? 'user'" /></span>
                        <strong>{{ $type }}</strong>
                    </label>
                @endforeach
            </div>
        </fieldset>

        @if ($v2)
        @php
            $countryChosen = old('visitor_origin_country', $preference->visitor_origin_source === 'unspecified' ? 'NONE' : $preference->visitor_origin_country_code);
            $provinceChosen = old('visitor_origin_province', $preference->visitor_origin_province);
            $cityTyped = old('visitor_origin_city', $preference->visitor_origin_city);
        @endphp
        <div class="field pc-field pc-col-4" data-origin-field data-origin-v2>
            <label for="visitor_origin_country"><span data-origin-label>Where are you travelling from?</span>
                <span class="field-help" tabindex="0" title="Helps DOT understand where visitors are travelling from. We keep only the place names, never your position or an address." aria-label="Helps DOT understand where visitors are travelling from. We keep only the place names, never your position or an address."><x-icon name="info" /></span>
            </label>
            <select id="visitor_origin_country" name="visitor_origin_country" data-origin-required @unless ($isLocal) required @endunless>
                <option value="">Choose a country</option>
                <option value="PH" @selected($countryChosen === 'PH')>Philippines</option>
                <option value="NONE" @selected($countryChosen === 'NONE')>Prefer not to say</option>
                <optgroup label="Other countries">
                    @foreach (\App\Support\Geo\Countries::ALL as $code => $countryName)
                        @continue($code === 'PH')
                        <option value="{{ $code }}" @selected($countryChosen === $code)>{{ $countryName }}</option>
                    @endforeach
                </optgroup>
            </select>
            @error('visitor_origin_country')<p class="pc-hint is-error" role="alert">{{ $message }}</p>@enderror

            <select id="visitor_origin_province" name="visitor_origin_province" aria-label="Province" data-origin-province @if ($countryChosen !== 'PH') hidden @endif>
                <option value="">Province (optional)</option>
                @foreach (\App\Support\Geo\PhProvinces::BY_REGION as $regionName => $provinceList)
                    <optgroup label="{{ $regionName }}">
                        @foreach ($provinceList as $provinceName)
                            <option value="{{ $provinceName }}" @selected($provinceChosen === $provinceName)>{{ $provinceName }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            @error('visitor_origin_province')<p class="pc-hint is-error" role="alert">{{ $message }}</p>@enderror

            <input type="text" id="visitor_origin_city" name="visitor_origin_city" value="{{ $cityTyped }}" maxlength="100" autocomplete="off"
                   aria-label="City or municipality" placeholder="City or municipality (type it)" data-origin-city
                   @if ($countryChosen === '' || $countryChosen === null || $countryChosen === 'NONE') hidden @endif>
            <p class="pc-hint" data-origin-hint>Choose a country, then a province and a city or municipality if you like. We never ask for your home address.</p>
            <p class="pc-hint" data-origin-privacy>Optional. Used for tourism statistics. Not linked to your name. Deleted after 30 days.</p>
        </div>
        @else
        <div class="field pc-field pc-col-4" data-origin-field>
            <label for="place_of_origin"><span data-origin-label>Where are you visiting from?</span>
                <span class="field-help" tabindex="0" title="Helps DOT understand where visitors are travelling from." aria-label="Helps DOT understand where visitors are travelling from."><x-icon name="info" /></span>
            </label>
            {{-- A picker over a short list of cities and countries (plan-origin.js), grouped Philippines then abroad.
                 Anything typed is still accepted, so the field never blocks: without JavaScript it is a plain text box.
                 It asks for a place, never for a home address. --}}
            <div class="address-field" data-origin-picker>
                <x-icon name="globe" class="pc-origin__pin" />
                <input type="text" id="place_of_origin" name="place_of_origin"
                       value="{{ old('place_of_origin', $preference->place_of_origin) }}"
                       placeholder="Start typing a city or country"
                       autocomplete="off"
                       role="combobox"
                       aria-expanded="false"
                       aria-autocomplete="list"
                       aria-controls="origin-from-suggestions"
                       @unless ($isLocal) required @endunless>
                <ul id="origin-from-suggestions" class="address-suggestions pc-places" role="listbox" aria-label="Places" hidden></ul>
            </div>
            <p class="pc-hint" data-origin-hint>City, province or country is enough. We never ask for your home address.</p>
        </div>
        @endif
    </div>
</div>
