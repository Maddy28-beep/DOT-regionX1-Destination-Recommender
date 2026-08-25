@if ($latitude && $longitude)
    @once
        <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
        <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
    @endonce
    @php
        $mapId = 'map-'.\Illuminate\Support\Str::random(8);
    @endphp
    <div id="{{ $mapId }}" class="listing-map" style="height:260px; border-radius:var(--radius-sm); margin-top:14px;"></div>
    <script>
        (function () {
            function initMap() {
                var map = L.map('{{ $mapId }}', { scrollWheelZoom: false }).setView([{{ $latitude }}, {{ $longitude }}], 14);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(map);
                L.marker([{{ $latitude }}, {{ $longitude }}]).addTo(map)
                    .bindPopup({{ Illuminate\Support\Js::from($name) }});
            }
            if (window.L) { initMap(); } else { window.addEventListener('load', initMap); }
        })();
    </script>
@endif
