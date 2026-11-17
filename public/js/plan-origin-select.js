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
    // ---- a first guess, so most visitors only press Continue
    // Nothing is asked of the browser: the time zone and the language it already sends are read, and only when the box is
    // still empty. The guess is shown and can be changed; nothing is recorded until the visitor confirms.
    const ZONES = {
        'Asia/Manila': 'PH', 'Asia/Tokyo': 'JP', 'Asia/Seoul': 'KR', 'Asia/Singapore': 'SG', 'Asia/Hong_Kong': 'HK', 'Asia/Macau': 'MO',
        'Asia/Shanghai': 'CN', 'Asia/Taipei': 'TW', 'Asia/Kuala_Lumpur': 'MY', 'Asia/Bangkok': 'TH', 'Asia/Jakarta': 'ID',
        'Asia/Ho_Chi_Minh': 'VN', 'Asia/Saigon': 'VN', 'Asia/Kolkata': 'IN', 'Asia/Calcutta': 'IN', 'Asia/Dubai': 'AE', 'Asia/Riyadh': 'SA',
        'Asia/Qatar': 'QA', 'Asia/Kuwait': 'KW', 'Asia/Phnom_Penh': 'KH', 'Asia/Vientiane': 'LA', 'Asia/Yangon': 'MM', 'Asia/Dhaka': 'BD',
        'Australia/Sydney': 'AU', 'Australia/Melbourne': 'AU', 'Australia/Brisbane': 'AU', 'Australia/Perth': 'AU', 'Australia/Adelaide': 'AU',
        'Pacific/Auckland': 'NZ', 'Europe/London': 'GB', 'Europe/Dublin': 'IE', 'Europe/Paris': 'FR', 'Europe/Berlin': 'DE', 'Europe/Madrid': 'ES',
        'Europe/Rome': 'IT', 'Europe/Amsterdam': 'NL', 'Europe/Zurich': 'CH', 'Europe/Stockholm': 'SE', 'Europe/Moscow': 'RU',
        'America/New_York': 'US', 'America/Chicago': 'US', 'America/Denver': 'US', 'America/Los_Angeles': 'US', 'America/Phoenix': 'US',
        'America/Toronto': 'CA', 'America/Vancouver': 'CA', 'Pacific/Honolulu': 'US', 'Pacific/Guam': 'GU'
    };
    const guessCountry = () => {
        let zone = '';
        try { zone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { /* the box stays empty */ }
        if (ZONES[zone]) return ZONES[zone];
        const tags = (navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language || '']);
        for (const tag of tags) {
            const region = (String(tag).split('-')[1] || '').toUpperCase();
            if (/^[A-Z]{2}$/.test(region)) return region;
        }
        return '';
    };
    const guessNote = document.querySelector('[data-origin-guess]');
    if (country.value === '' && !country.disabled) {
        const code = guessCountry();
        const option = code ? [...country.options].find(o => o.value === code) : null;
        if (option) {
            country.value = code;
            country.dispatchEvent(new Event('change', { bubbles: true }));
            if (guessNote) {
                guessNote.textContent = `We picked ${option.textContent.trim()} from your browser settings. Change it if that is not right.`;
                guessNote.hidden = false;
            }
        }
    }
    // Registered after the guess, so only a later choice by the visitor replaces the guess and hides its note.
    [country, province].forEach(control => control && control.addEventListener('change', () => { if (guessNote) guessNote.hidden = true; }));
    sync();
})();
