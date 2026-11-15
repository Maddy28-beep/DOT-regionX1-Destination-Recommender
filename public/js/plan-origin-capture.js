(() => {
    'use strict';
    const button = document.querySelector('[data-confirm-origin]');
    const status = document.querySelector('[data-confirm-origin-status]');
    if (!button || !status) return;
    let pending;
    window.confirmVisitorOrigin = () => {
        if (pending) return pending;
        const form = button.closest('form');
        const fields = ['visitor_origin_country', 'visitor_origin_province', 'visitor_origin_city']
            .map(name => form.elements.namedItem(name));
        const bad = fields.find(field => field && !field.checkValidity());
        if (bad) { bad.reportValidity(); return Promise.resolve(false); }
        const body = new FormData();
        body.set('_token', form.elements.namedItem('_token').value);
        fields.forEach(field => { if (field) body.set(field.name, field.value); });
        button.disabled = true;
        status.textContent = 'Confirming your origin...';
        pending = fetch(button.dataset.confirmUrl, {
            method: 'POST', credentials: 'same-origin', headers: {Accept: 'application/json'}, body
        }).then(async response => {
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                status.textContent = Object.values(data.errors || {}).flat().join(' ') ||
                    (response.status === 419 ? 'Your session expired. Refresh the page and try again.' : 'Origin was not recorded. Please try again.');
                return false;
            }
            status.textContent = data.message;
            return true;
        }).catch(() => {
            status.textContent = 'Origin was not recorded. Check your connection and try again.';
            return false;
        }).finally(() => { pending = null; button.disabled = false; });
        return pending;
    };
    button.addEventListener('click', async () => {
        if (await window.confirmVisitorOrigin()) {
            document.getElementById('start_at')?.focus();
        }
    });
})();
