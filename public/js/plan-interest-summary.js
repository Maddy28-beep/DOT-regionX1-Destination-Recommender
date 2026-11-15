/* Display the existing selections only. Never write controls, validation, drafts or planner inputs. */
(() => {
    'use strict';
    const form = document.getElementById('planPreferencesForm');
    // The box that listed the picks under the lists was taken out of the form; the "N selected" counts and the spoken status stay.
    const summary = form?.querySelector('[data-picks-summary]');
    if (!form) return;
    const status = form.querySelector('[data-selection-status]');
    const groups = ['activities', 'amenities'];
    function render(announce = false) {
        const counts = [];
        groups.forEach(group => {
            const selected = [...form.querySelectorAll(`input[name="${group}[]"]:checked`)].map(input => input.value);
            const badge = form.querySelector(`[data-selection-count="${group}"]`);
            badge.textContent = `${selected.length} selected`;
            badge.hidden = false;
            const text = summary?.querySelector(`[data-picks-text="${group}"]`);
            if (text) text.textContent = selected.length
                ? selected.join(', ')
                : group === 'activities' ? 'Choose at least one interest.' : 'None selected';
            const noun = group === 'activities'
                ? (selected.length === 1 ? 'interest' : 'interests')
                : (selected.length === 1 ? 'amenity' : 'amenities');
            counts.push(`${selected.length} ${noun} selected`);
        });
        if (summary) summary.hidden = false;
        if (announce && status) status.textContent = counts.join('. ');
    }
    form.addEventListener('change', event => {
        if (groups.some(group => event.target.name === `${group}[]`)) render(true);
    });
    // Browser history can restore checked controls without firing change.
    window.addEventListener('pageshow', () => render());
    render();
})();
