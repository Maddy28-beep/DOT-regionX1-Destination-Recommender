@props(['listings', 'id' => 'results-map'])

{{--
    Multi-marker map for a listing grid (Destinations, and any other index
    page that adopts it later) -- Leaflet + OpenStreetMap tiles, the same
    vendored, no-API-key approach region-map.blade.php already uses for the
    homepage locator. This one plots individual listings on the current page
    rather than one circle per region.

    Only listings with real coordinates get a marker. A listing without one
    is silently absent from the map rather than guessed onto it -- the same
    "unknown means cannot judge, not nearby" rule the recommender itself
    follows -- but is still counted for the caller so the empty-state note
    below can say so honestly instead of the map just looking sparse for no
    stated reason.
--}}

@php
    $mappable = $listings->filter(fn ($listing) => $listing->latitude !== null && $listing->longitude !== null)->values();
    $unmappedCount = $listings->count() - $mappable->count();
@endphp

@once
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
@endonce

<div id="{{ $id }}" class="results-map" role="img" aria-label="Map of the listings shown on this page"></div>

@if ($unmappedCount > 0)
    <p class="results-map__note">
        {{ $unmappedCount }} of {{ $listings->count() }} listing{{ $listings->count() === 1 ? '' : 's' }} on this page
        {{ $unmappedCount === 1 ? "isn't" : "aren't" }} shown here &mdash; we don't hold coordinates for
        {{ $unmappedCount === 1 ? 'it' : 'them' }} yet. Switch to Grid View to see the full list.
    </p>
@endif

<script>
    (function () {
        var points = {!! Illuminate\Support\Js::from($mappable->map(fn ($listing) => [
            'lat' => (float) $listing->latitude,
            'lng' => (float) $listing->longitude,
            'name' => $listing->name,
            'meta' => $listing->posterMeta(),
            'url' => $listing->posterUrl(),
        ])) !!};

        function init() {
            var el = document.getElementById({{ Illuminate\Support\Js::from($id) }});
            if (!el || !window.L || el.dataset.mapInit) return;
            el.dataset.mapInit = '1';

            var map = L.map(el, { scrollWheelZoom: false }).setView([7.1, 125.8], 8);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 18,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);

            var bounds = [];
            points.forEach(function (p) {
                var marker = L.marker([p.lat, p.lng]).addTo(map);
                var html = '<div class="region-pop"><strong>' + escapeHtml(p.name) + '</strong>'
                    + '<div class="region-pop__total">' + escapeHtml(p.meta) + '</div>'
                    + '<a class="region-pop__link" href="' + p.url + '">View details</a></div>';
                marker.bindPopup(html);
                bounds.push([p.lat, p.lng]);
            });

            function fitToBounds() {
                map.invalidateSize();
                if (bounds.length === 1) {
                    map.setView(bounds[0], 13);
                } else if (bounds.length > 1) {
                    map.fitBounds(bounds, { padding: [36, 36], maxZoom: 14 });
                }
            }

            if (bounds.length) {
                requestAnimationFrame(fitToBounds);
            }

            window.addEventListener('resize', function () { map.invalidateSize(); });

            /*
             * The container starts hidden (Map View isn't the default panel),
             * so the fitToBounds() call above ran against a 0x0 box and every
             * marker's screen position was computed from that -- garbage
             * coordinates, not just a wrong zoom level. invalidateSize() alone
             * only makes Leaflet re-render tiles for the now-real size; it
             * does not know the earlier fitBounds/setView was meaningless and
             * so never recomputes where a marker actually belongs on screen.
             * Re-running fitToBounds() the first time the panel is actually
             * visible is what fixes the marker positions themselves, not just
             * the tiles under them.
             */
            var fitted = false;
            new IntersectionObserver(function (entries) {
                if (entries[0].isIntersecting && !fitted) {
                    fitted = true;
                    fitToBounds();
                }
            }, { threshold: 0.01 }).observe(el);
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
