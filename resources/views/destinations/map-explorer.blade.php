<link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
<link rel="stylesheet" href="{{ asset('css/destination-explorer.css') }}?v={{ filemtime(public_path('css/destination-explorer.css')) }}">
<section id="destinationMapView" aria-label="Explore destinations on a map" hidden>
    <p class="explorer-hint">Select a map pin to view destination details.</p>
    <div id="destinationExplorerMap" class="explorer-map" aria-label="Destination map"></div>
    <p id="destinationMapStatus" class="explorer-hint" role="status"></p>
</section>
<script>window.destinationExplorerData = {{ Illuminate\Support\Js::from($mapDestinations) }};</script>
<script src="{{ asset('vendor/leaflet/leaflet.js') }}" defer></script>
<script src="{{ asset('js/destination-explorer.js') }}?v={{ filemtime(public_path('js/destination-explorer.js')) }}" defer></script>
