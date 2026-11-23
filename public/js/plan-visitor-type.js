(() => {
    'use strict';
    const group = document.querySelector('[data-visitor-types]');
    const country = document.getElementById('visitor_origin_country');
    const province = document.querySelector('[data-origin-province]');
    const resident = group?.querySelector('[data-resident-choice]');
    if (!group || !country || !resident) return;
    const card = resident.closest('[data-resident-option]');
    const grid = group.querySelector('[data-visitor-type-grid]');
    const hint = group.querySelector('[data-visitor-type-hint]');
    let provinces = [];
    try { provinces = JSON.parse(country.closest('[data-origin-v2]')?.dataset.regionXiProvinces || '[]'); } catch (_) { /* fail closed */ }
    const sync = () => {
        const eligible = country.value === 'PH' && provinces.includes(province?.value);
        const cleared = !eligible && resident.checked;
        resident.disabled = !eligible;
        if (cleared) resident.checked = false;
        card.hidden = !eligible;
        card.style.display = eligible ? '' : 'none';
        grid.style.setProperty('--visitor-columns', eligible ? 3 : 2);
        hint.textContent = eligible
            ? 'Choose the resident option only if you live in Davao Region.'
            : (cleared ? 'Your origin changed. Choose first-time or returning visitor.' : 'Visited Davao before, even many times? Choose Returning Visitor.');
        if (cleared) resident.dispatchEvent(new Event('change', {bubbles: true}));
    };
    [country, province].forEach(field => ['input', 'change'].forEach(event => field?.addEventListener(event, sync)));
    group.addEventListener('change', sync);
    window.addEventListener('pageshow', sync);
    document.addEventListener('visitor-origin-confirmed', sync);
    document.addEventListener('visitor-origin-invalidated', sync);
    group.closest('form')?.addEventListener('submit', sync, true);
    sync();
})();
