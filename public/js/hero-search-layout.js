/* Presentation only. Existing hero-search.js owns values, validation and submitted controls. */
(() => {
    'use strict';
    const form = document.querySelector('[data-hero-search]');
    if (!form) return;
    const grid = form.querySelector('[data-search-filters]');
    const custom = grid.querySelector('[data-custom-budget]');
    function arrange(purpose) {
        const order = purpose === 'accommodations'
            ? ['where', 'guests', 'budget', 'from', 'to', 'meal', 'date', 'cuisine', 'interest', 'duration', 'travelers']
            : ['where', 'from', 'to', 'guests', 'meal', 'date', 'cuisine', 'interest', 'duration', 'travelers', 'budget', 'food'];
        // Move original controls so visual and keyboard order agree in every tab.
        order.forEach(kind => grid.querySelectorAll(`[data-for="${kind}"]`).forEach(box => grid.append(box)));
        if (custom) grid.append(custom);
    }

    const submitLabel = form.querySelector('[data-search-submit-label]');
    const labels = { destinations: 'Search destinations', accommodations: 'Search stays', restaurants: 'Search dining', packages: 'Search packages', souvenir_centers: 'Search souvenirs' };
    const updateLabel = () => {
        const selected = form.querySelector('[name="purpose"]:checked');
        if (selected) arrange(selected.value);
        if (submitLabel && selected) submitLabel.textContent = labels[selected.value];
    };
    form.addEventListener('change', updateLabel);
    form.addEventListener('hero-search:layout', updateLabel);
    window.addEventListener('pageshow', updateLabel);
    updateLabel();

    // Move one original statistics row: desktop places it after discovery; mobile retains the hero placement.
    const desktopLayout = window.matchMedia('(min-width: 1061px)');
    const statistics = document.querySelector('.home-discovery-stats');
    const heroLower = form.closest('.hero-lower');
    const discovery = document.querySelector('#known-for > .container');
    const placeStatistics = () => {
        if (!statistics || !heroLower) return;
        if (desktopLayout.matches && discovery) discovery.append(statistics);
        else heroLower.insertBefore(statistics, heroLower.querySelector('.hero-plan-cta'));
    };
    desktopLayout.addEventListener('change', placeStatistics);
    window.addEventListener('pageshow', placeStatistics);
    placeStatistics();

    const picker = form.querySelector('[data-guest-picker]');
    const summary = picker?.querySelector('summary');
    const close = () => { if (picker) picker.open = false; };
    form.querySelector('[data-guests-done]')?.addEventListener('click', () => { close(); summary.focus(); });
    form.addEventListener('keydown', event => {
        if (event.key === 'Escape' && picker?.open) { close(); summary.focus(); }
    });
    document.addEventListener('click', event => { if (picker && !picker.contains(event.target)) close(); });
    form.addEventListener('change', event => { if (event.target.name === 'purpose') close(); });
    form.querySelector('[data-search-reset]')?.addEventListener('click', close);

    // Visual feedback only: native validation and the existing budget validator run first.
    const loading = document.querySelector('[data-search-loading]');
    const loadingCopy = loading?.querySelector('#searchLoadingCopy');
    const audiences = { destinations: 'destinations', accommodations: 'stays', restaurants: 'dining options', packages: 'tour packages', souvenir_centers: 'souvenir shops' };
    document.addEventListener('submit', event => {
        if (event.target !== form || event.defaultPrevented || !loading?.showModal) return;
        const audience = audiences[form.querySelector('[name="purpose"]:checked')?.value] || 'places';
        loadingCopy.textContent = `Looking for ${audience} that match your preferences.`;
        close();
        if (!loading.open) loading.showModal();
        // Do not delay, intercept or resubmit the existing GET request.
    });
    const hideLoading = () => { if (loading?.open) loading.close(); };
    window.addEventListener('pageshow', hideLoading);
    window.addEventListener('pagehide', hideLoading);
})();
