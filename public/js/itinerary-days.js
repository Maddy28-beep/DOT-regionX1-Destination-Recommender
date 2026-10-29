/*
 * Itinerary page: the Day 1 / Day 2 / ... tabs, and the map beside them that follows the selected day.
 *
 * Without JavaScript every day is listed one after the other and the tabs are plain links to them. With it,
 * the days become tabs and only one shows at a time (the one in the URL hash, else the first).
 * The map is drawn from the places of the selected day in data-days; everything from the data goes in with
 * textContent, never as HTML.
 */
(function () {
    'use strict';

    var root = document.querySelector('.itin');
    if (!root) return;

    // ---- print / save as PDF: every day is printed, not only the tab that is showing (see the print rules in itinerary-redesign.css)
    root.querySelectorAll('[data-print]').forEach(function (button) {
        button.addEventListener('click', function () { window.print(); });
    });

    // ---- day tabs
    var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-day-tab]'));
    var panels = Array.prototype.slice.call(root.querySelectorAll('[data-day-panel]'));
    var currentDay = null;
    var onDayChange = null;

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
        currentDay = String(day);
        if (typeof onDayChange === 'function') onDayChange();
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

    // ---- the day's map
    var mapEl = document.getElementById('itinDayMap');
    if (!mapEl) return;

    var days, origin;
    try { days = JSON.parse(mapEl.getAttribute('data-days') || '{}'); } catch (e) { days = {}; }
    try { origin = JSON.parse(mapEl.getAttribute('data-origin') || 'null'); } catch (e) { origin = null; }

    var titleEl = document.getElementById('itinDayMapTitle');
    var metaEl = document.getElementById('itinDayMapMeta');
    var gmapsEl = document.getElementById('itinDayGmaps');
    var directionsEl = document.getElementById('itinDayDirections');
    var map = null;
    var layer = null;

    function plural(n, word) { return n + ' ' + word + (n === 1 ? '' : 's'); }

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

    // The card around the map: its title, its count, the Google Maps link and whether there are directions to open.
    function updateCard(day, info) {
        if (titleEl) titleEl.textContent = 'Day ' + day + ' on the map';
        if (metaEl) {
            var km = info.km > 0 ? ' · ' + (Math.round(info.km * 10) / 10) + ' km' : '';
            metaEl.textContent = plural(info.count, 'stop') + km;
        }
        if (gmapsEl) {
            if (info.gmaps) { gmapsEl.setAttribute('href', info.gmaps); gmapsEl.hidden = false; } else { gmapsEl.hidden = true; }
        }
        if (directionsEl) {
            var panel = root.querySelector('[data-day-panel="' + day + '"]');
            directionsEl.hidden = !(panel && panel.querySelector('details'));
        }
    }

    function draw() {
        if (!map || currentDay === null) return;
        var info = days[currentDay] || { stops: [], count: 0, km: 0, gmaps: null };
        updateCard(currentDay, info);

        layer.clearLayers();
        var points = [];

        // The first day starts from the traveller's own starting point: an orange dot, not numbered.
        if (origin && String(currentDay) === String(Object.keys(days)[0])) {
            var start = L.divIcon({ className: 'itin-pin itin-pin--origin', html: '<span></span>', iconSize: [18, 18], iconAnchor: [9, 9] });
            var startLabel = document.createElement('strong');
            startLabel.textContent = 'Start: ' + origin.label;
            L.marker([origin.lat, origin.lng], { icon: start, title: origin.label }).addTo(layer).bindPopup(startLabel);
            points.push([origin.lat, origin.lng]);
        }

        info.stops.forEach(function (stop, index) {
            var icon = L.divIcon({ className: 'itin-pin', html: '<span>' + (index + 1) + '</span>', iconSize: [28, 28], iconAnchor: [14, 14] });
            var label = document.createElement('strong');
            label.textContent = (index + 1) + '. ' + stop.label;
            L.marker([stop.lat, stop.lng], { icon: icon, title: stop.label }).addTo(layer).bindPopup(label);
            points.push([stop.lat, stop.lng]);
        });

        if (points.length > 1) {
            L.polyline(points, { color: '#0b6b4f', weight: 3, opacity: .55, dashArray: '6 8' }).addTo(layer);
            map.fitBounds(points, { padding: [26, 26], maxZoom: 14 });
        } else if (points.length === 1) {
            map.setView(points[0], 13);
        }
    }

    function drawMap() {
        map = L.map(mapEl, { scrollWheelZoom: false });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);
        layer = L.layerGroup().addTo(map);
        draw();
    }

    onDayChange = draw;
    if (currentDay !== null && days[currentDay]) updateCard(currentDay, days[currentDay]);

    // "Directions" opens the selected day's turn-by-turn route, which sits under its timeline.
    if (directionsEl) {
        directionsEl.addEventListener('click', function () {
            var panel = root.querySelector('[data-day-panel="' + currentDay + '"]');
            var details = panel && panel.querySelector('details');
            if (!details) return;
            details.open = true;
            details.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }

    // After the page has loaded, so the route maps lower down (which load Leaflet themselves) go first and it is not loaded twice.
    function start() { withLeaflet(drawMap); }
    if (document.readyState === 'complete') start(); else window.addEventListener('load', start);
})();
