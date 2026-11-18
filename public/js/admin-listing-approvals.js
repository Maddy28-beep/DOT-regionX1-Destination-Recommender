/* Disclosure and focus only. Decisions keep their existing native POST forms. */
(() => {
    'use strict';
    const page = document.querySelector('[data-listing-approvals]');
    if (!page) return;
    page.querySelectorAll('[data-la-toggle]').forEach(button => {
        const review = document.getElementById(button.getAttribute('aria-controls'));
        if (!review) return;
        button.hidden = false;
        button.addEventListener('click', () => {
            const expanded = button.getAttribute('aria-expanded') === 'true';
            review.hidden = expanded;
            button.setAttribute('aria-expanded', String(!expanded));
            button.querySelector('span').textContent = expanded ? 'View changes' : 'Hide changes';
        });
    });
    page.querySelectorAll('.la-return').forEach(details => {
        const note = details.querySelector('textarea[name="note"]');
        const cancel = details.querySelector('[data-la-cancel]');
        if (!note || !cancel) return;
        cancel.hidden = false;
        details.addEventListener('toggle', () => {
            if (details.open && !note.hasAttribute('aria-invalid')) note.focus({ preventScroll: true });
        });
        cancel.addEventListener('click', () => {
            details.open = false;
            details.querySelector('summary').focus({ preventScroll: true });
        });
        if (note.getAttribute('aria-invalid') === 'true') note.focus();
    });
})();
