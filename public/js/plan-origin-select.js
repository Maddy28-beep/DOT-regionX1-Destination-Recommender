/*
 * Trip form, "Where are you travelling from?" (trip_location_v2): country, then a province for the Philippines, then a typed
 * city or municipality. "Prefer not to say" hides the rest. Without JavaScript all three boxes are plain controls and the
 * server accepts whatever is filled in, so none of this is needed to submit the form.
 */
(() => {
    'use strict';

    const country = document.getElementById('visitor_origin_country');
    const province = document.querySelector('[data-origin-province]');
    const city = document.querySelector('[data-origin-city]');
    if (!country) return;

    const sync = () => {
        const value = country.value;
        const isPh = value === 'PH';
        const answered = value !== '' && value !== 'NONE';
        if (province) {
            province.hidden = !isPh;
            if (!isPh) province.value = '';
        }
        if (city) {
            city.hidden = !answered;
            if (!answered) city.value = '';
        }
    };

    country.addEventListener('change', sync);
    sync();
})();
