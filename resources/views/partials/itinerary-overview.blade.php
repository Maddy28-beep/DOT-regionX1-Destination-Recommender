{{--
    "Trip overview" beside the day-by-day plan: a map of every place in the trip and the facts the traveller
    gave us (duration, who is travelling, interests, budget, where it starts).

    The map is drawn only from places we hold coordinates for, numbered in the order they are visited, and it
    is left out altogether when there is nothing to plot. The facts come from the survey the plan was built
    from; rows with no answer are skipped.

    $itinerary, $preference, $routeStops (see Itinerary::routeStops())
--}}
@php
    // Every plottable place, in trip order, each shown once (the hotel comes up every day).
    $overviewStops = [];
    $seen = [];
    foreach ($routeStops as $day => $dayStops) {
        foreach ($dayStops as $stop) {
            if ($stop['lat'] === null || $stop['lng'] === null || isset($seen[$stop['label']])) {
                continue;
            }
            $seen[$stop['label']] = true;
            $overviewStops[] = ['lat' => $stop['lat'], 'lng' => $stop['lng'], 'label' => $stop['label'], 'day' => (int) $day];
        }
    }

    $interests = $preference?->activities?->pluck('activity')->filter()->values() ?? collect();
@endphp

<aside class="itin-overview" aria-labelledby="itin-overview-title">
    <h2 id="itin-overview-title">Trip overview</h2>

    @if ($overviewStops !== [])
        <div class="itin-overview__map" id="itinOverviewMap" role="img" aria-label="Map of the places in your trip"
             data-stops='@json($overviewStops)'
             data-leaflet-css="{{ asset('vendor/leaflet/leaflet.css') }}"
             data-leaflet-js="{{ asset('vendor/leaflet/leaflet.js') }}"></div>
        <p class="itin-overview__legend">{{ count($overviewStops) }} {{ \Illuminate\Support\Str::plural('place', count($overviewStops)) }} on the map, numbered in the order you visit them.</p>
    @endif

    <dl class="itin-overview__facts">
        <div><dt><x-icon name="calendar" /> Duration</dt><dd>{{ $itinerary->total_days }} {{ \Illuminate\Support\Str::plural('day', $itinerary->total_days) }}</dd></div>
        @if ($preference && filled($preference->travel_type))
            <div><dt><x-icon name="user" /> Travel style</dt><dd>{{ $preference->travel_type }}</dd></div>
        @endif
        @if ($interests->isNotEmpty())
            <div><dt><x-icon name="compass" /> Interests</dt><dd>{{ $interests->join(', ', ' and ') }}</dd></div>
        @endif
        @if ($preference && filled($preference->budget))
            <div><dt><x-icon name="tag" /> Budget</dt><dd>{{ $preference->budget }}</dd></div>
        @endif
        @if (! $itinerary->package)
            <div><dt><x-icon name="map-pin" /> Starting from</dt><dd>{{ $preference?->origin_label ?: 'Davao City centre' }}</dd></div>
        @endif
    </dl>

    <p class="itin-overview__note"><x-icon name="info" /> Review opening hours before your visit.</p>
</aside>
