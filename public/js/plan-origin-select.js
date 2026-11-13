/*
 * Trip form, "Where are you travelling from?" (trip_location_v2): country, then a province for the Philippines, then a typed
 * city or municipality. "Prefer not to say" hides the rest. Without JavaScript all three boxes are plain controls and the
 * server accepts whatever is filled in (and ignores a province for any country but the Philippines), so none of this is
 * needed to submit the form.
 *
 * The province follows the country however the country changed: a mouse or touch pick ("change"), the keyboard or type-ahead
 * ("input", "keyup"), leaving the box ("focusout"), the browser restoring the form on Back or filling it in ("pageshow"),
 * and the moment the form is submitted. Listening to "change" alone left the province showing after some of these.
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

    ['change', 'input', 'keyup', 'focusout'].forEach(type => country.addEventListener(type, sync));
    window.addEventListener('pageshow', sync);
    if (country.form) country.form.addEventListener('submit', sync, true);
    sync();
})();
