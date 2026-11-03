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

<div class="plan-section pc-block pc-trip-dates">
    <header class="pc-block__head"><h3>When</h3><p>Only the number of days is required.</p></header>
    <div class="pc-grid">
        <div class="field pc-field pc-col-3">
            <label for="travel_days">Number of travel days</label>
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
            <input type="date" id="start_date" name="start_date" value="{{ old('start_date', optional($preference->start_date)->format('Y-m-d')) }}">
            <p class="pc-hint">Not sure yet? Leave it blank.</p>
        </div>

        <div class="field pc-field pc-col-6" data-quick-dates hidden>
            <span class="pc-label" id="quickDatesLabel">Quick pick</span>
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
        </div>

        {{-- Leaving: all optional. The point is chosen here, never guessed from where the trip starts. The buttons only
             fill the box and the two hidden coordinates (public/js/plan-departure.js); the server also reads a typed place. --}}
        <div class="field pc-field pc-col-3" data-departure>
            <label for="departure_date">Departure date (optional)
                <span class="field-help" tabindex="0" title="{{ $departureHelp }}" aria-label="{{ $departureHelp }}"><x-icon name="info" /></span>
            </label>
            <input type="date" id="departure_date" name="departure_date" value="{{ old('departure_date', optional($preference->departure_date)->format('Y-m-d')) }}">
            @error('departure_date')<p class="pc-hint is-error" role="alert">{{ $message }}</p>@enderror
        </div>

        <div class="field pc-field pc-col-3">
            <label for="departure_time">Departure time (optional)</label>
            <input type="time" id="departure_time" name="departure_time" value="{{ $departureValue }}">
            @error('departure_time')<p class="pc-hint is-error" role="alert">{{ $message }}</p>@enderror
            <p class="pc-hint">Needed if you give a date.</p>
        </div>

        <div class="field pc-field pc-col-6">
            <label for="departure_label">Leaving from (needed with a time)</label>
            <input type="text" id="departure_label" name="departure_label" value="{{ old('departure_label', $preference->departure_label) }}" placeholder="Airport, seaport or terminal" autocomplete="off">
            <input type="hidden" name="departure_lat" id="departure_lat" value="{{ old('departure_lat', $preference->departure_lat) }}">
            <input type="hidden" name="departure_lng" id="departure_lng" value="{{ old('departure_lng', $preference->departure_lng) }}">
            @error('departure_label')<p class="pc-hint is-error" role="alert">{{ $message }}</p>@enderror
            <div class="pc-quick" role="group" aria-label="Common departure points" data-departure-points hidden>
                <button type="button" data-departure-pick="Davao International Airport" data-lat="7.130" data-lng="125.645">Davao Airport</button>
                <button type="button" data-departure-pick="Port of Sasa" data-lat="7.127" data-lng="125.663">Seaport</button>
                <button type="button" data-departure-pick="Davao City Overland Transport Terminal, Ecoland" data-lat="7.056" data-lng="125.602">Bus terminal</button>
                <button type="button" data-departure-same>Same as where I start</button>
            </div>
            <p class="pc-hint">We allow {{ $departureBufferText }} there before you leave.</p>
        </div>
    </div>
</div>

<div class="plan-section pc-block pc-trip-visitor">
    <header class="pc-block__head"><h3>Where you&rsquo;re from</h3></header>
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
    </div>
</div>
