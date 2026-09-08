{{--
    Lets an establishment place itself on the map.

    Included with: $listing (anything carrying latitude/longitude).

    Why a picker rather than geocoding the address we already hold: the
    addresses in this catalogue are barangay/purok level, which is finer than
    any geocoder resolves. Tried against the real data it matched stray words
    -- returning an elementary school for one listing, a street in the wrong
    province for another -- and its confidence score gave no way to separate
    those from a good hit. The business is the only party that actually knows
    where it is, and placing itself takes one click.

    Leaflet + OpenStreetMap, both already vendored locally for the itinerary's
    route map, so this adds no new dependency and still needs no API key.
--}}

@once
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
@endonce

@php
    $bounds = \App\Http\Controllers\Establishment\EstablishmentDashboardController::REGION_BOUNDS;
    $fallback = \App\Http\Controllers\Establishment\EstablishmentDashboardController::MAP_DEFAULT;

    $lat = old('latitude', $listing->latitude);
    $lng = old('longitude', $listing->longitude);
    $hasPoint = $lat !== null && $lng !== null && $lat !== '' && $lng !== '';
@endphp

<div class="field">
    <label>Where you are on the map</label>

    <div class="privacy-note">
        <x-icon name="map-pin" />
        <p>
            Click the map, or drag the pin, to mark your entrance. This is what lets ExploreDVO
            work out travel times to you and place you on a traveller&rsquo;s day plan.
            @unless ($hasPoint)
                <strong>You have not set this yet</strong>, so we can only estimate your position
                from your city.
            @endunless
        </p>
    </div>

    @error('latitude') <div class="alert alert-error">{{ $message }}</div> @enderror
    @error('longitude') <div class="alert alert-error">{{ $message }}</div> @enderror

    <div id="location-picker" style="height:320px; border-radius:var(--radius-sm); margin-top:10px;"></div>

    <input type="hidden" name="latitude" id="picker-lat" value="{{ $hasPoint ? $lat : '' }}">
    <input type="hidden" name="longitude" id="picker-lng" value="{{ $hasPoint ? $lng : '' }}">

    <div class="origin-control" style="margin-top:10px;">
        <button type="button" class="btn btn-outline btn-sm" id="picker-locate">Use my current location</button>
        <button type="button" class="btn btn-outline btn-sm" id="picker-clear" @unless ($hasPoint) hidden @endunless>Clear the pin</button>
        <span class="origin-status" id="picker-status" role="status">
            {{ $hasPoint ? 'Pinned at '.number_format((float) $lat, 5).', '.number_format((float) $lng, 5) : 'No pin set.' }}
        </span>
    </div>
</div>

<script>
    (function () {
        var el = document.getElementById('location-picker');
        if (!el || typeof L === 'undefined') return;

        var latInput = document.getElementById('picker-lat');
        var lngInput = document.getElementById('picker-lng');
        var status = document.getElementById('picker-status');
        var clearBtn = document.getElementById('picker-clear');
        var locateBtn = document.getElementById('picker-locate');

        var bounds = @json($bounds);
        var start = @json($hasPoint ? ['lat' => (float) $lat, 'lng' => (float) $lng] : $fallback);
        var placed = @json($hasPoint);

        var map = L.map(el).setView([start.lat, start.lng], placed ? 16 : 11);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        var marker = null;

        function inRegion(lat, lng) {
            return lat >= bounds.lat[0] && lat <= bounds.lat[1]
                && lng >= bounds.lng[0] && lng <= bounds.lng[1];
        }

        function place(lat, lng, announce) {
            if (!inRegion(lat, lng)) {
                // Refused here as well as server-side, so the mistake is
                // visible while the map is still in front of them.
                status.textContent = 'That point is outside the Davao Region — pick a spot inside it.';
                return;
            }

            latInput.value = lat.toFixed(6);
            lngInput.value = lng.toFixed(6);

            if (marker) {
                marker.setLatLng([lat, lng]);
            } else {
                marker = L.marker([lat, lng], { draggable: true }).addTo(map);
                marker.on('dragend', function () {
                    var p = marker.getLatLng();
                    place(p.lat, p.lng, true);
                });
            }

            clearBtn.hidden = false;
            status.textContent = announce || ('Pinned at ' + lat.toFixed(5) + ', ' + lng.toFixed(5) + '. Save to keep it.');
        }

        if (placed) {
            place(start.lat, start.lng, 'Pinned at ' + start.lat.toFixed(5) + ', ' + start.lng.toFixed(5) + '.');
        }

        map.on('click', function (e) { place(e.latlng.lat, e.latlng.lng); });

        clearBtn.addEventListener('click', function () {
            latInput.value = '';
            lngInput.value = '';
            if (marker) { map.removeLayer(marker); marker = null; }
            clearBtn.hidden = true;
            status.textContent = 'No pin set. Save to clear it.';
        });

        if (!navigator.geolocation) {
            locateBtn.disabled = true;
        } else {
            locateBtn.addEventListener('click', function () {
                locateBtn.disabled = true;
                status.textContent = 'Finding you…';
                navigator.geolocation.getCurrentPosition(
                    function (pos) {
                        locateBtn.disabled = false;
                        map.setView([pos.coords.latitude, pos.coords.longitude], 17);
                        place(pos.coords.latitude, pos.coords.longitude);
                    },
                    function () {
                        locateBtn.disabled = false;
                        status.textContent = 'Could not get your location — click the map instead.';
                    },
                    { timeout: 8000, maximumAge: 300000 }
                );
            });
        }

        // Leaflet mis-measures a map that was laid out inside a panel before
        // tiles loaded; this settles it once the page is done.
        setTimeout(function () { map.invalidateSize(); }, 200);
    })();
</script>
