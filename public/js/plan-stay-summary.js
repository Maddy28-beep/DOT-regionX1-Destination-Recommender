/* Read-only presentation of the existing budget, stay and resort-setting radios. */
(() => {
    'use strict';
    const form = document.getElementById('planPreferencesForm');
    const summary = form?.querySelector('[data-stay-summary]');
    if (!summary) return;
    const chosen = name => form.querySelector(`input[name="${name}"]:checked`);
    function render() {
        const budget = chosen('budget')?.value;
        const stay = chosen('accommodation_pref');
        const parts = [budget];
        if (stay) {
            const label = stay.closest('label');
            // The visible label keeps display names separate from the submitted values.
            parts.push(stay.value === 'Any' ? 'Any accommodation'
                : label.querySelector('.pc-stay-choice__name')?.textContent.trim() || stay.value);
            if (stay.value === 'Resort') {
                const setting = chosen('resort_setting');
                parts.push(setting?.closest('label').querySelector('span')?.textContent.trim() || 'Any resort');
            }
        }
        summary.querySelector('[data-stay-summary-text]').textContent = parts.filter(Boolean).join(' · ');
        summary.hidden = false;
    }
    form.addEventListener('change', event => {
        if (['budget', 'accommodation_pref', 'resort_setting'].includes(event.target.name)) render();
    });
    window.addEventListener('pageshow', render);
    render();
})();
