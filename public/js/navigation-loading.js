/* Non-blocking feedback for public same-tab navigation. Native navigation stays in charge.
   A thin bar runs along the top of the screen at once; the "Loading ..." pill only appears if the page is slow (a fast page
   never flashes it), and says a little more if it is slower still. */
(() => {
    'use strict';
    const pill = document.querySelector('[data-navigation-loading]');
    if (!pill) return;
    const label = pill.querySelector('[data-navigation-loading-label]');
    const slow = pill.querySelector('[data-navigation-loading-slow]');
    const bar = document.querySelector('[data-navigation-progress]');
    const PILL_AFTER = 500;   // ms before the pill is shown
    const SLOW_AFTER = 3500;  // ms before it says "taking a little longer"
    let pillTimer = 0;
    let slowTimer = 0;

    const hide = () => {
        window.clearTimeout(pillTimer);
        window.clearTimeout(slowTimer);
        pill.hidden = true;
        if (slow) slow.hidden = true;
        if (bar) { bar.hidden = true; bar.classList.remove('is-running'); }
    };
    const show = url => {
        const section = url.pathname.split('/').filter(Boolean)[0];
        const names = { destinations: 'destinations', accommodations: 'stays', restaurants: 'dining', packages: 'tour packages', 'souvenir-centers': 'souvenir centers', 'tour-operators': 'tour operators', events: 'events', advisories: 'advisories', plan: 'trip planner', saved: 'saved places' };
        start(`Loading ${names[section] || 'page'}…`);
    };
    const start = text => {
        label.textContent = text;

        hide();
        if (bar) {
            bar.hidden = false;
            bar.classList.remove('is-running');
            void bar.offsetWidth; // start from the left again
            bar.classList.add('is-running');
        }
        pillTimer = window.setTimeout(() => { pill.hidden = false; }, PILL_AFTER);
        slowTimer = window.setTimeout(() => { if (slow) slow.hidden = false; }, SLOW_AFTER);
    };
    document.addEventListener('click', event => {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest('a[href]');
        if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
        const url = new URL(link.href, location.href);
        if (url.origin !== location.origin || !['http:', 'https:'].includes(url.protocol)) return;
        if (url.pathname === location.pathname && url.search === location.search) return;
        // A "Log in" link that opens the sign-in card is not a page change.
        if (link.hasAttribute('data-login-open')) return;
        // Not a microtask: those run between one listener and the next, before a later listener (the sign-in card's own) has had
        // the chance to cancel the navigation. A timer runs after all of them, so defaultPrevented can be trusted.
        window.setTimeout(() => { if (!event.defaultPrevented) show(url); }, 0);
    });
    document.addEventListener('submit', event => {
        const form = event.target;
        // Search already has its own modal. POST actions are not page browsing.
        if (!(form instanceof HTMLFormElement) || form.matches('[data-hero-search]') || form.method.toLowerCase() !== 'get' || (form.target && form.target !== '_self')) return;
        const url = new URL(form.action, location.href);
        if (url.origin !== location.origin) return;
        window.setTimeout(() => { if (!event.defaultPrevented) show(url); }, 0);
    });
    // A form that posts and then sends the visitor on (sign in, sign up, request an account) shows that it is working: the
    // top bar runs, and its button says what it is doing and cannot be pressed twice. The server takes a moment to check a
    // password, and without this the page just sat still.
    const idle = form => {
        form.classList.remove('is-busy');
        form.querySelectorAll('.is-busy').forEach(button => {
            button.classList.remove('is-busy');
            button.removeAttribute('aria-busy');
            button.disabled = false;
            if (button.dataset.idleLabel) { button.textContent = button.dataset.idleLabel; delete button.dataset.idleLabel; }
        });
    };
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-busy-submit]')) return;
        window.setTimeout(() => {
            if (event.defaultPrevented) return;
            form.classList.add('is-busy');
            start(form.dataset.busyLabel || 'Please wait…');
            const button = event.submitter || form.querySelector('[type="submit"]');
            if (button) {
                button.classList.add('is-busy');
                button.setAttribute('aria-busy', 'true');
                if (button.dataset.busyLabel) { button.dataset.idleLabel = button.textContent; button.textContent = button.dataset.busyLabel; }
                // The form's data was taken when it was submitted, so the button can be shut now.
                button.disabled = true;
            }
        }, 0);
    });
    // The sign-in card (public/js/login-modal.js) says it has signed someone in and the page is about to reload.
    window.addEventListener('app:navigating', event => start((event.detail && event.detail.label) || 'Loading page…'));
    window.addEventListener('pageshow', () => { hide(); document.querySelectorAll('form.is-busy').forEach(idle); });
    window.addEventListener('pagehide', hide);
})();
