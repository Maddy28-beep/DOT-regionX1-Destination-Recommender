@props(['regions'])

{{--
    Interactive locator for the About-the-Region section.

    Leaflet + OpenStreetMap tiles, both vendored locally -- no API key, no
    billing account, the same approach the itinerary route maps and the
    establishment location picker already use.

    It replaces a hand-drawn SVG. The illustration was accurate enough as
    decoration but could not be checked against anything, and a published map
    was the alternative -- which brings a licensing question and, in the case of
    the one we looked at, a province still labelled Compostela Valley seven
    years after it became Davao de Oro. This reads its region names and counts
    straight from the database, so it cannot fall out of step with the
    catalogue it describes.

    The <noscript> block keeps the section meaningful without JavaScript: the
    region pills beside it already link everywhere the pins do.
--}}
@if (!empty($regions))
    @once
        <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
        <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
    @endonce

    <div id="region-map" class="region-map" role="img"
         aria-label="Map of the Davao Region showing accredited listings in each province"></div>

    <noscript>
        <p class="region-map__fallback">
            The interactive map needs JavaScript. The region links above go to the same places.
        </p>
    </noscript>

    <script>
        (function () {
            var regions = {!! Illuminate\Support\Js::from($regions) !!};

            function init() {
                var el = document.getElementById('region-map');
                if (!el || !window.L) return;

                var map = L.map(el, { scrollWheelZoom: false }).setView([7.1, 125.8], 8);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 18,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(map);

                var bounds = [];
                regions.forEach(function (r) {
                    /*
                     * A circle sized by how much is accredited there, not a pin.
                     * Davao City holds 244 listings against Davao Occidental's 1;
                     * identical markers would flatten a 200-fold difference that is
                     * the single most useful thing this map can show. Square-rooted
                     * so the area rather than the radius tracks the count.
                     */
                    var radius = 8 + Math.sqrt(r.total) * 1.6;

                    var marker = L.circleMarker([r.lat, r.lng], {
                        radius: radius,
                        color: '#0b6b4f',
                        weight: 2,
                        fillColor: '#12836f',
                        fillOpacity: .45
                    }).addTo(map);

                    var html = '<div class="region-pop">'
                        + '<strong>' + escapeHtml(r.name) + '</strong>'
                        + '<div class="region-pop__total">' + r.total + ' accredited listing'
                        + (r.total === 1 ? '' : 's') + '</div>';

                    r.links.forEach(function (l) {
                        html += '<a class="region-pop__link" href="' + l.url + '">'
                             + escapeHtml(l.label) + ' <span>' + l.count + '</span></a>';
                    });

                    if (r.approximate) {
                        // Say so rather than imply the pin is surveyed. This region
                        // has no listing with coordinates, so it sits on its
                        // provincial capital.
                        html += '<div class="region-pop__note">Pin shows the provincial capital &mdash;'
                             + ' we do not hold coordinates for this area yet.</div>';
                    }

                    marker.bindPopup(html + '</div>');
                    marker.bindTooltip(r.name, { direction: 'top' });
                    bounds.push([r.lat, r.lng]);
                });

                if (bounds.length) {
                    /*
                     * invalidateSize() first, and fit on the next frame.
                     * fitBounds measures the container to decide the zoom, and
                     * this map sits inside a <figure> that has not always been
                     * laid out when the script runs -- against a 0x0 viewport
                     * Leaflet concluded everything fit and clamped to zoom 18,
                     * i.e. a street corner instead of the region.
                     *
                     * maxZoom is a floor under that whole class of bug: even if
                     * the measurement is wrong again, the map can never land
                     * closer than provincial scale.
                     */
                    requestAnimationFrame(function () {
                        map.invalidateSize();
                        map.fitBounds(bounds, { padding: [36, 36], maxZoom: 9 });
                    });
                }

                // The section can be scrolled past before the map has laid out;
                // re-measuring on resize keeps the tiles from tearing.
                window.addEventListener('resize', function () { map.invalidateSize(); });
            }

            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        })();
    </script>
@endif
