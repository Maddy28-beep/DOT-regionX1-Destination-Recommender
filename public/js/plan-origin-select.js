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

    // Home origin never determines the device's current location or changes a chosen pick-up.

    // Visitors travelling from outside Davao Region are offered the airport as a one-tap pick-up. It is never filled in for them.
    const originField = country.closest('[data-origin-field]');
    const regionProvinces = (() => { try { return JSON.parse(originField?.dataset.regionXiProvinces || '[]'); } catch (e) { return []; } })();
    const suggestion = document.querySelector('[data-suggest-airport]');
    const pickup = document.getElementById('origin_label');
    const airport = document.querySelector('[data-start-pick="Davao International Airport"]');
    const suggest = () => {
        if (!suggestion) return;
        const value = country.value;
        const outside = value !== '' && value !== 'NONE' && (value !== 'PH' || (province && province.value !== '' && !regionProvinces.includes(province.value)));
        suggestion.hidden = !(outside && airport && pickup && pickup.value.trim() === '');
    };
    suggestion?.querySelector('[data-suggest-airport-use]')?.addEventListener('click', () => { airport?.click(); suggest(); });
    pickup?.addEventListener('input', suggest);

    const sync = () => {
        suggest();
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
    // ---- two plain choices: Philippines, or another country (then the country list)
    // The select stays the one real field; the choices only set it or show it. Without this script the select is the whole question.
    const choice = document.querySelector('[data-origin-choice]');
    if (choice) {
        const picks = [...choice.querySelectorAll('[data-origin-pick]')];
        let other = country.value !== '' && country.value !== 'PH';
        const paint = () => {
            const state = country.value === 'PH' ? 'PH' : (other ? 'OTHER' : '');
            picks.forEach(button => button.setAttribute('aria-checked', String(button.dataset.originPick === state)));
            // The country list is only needed for "Another country"; for Philippines the province is what is asked next.
            // The select and the shared dropdown built around it are hidden and shown together (a hidden select hides its dropdown).
            country.hidden = state !== 'OTHER';
            const built = country.closest('.site-dropdown');
            if (built) built.hidden = state !== 'OTHER';
        };
        const choose = kind => {
            if (kind === 'PH') {
                other = false;
                country.value = 'PH';
            } else {
                other = true;
                if (country.value === 'PH') country.value = '';
            }
            country.dispatchEvent(new Event('change', { bubbles: true }));
            paint();
            if (kind === 'OTHER') { const control = (country.closest('.site-dropdown') || country).querySelector('button, select') || country; control.focus?.(); }
        };
        picks.forEach(button => button.addEventListener('click', () => choose(button.dataset.originPick)));
        country.addEventListener('change', paint);
        choice.hidden = false;
        paint();
        // The app builds the shared dropdown around the select after this file has run: paint again once it is there.
        window.addEventListener('load', paint);
        // Continue with neither choice made: say so, instead of the browser pointing at a field that is hidden.
        const go = document.querySelector('[data-confirm-origin]');
        go?.addEventListener('click', event => {
            if (country.value !== '') return;
            event.stopImmediatePropagation();
            const note = document.querySelector('[data-confirm-origin-status]');
            if (note) note.textContent = 'Choose Philippines or another country first.';
            choice.querySelector('[data-origin-pick]')?.focus();
        }, true);
    }

    sync();
})();
