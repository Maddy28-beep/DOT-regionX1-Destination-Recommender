/*
 * Itinerary page: the Day 1 / Day 2 / ... tabs, and the map in the trip overview.
 *
 * Without JavaScript every day is listed one after the other and the tabs are plain links to them. With it,
 * the days become tabs and only one shows at a time (the one in the URL hash, else the first).
 * The map is drawn from the places in data-stops; everything from the data goes in with textContent, never as HTML.
 */
(function () {
    'use strict';

    var root = document.querySelector('.itin');
    if (!root) return;

    // ---- day tabs
    var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-day-tab]'));
    var panels = Array.prototype.slice.call(root.querySelectorAll('[data-day-panel]'));

    function showDay(day, updateHash) {
        var found = false;
        panels.forEach(function (panel) {
            var on = panel.getAttribute('data-day-panel') === String(day);
            panel.classList.toggle('is-active', on);
            if (on) found = true;
        });
        if (!found) return false;

        tabs.forEach(function (tab) {
            var on = tab.getAttribute('data-day-tab') === String(day);
            tab.classList.toggle('is-active', on);
            if (on) tab.setAttribute('aria-current', 'true'); else tab.removeAttribute('aria-current');
        });

        if (updateHash && window.history && history.replaceState) {
            history.replaceState(null, '', '#itinerary-day-' + day);
        }
        return true;
    }

    if (panels.length > 0) {
        root.classList.add('js-days');

        var fromHash = /^#itinerary-day-(\d+)$/.exec(location.hash || '');
        if (!(fromHash && showDay(fromHash[1], false))) {
            showDay(panels[0].getAttribute('data-day-panel'), false);
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function (event) {
                event.preventDefault();
                showDay(tab.getAttribute('data-day-tab'), true);
            });
        });

        window.addEventListener('hashchange', function () {
            var match = /^#itinerary-day-(\d+)$/.exec(location.hash || '');
            if (match) showDay(match[1], false);
        });
    }

    // ---- overview map
    var mapEl = document.getElementById('itinOverviewMap');
    if (!mapEl) return;

    var stops;
    try { stops = JSON.parse(mapEl.getAttribute('data-stops') || '[]'); } catch (e) { stops = []; }
    if (!stops.length) return;

    function withLeaflet(done) {
        if (window.L) { done(); return; }

        if (!document.querySelector('link[href*="leaflet.css"]')) {
            var css = document.createElement('link');
            css.rel = 'stylesheet';
            css.href = mapEl.getAttribute('data-leaflet-css');
            document.head.appendChild(css);
        }
        var script = document.createElement('script');
        script.src = mapEl.getAttribute('data-leaflet-js');
        script.onload = done;
        document.head.appendChild(script);
    }

    function drawMap() {
        var map = L.map(mapEl, { scrollWheelZoom: false });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        var points = [];
        stops.forEach(function (stop, index) {
            var icon = L.divIcon({ className: 'itin-pin', html: '<span>' + (index + 1) + '</span>', iconSize: [28, 28], iconAnchor: [14, 14] });
            var label = document.createElement('strong');
            label.textContent = (index + 1) + '. ' + stop.label;
            L.marker([stop.lat, stop.lng], { icon: icon, title: stop.label }).addTo(map).bindPopup(label);
            points.push([stop.lat, stop.lng]);
        });

        if (points.length > 1) {
            L.polyline(points, { color: '#0b6b4f', weight: 3, opacity: .55, dashArray: '6 8' }).addTo(map);
            map.fitBounds(points, { padding: [26, 26], maxZoom: 13 });
        } else {
            map.setView(points[0], 12);
        }
    }

    // After the page has loaded, so the route maps lower down (which load Leaflet themselves) go first and it is not loaded twice.
    function start() { withLeaflet(drawMap); }
    if (document.readyState === 'complete') start(); else window.addEventListener('load', start);
})();
