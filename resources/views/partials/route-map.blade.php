{{--
    Turn-by-turn route guidance (Req. 2.2.1.6 / Sec. 2.2.3.1.5). Takes an ordered list of stops
    and draws the route + step-by-step directions using the free, keyless public OSRM routing
    server — the same "no API key, no billing account" approach already used for the embedded
    map (Leaflet + OpenStreetMap tiles instead of the Google Maps API, see §5.13 of the
    implementation notes).

    $stops: ordered array of ['lat' => float, 'lng' => float, 'label' => string], at least 2 entries.
--}}
@if (($stops ?? null) && count($stops) >= 2)
    @once
        <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
        <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
    @endonce
    @php
        $routeId = $mapId ?? ('route-'.\Illuminate\Support\Str::random(8));
    @endphp
    <div class="route-map-block">
        <div id="{{ $routeId }}" class="route-map" style="height:280px; border-radius:var(--radius-sm);"></div>
        <div id="{{ $routeId }}-summary" class="route-summary sub" style="margin-top:8px;">Calculating route&hellip;</div>
        <ol id="{{ $routeId }}-steps" class="route-steps"></ol>
    </div>
    <style>
        .route-steps { margin: 10px 0 0; padding-left: 20px; font-size: .82rem; }
        .route-steps li { padding: 4px 0; border-bottom: 1px dashed var(--border); }
        .route-steps li:last-child { border-bottom: none; }
        .route-steps .step-dist { color: var(--muted); font-size: .76rem; }
    </style>
    <script>
        (function () {
            var stops = {!! Illuminate\Support\Js::from(collect($stops)->map(fn($s) => ['lat' => (float) $s['lat'], 'lng' => (float) $s['lng'], 'label' => $s['label']])->all()) !!};

            function initRoute() {
                var mapEl = document.getElementById('{{ $routeId }}');
                if (!mapEl) return;
                var map = L.map(mapEl, { scrollWheelZoom: false }).setView([stops[0].lat, stops[0].lng], 11);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(map);

                var bounds = [];
                stops.forEach(function (s, i) {
                    var marker = L.marker([s.lat, s.lng]).addTo(map).bindPopup((i + 1) + '. ' + s.label);
                    bounds.push([s.lat, s.lng]);
                });
                map.fitBounds(bounds, { padding: [24, 24] });

                // This map commonly lives inside a collapsed <details> (display:none), which gives
                // Leaflet a 0x0 container to measure at init time and can leave the tiles/route
                // laid out incorrectly. Re-measure and re-center every time the panel is opened.
                var detailsEl = mapEl.closest('details');
                if (detailsEl) {
                    detailsEl.addEventListener('toggle', function () {
                        if (detailsEl.open) {
                            setTimeout(function () {
                                map.invalidateSize();
                                map.fitBounds(bounds, { padding: [24, 24] });
                            }, 50);
                        }
                    });
                }

                var coords = stops.map(function (s) { return s.lng + ',' + s.lat; }).join(';');
                var summaryEl = document.getElementById('{{ $routeId }}-summary');
                var stepsEl = document.getElementById('{{ $routeId }}-steps');

                fetch('https://router.project-osrm.org/route/v1/driving/' + coords + '?overview=full&geometries=geojson&steps=true')
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data.code !== 'Ok' || !data.routes || !data.routes.length) {
                            summaryEl.textContent = 'Turn-by-turn directions are not available for this route right now.';
                            return;
                        }
                        var route = data.routes[0];
                        var latlngs = route.geometry.coordinates.map(function (c) { return [c[1], c[0]]; });
                        L.polyline(latlngs, { color: '#0b6b4f', weight: 4, opacity: 0.8 }).addTo(map);

                        var km = (route.distance / 1000).toFixed(1);
                        var mins = Math.round(route.duration / 60);
                        summaryEl.textContent = 'Approx. ' + km + ' km · ' + mins + ' min driving time (via OpenStreetMap routing)';

                        var stepNum = 1;
                        route.legs.forEach(function (leg) {
                            leg.steps.forEach(function (step) {
                                var instruction = describeStep(step);
                                if (!instruction) return;
                                var li = document.createElement('li');
                                var distText = step.distance >= 1000
                                    ? (step.distance / 1000).toFixed(1) + ' km'
                                    : Math.round(step.distance) + ' m';
                                li.innerHTML = instruction + ' <span class="step-dist">(' + distText + ')</span>';
                                stepsEl.appendChild(li);
                                stepNum++;
                            });
                        });
                    })
                    .catch(function () {
                        summaryEl.textContent = 'Turn-by-turn directions are not available right now.';
                    });
            }

            function describeStep(step) {
                var type = step.maneuver.type;
                var name = step.name || 'the road';
                var modifier = step.maneuver.modifier;
                switch (type) {
                    case 'depart': return 'Head out toward ' + name;
                    case 'arrive': return 'Arrive at your destination';
                    case 'turn': return 'Turn ' + (modifier || '') + ' onto ' + name;
                    case 'new name': return 'Continue onto ' + name;
                    case 'merge': return 'Merge onto ' + name;
                    case 'roundabout': case 'rotary': return 'At the roundabout, continue onto ' + name;
                    case 'fork': return 'Keep ' + (modifier || '') + ' onto ' + name;
                    case 'continue': return 'Continue ' + (modifier || '') + ' onto ' + name;
                    default: return 'Continue onto ' + name;
                }
            }

            if (window.L) { initRoute(); } else { window.addEventListener('load', initRoute); }
        })();
    </script>
@endif
