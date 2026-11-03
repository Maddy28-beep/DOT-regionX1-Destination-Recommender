/*
 * Trip form, "Leaving": the departure-point buttons.
 *
 * They fill the "Leaving from" box and its two hidden coordinates, the same way the starting-point buttons do.
 * "Same as where I start" copies the starting point only when the traveller taps it: nothing is ever filled in for
 * them. Without JavaScript the buttons stay hidden and a typed place is looked up when the form is submitted.
 */
(() => {
    'use strict';

    const label = document.getElementById('departure_label');
    const lat = document.getElementById('departure_lat');
    const lng = document.getElementById('departure_lng');
    const points = document.querySelector('[data-departure-points]');
    if (!label || !points) return;

    points.hidden = false;

    points.querySelectorAll('[data-departure-pick]').forEach(button => button.addEventListener('click', () => {
        label.value = button.dataset.departurePick;
        if (lat) lat.value = button.dataset.lat || '';
        if (lng) lng.value = button.dataset.lng || '';
    }));

    const same = points.querySelector('[data-departure-same]');
    if (same) {
        same.addEventListener('click', () => {
            const startLabel = document.getElementById('origin_label');
            const startLat = document.getElementById('origin_lat');
            const startLng = document.getElementById('origin_lng');
            if (!startLabel || !startLabel.value || !startLat || !startLat.value) {
                same.textContent = 'Set where you start first';
                return;
            }
            label.value = startLabel.value;
            if (lat) lat.value = startLat.value;
            if (lng) lng.value = startLng ? startLng.value : '';
            same.textContent = 'Same as where I start';
        });
    }

    // Typing a different place invalidates the tapped coordinates; the server then looks the typed place up.
    label.addEventListener('input', () => {
        if (lat) lat.value = '';
        if (lng) lng.value = '';
    });
})();
