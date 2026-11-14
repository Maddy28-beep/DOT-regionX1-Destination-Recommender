/*
 * Trip form, "Where are you travelling from?" (trip_location_v2): country, then a province for the Philippines, then a typed
 * city or municipality. "Prefer not to say" hides the rest. Without JavaScript all three boxes are plain controls and the
 * server accepts whatever is filled in (and ignores a province for any country but the Philippines), so none of this is
 * needed to submit the form.
 *
 * Two things make the province follow the country:
 *
 *  1. WHAT is hidden. The shared dropdown in app.js wraps every <select> in <span class="site-dropdown"> and shows its own
 *     button; the native select is only kept for the value. Hiding the select alone therefore left the "Province (optional)"
 *     button on screen for every country, and even before one was chosen. The wrapper is hidden with it (and a style rule in
 *     plan-trip-layout.css makes the hidden wrapper really disappear). The wrapper is built by app.js, which may run after this
 *     file, so the wrapper is looked for again whenever the box around the select changes.
 *  2. WHEN it is checked: a mouse or touch pick ("change"), the keyboard or type-ahead ("input", "keyup"), leaving the box
 *     ("focusout"), the browser restoring the form on Back or filling it in ("pageshow", "load"), and the moment the form is
 *     submitted.
 */
(() => {
    'use strict';

    const country = document.getElementById('visitor_origin_country');
    const province = document.querySelector('[data-origin-province]');
    const city = document.querySelector('[data-origin-city]');
    if (!country) return;

    /*
     * From outside Davao Region (another country, or a Philippine province outside Region XI) only "Use my current location"
     * is switched off, and the advisory shows. The pick-up box and the arrival buttons stay usable: the visitor types or picks
     * where they want to be picked up, and the server still refuses a place outside Davao Region. The pre-filled Davao
     * airport default is cleared (so nothing is chosen for them) and comes back if they are in Region XI again.
     */
    const field = country.closest('[data-origin-field]');
    const regionXi = (() => { try { return JSON.parse(field?.dataset.regionXiProvinces || '[]'); } catch (e) { return []; } })();
    const pickup = document.querySelector('.pc-trip-start');
    const note = pickup?.querySelector('[data-pickup-lock-note]');
    const locate = document.getElementById('origin-btn');
    let cleared = null;
    const lockPickup = () => {
        if (!pickup || !note || !regionXi.length) return;
        const chosen = country.value;
        const where = province ? province.value : '';
        // Not decided yet (no country, or the Philippines without a province): leave it alone, the form cannot be sent yet.
        if (chosen === '' || (chosen === 'PH' && where === '')) return;
        const outside = chosen !== 'PH' || !regionXi.includes(where);
        note.hidden = !outside;
        if (locate) locate.disabled = outside;
        const label = document.getElementById('origin_label');
        const lat = document.getElementById('origin_lat');
        const lng = document.getElementById('origin_lng');
        if (outside && cleared === null && label && label.value === 'Davao International Airport') {
            cleared = { label: label.value, lat: lat?.value || '', lng: lng?.value || '' };
            label.value = '';
            if (lat) lat.value = '';
            if (lng) lng.value = '';
            const status = document.getElementById('origin-status');
            if (status) status.textContent = '';
        } else if (!outside && cleared !== null) {
            if (label && label.value === '') { label.value = cleared.label; if (lat) lat.value = cleared.lat; if (lng) lng.value = cleared.lng; }
            cleared = null;
        }
    };

    const sync = () => {
        const value = country.value;
        const isPh = value === 'PH';
        const answered = value !== '';
        if (province) {
            const hadValue = province.value !== '';
            province.hidden = !isPh;
            province.required = isPh;
            // The visible control the shared dropdown built around the select, once it exists.
            const wrapper = province.closest('.site-dropdown');
            if (wrapper) wrapper.hidden = !isPh;
            if (!isPh && hadValue) {
                province.value = '';
                // Lets the shared dropdown show "Province (optional)" again instead of the province that was cleared.
                province.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        lockPickup();
        if (city) {
            city.hidden = !answered;
            if (!answered) city.value = '';
        }
    };

    ['change', 'input', 'keyup', 'focusout'].forEach(type => country.addEventListener(type, sync));
    if (province) ['change', 'input', 'keyup', 'focusout'].forEach(type => province.addEventListener(type, sync));
    window.addEventListener('pageshow', sync);
    window.addEventListener('load', sync);
    if (country.form) country.form.addEventListener('submit', sync, true);

    // app.js builds the wrapper after this file has run: look again when the box around the province select changes.
    if (province && province.parentElement && 'MutationObserver' in window) {
        new MutationObserver(sync).observe(province.closest('.site-dropdown')?.parentElement || province.parentElement, { childList: true });
    }
    sync();
})();
