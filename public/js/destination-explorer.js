(() => {
    'use strict';
    const places = window.destinationExplorerData || [];
    const mapContainer = document.getElementById('destinationExplorerMap');
    if (!mapContainer) return;
    const status = document.getElementById('destinationMapStatus');
    let map;
    const markers = new Map();

    const hasCoordinates = p => p.latitude !== null && p.longitude !== null && Number.isFinite(Number(p.latitude)) && Number.isFinite(Number(p.longitude)) && Math.abs(Number(p.latitude)) <= 90 && Math.abs(Number(p.longitude)) <= 180;
    function element(tag, text, className) {
        const node = document.createElement(tag);
        if (text) node.textContent = text;
        if (className) node.className = className;
        return node;
    }
    function initMap() {
        if (map) { map.invalidateSize(); return; }
        if (!window.L) { status.textContent = 'The map could not load. Switch to Grid to browse destination details.'; return; }
        map = L.map('destinationExplorerMap', {scrollWheelZoom:false}).setView([7.19,125.45],9);
        new ResizeObserver(() => map.invalidateSize()).observe(document.getElementById('destinationExplorerMap'));
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom:19, attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).on('tileerror', () => { status.textContent = 'Some map tiles could not load. Switch to Grid to browse destinations.'; }).addTo(map);
        places.filter(hasCoordinates).forEach(p => {
            const popup = element('div', null, 'explorer-popup');
            const link = element('a', 'View details →'); link.href = p.url;
            popup.append(element('strong', p.name), link);
            const marker = L.marker([Number(p.latitude),Number(p.longitude)], {title:p.name, alt:p.name}).addTo(map).bindPopup(popup);

            markers.set(p.id,marker);
        });
        if (markers.size) map.fitBounds(L.featureGroup([...markers.values()]).getBounds(), {padding:[30,30],maxZoom:13});
        if (!places.length) { status.textContent = 'No destinations match your filters. Try clearing a filter.'; return; }
        const missing = places.length - markers.size;
        status.textContent = `${markers.size} ${markers.size === 1 ? 'place' : 'places'} mapped.${missing ? ` ${missing} ${missing === 1 ? 'place has' : 'places have'} no usable coordinates and ${missing === 1 ? 'appears' : 'appear'} only in Grid view.` : ''}`;
    }
    function showView(view) {
        const isMap = view === 'map';
        document.getElementById('destinationMapView').hidden = !isMap;
        document.getElementById('destinationListView').hidden = isMap;
        document.getElementById('destinationViewInput').value = view;
        document.querySelectorAll('[data-destination-view]').forEach(button => button.setAttribute('aria-pressed',String(button.dataset.destinationView === view)));
        const url = new URL(location.href); url.searchParams.set('view',view); history.replaceState(null,'',url);
        // Category links retain the selected display when applying another filter.
        document.querySelectorAll('.catalog-categories a, #destinationListView .pagination a, .active-filters a, .filter-panel__clear').forEach(link => {
            const target = new URL(link.href); target.searchParams.set('view',view); link.href = target;
        });
        if (isMap) initMap();
    }
    document.querySelector('.destination-view-toggle').hidden = false;
    document.querySelectorAll('[data-destination-view]').forEach(button => button.onclick = () => showView(button.dataset.destinationView));
    showView(new URLSearchParams(location.search).get('view') === 'map' ? 'map' : 'grid');
})();
