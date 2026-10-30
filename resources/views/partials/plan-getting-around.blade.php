{{--
    Step 4 of the trip form: how far to go, and how the traveller gets from their starting point.

    Distance posts distance_pref as before. Transport posts transport_pref, where "public" stands for the
    Public Transport card and public_kind says which kind (jeep or taxi); the server turns that pair into the
    single TransportMode the planner stores (TripPlannerController::update). Transport is optional: leaving it
    blank plans as a personal car, exactly as every trip did before the question existed.

    The comparison under the cards (plan-comfort.js) is drawn from TransportMode::comparison(), the table the
    planner itself times journeys with, so the page cannot promise a pace the plan does not use.

    $preference, $distanceRadii, $transportComparison
--}}
@use('App\Services\Recommendation\TransportMode')
@php
    $distanceNow = old('distance_pref', $preference->distance_pref) ?: 'moderate';
    $stored = old('transport_pref', $preference->transport_pref);
    // The card the stored mode belongs to: jeep and taxi both sit under Public Transport.
    $transportNow = TransportMode::isPublic($stored) ? 'public' : ($stored ?: '');
    $kindNow = old('public_kind', TransportMode::isPublic($stored) ? $stored : TransportMode::JEEP);

    $distanceCards = [
        'near' => ['Close Proximity', 'Right around your starting point. Short rides between stops.', 'map-pin'],
        'moderate' => ['Commutable', 'Nearby towns and farms, an easy day trip. How far depends on how you travel.', 'target'],
        'far' => ['Remote', 'Mountains, islands and far coasts. Worth a longer journey.', 'compass'],
    ];
    $transportCards = [
        TransportMode::TOUR_OPERATOR => ['Tour Operator Pick-up', 'A tour operator collects you at your starting point.', 'van'],
        'public' => ['Commute: Public Transport', 'Jeepney or taxi from your starting point. Cheaper, with waiting.', 'bus'],
        TransportMode::PERSONAL_CAR => ['Commute: Personal Car', 'Drive yourself from your starting point.', 'car'],
        TransportMode::RENTAL_CAR => ['Commute: Rent a Car', 'Rent a car for the trip, with or without a driver.', 'key'],
    ];
@endphp

<div class="plan-section">
    <h3 class="plan-section__title"><x-icon name="target" /> Distance &amp; getting around</h3>

    <fieldset class="pc-group">
        <legend>How far will you go?</legend>
        {{-- The distance is the recommender's own gate (RANGE_RADIUS_KM, COMMUTABLE_RADIUS_KM), not a number written here, so what a
             traveller reads is what their results are filtered by. "about" is deliberate: when a radius holds
             too few places the gate widens, and the itinerary says so. --}}
        <p class="pc-help">Measured from your starting point. If there aren't enough DOT-accredited places in range, we widen the search and tell you on the itinerary.</p>
        <div class="pc-cards">
            @foreach ($distanceCards as $value => [$name, $note, $icon])
                <label class="pc-card">
                    <input type="radio" name="distance_pref" value="{{ $value }}" @checked($distanceNow === $value) required>
                    <span class="pc-card__icon" aria-hidden="true"><x-icon :name="$icon" /></span>
                    <span class="pc-card__text">
                        <strong>{{ $name }}</strong>
                        <small>{{ $note }}</small>
                        <em>{{ $distanceLabels[$value] }}</em>
                    </span>
                    <span class="pc-card__tick" aria-hidden="true"><x-icon name="check" /></span>
                </label>
            @endforeach
        </div>
    </fieldset>

    <fieldset class="pc-group">
        <legend>How will you get from your starting point? <span class="field-optional-hint">(optional)</span></legend>
        <p class="pc-help">Be picked up by a tour operator, or commute on your own. Skip it and we plan with a car.</p>
        <div class="pc-cards pc-cards--4" data-transport-cards>
            @foreach ($transportCards as $value => [$name, $note, $icon])
                <label class="pc-card">
                    <input type="radio" name="transport_pref" value="{{ $value }}" @checked($transportNow === $value)>
                    <span class="pc-card__icon" aria-hidden="true"><x-icon :name="$icon" /></span>
                    <span class="pc-card__text"><strong>{{ $name }}</strong><small>{{ $note }}</small></span>
                    <span class="pc-card__tick" aria-hidden="true"><x-icon name="check" /></span>
                </label>
            @endforeach
        </div>

        <div class="pc-sub" data-transport-public @unless ($transportNow === 'public') hidden @endunless>
            <strong>Which kind of public transport?</strong>
            <div class="pc-chips">
                <label class="pc-chip"><input type="radio" name="public_kind" value="jeep" @checked($kindNow !== TransportMode::TAXI)><span>Jeepney</span></label>
                <label class="pc-chip"><input type="radio" name="public_kind" value="taxi" @checked($kindNow === TransportMode::TAXI)><span>Taxi</span></label>
            </div>
        </div>

        <div class="pc-sub" data-transport-operator @unless ($transportNow === TransportMode::TOUR_OPERATOR) hidden @endunless>
            <strong>How tour operator pick-up works here</strong>
            <span class="pc-help">We suggest accredited operators near your starting point and show how to reach them. Pick-up time, place and price are set by the operator, so confirm with them before you travel.</span>
        </div>

        <div class="pc-compare" data-transport-compare
             data-modes='@json($transportComparison)'
             data-samples='@json(['near' => 18, 'moderate' => 55, 'far' => 110])'
             aria-live="polite">
            <div class="pc-compare__head"><strong data-compare-title></strong><span>Estimate only, for planning</span></div>
            <div data-compare-bars></div>
            <p class="pc-compare__tip" data-compare-tip hidden></p>
        </div>
    </fieldset>
</div>
