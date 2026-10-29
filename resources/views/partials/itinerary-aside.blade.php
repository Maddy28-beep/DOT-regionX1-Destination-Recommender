{{--
    The column beside the day-by-day plan: the selected day on a map, and -- for a plan that was built around the
    traveller's choices -- the tour operators near their start and what the plan was built around.

    The map follows the selected day (public/js/itinerary-days.js redraws it from data-days). It is drawn only from
    places we hold coordinates for, numbered in the order they are visited, and left out when there is nothing to plot.

    $itinerary, $preference, $routeStops (see Itinerary::routeStops()), $dayMeta (day => stops, km),
    $operators (TourOperatorSuggester::near(), empty unless the traveller chose a pick-up)
--}}
@php
    $dayMaps = [];
    foreach ($routeStops as $day => $dayStops) {
        $plottable = collect($dayStops)->filter(fn ($s) => $s['lat'] !== null && $s['lng'] !== null)->values();
        $dayMaps[$day] = [
            'stops' => $plottable->map(fn ($s) => ['lat' => $s['lat'], 'lng' => $s['lng'], 'label' => $s['label']])->all(),
            'count' => $dayMeta[$day]['stops'] ?? 0,
            'km' => $dayMeta[$day]['km'] ?? 0,
            'gmaps' => \App\Models\Itinerary::googleMapsUrl($dayStops),
        ];
    }
    $hasMap = collect($dayMaps)->contains(fn ($d) => $d['stops'] !== []);
    $firstDay = array_key_first($dayMaps);

    // Where the traveller starts: the first journey of the first day leaves from here.
    $origin = $itinerary->package ? null : [
        'lat' => $preference?->origin_lat !== null ? (float) $preference->origin_lat : \App\Services\Recommendation\ContentBasedRecommendationService::DEFAULT_ORIGIN_LAT,
        'lng' => $preference?->origin_lng !== null ? (float) $preference->origin_lng : \App\Services\Recommendation\ContentBasedRecommendationService::DEFAULT_ORIGIN_LNG,
        'label' => $preference?->startLabel() ?? 'Davao City centre',
    ];
    $fmtKm = fn ($km) => rtrim(rtrim(number_format((float) $km, 1), '0'), '.');
@endphp

<aside class="itin-side" aria-label="Map and trip details">
    @if ($hasMap)
        <section class="itin-side__card itin-daymap" aria-labelledby="itinDayMapTitle">
            <header class="itin-daymap__head">
                <h2 id="itinDayMapTitle">Day {{ $firstDay }} on the map</h2>
                <span id="itinDayMapMeta">{{ $dayMaps[$firstDay]['count'] }} {{ \Illuminate\Support\Str::plural('stop', $dayMaps[$firstDay]['count']) }}@if ($dayMaps[$firstDay]['km'] > 0) &middot; {{ $fmtKm($dayMaps[$firstDay]['km']) }} km @endif</span>
            </header>
            <div class="itin-daymap__map" id="itinDayMap" role="img" aria-label="Map of the selected day's stops"
                 data-days='@json($dayMaps)'
                 data-origin='@json($origin)'
                 data-leaflet-css="{{ asset('vendor/leaflet/leaflet.css') }}"
                 data-leaflet-js="{{ asset('vendor/leaflet/leaflet.js') }}"></div>
            <div class="itin-daymap__buttons">
                <a class="btn btn-outline" id="itinDayGmaps" href="{{ $dayMaps[$firstDay]['gmaps'] }}" target="_blank" rel="noopener noreferrer"><x-icon name="external-link" /> Google Maps<span class="sr-only"> (opens in a new tab)</span></a>
                <button type="button" class="btn btn-outline" id="itinDayDirections" hidden><x-icon name="route" /> Directions</button>
            </div>
        </section>
    @endif

    @unless ($itinerary->package)
        @include('partials.itinerary-planned-around', ['preference' => $preference, 'operators' => $operators ?? collect()])
    @endunless
</aside>
