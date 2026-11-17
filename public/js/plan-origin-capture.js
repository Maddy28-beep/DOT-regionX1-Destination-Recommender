(() => {
    'use strict';
    const button = document.querySelector('[data-confirm-origin]');
    const status = document.querySelector('[data-confirm-origin-status]');
    if (!button || !status) return;
    const root = document.getElementById('planWizard');
    const form = button.closest('form');
    const fields = ['visitor_origin_country', 'visitor_origin_province', 'visitor_origin_city']
        .map(name => form.elements.namedItem(name));
    const signature = () => JSON.stringify(fields.map(field => field?.value || ''));
    let pending;
    let confirmed = null;
    window.isVisitorOriginConfirmed = () => confirmed !== null && confirmed === signature();
    function invalidate() {
        if (confirmed === null || window.isVisitorOriginConfirmed()) return;
        confirmed = null;
        root?.setAttribute('data-origin-pending', '');
        status.textContent = 'Your origin changed. Confirm it again to continue.';
        document.dispatchEvent(new CustomEvent('visitor-origin-invalidated'));
    }
    fields.forEach(field => {
        field?.addEventListener('input', invalidate);
        field?.addEventListener('change', invalidate);
    });
    window.confirmVisitorOrigin = () => {
        invalidate();
        if (window.isVisitorOriginConfirmed()) return Promise.resolve(true);
        if (pending) return pending;
        const bad = fields.find(field => field && !field.disabled && !field.checkValidity());
        if (bad) { bad.reportValidity(); return Promise.resolve(false); }
        const requested = signature();
        const body = new FormData();
        body.set('_token', form.elements.namedItem('_token').value);
        fields.forEach(field => { if (field) body.set(field.name, field.value); });
        button.disabled = true;
        status.textContent = 'Confirming your origin...';
        pending = fetch(button.dataset.confirmUrl, {
            method: 'POST', credentials: 'same-origin', headers: {Accept: 'application/json'}, body
        }).then(async response => {
            const data = await response.json().catch(() => ({}));
            if (!response.ok || typeof data.message !== 'string' || !data.message.trim()) {
                status.textContent = Object.values(data.errors || {}).flat().join(' ') ||
                    (response.status === 419 ? 'Your session expired. Refresh the page and try again.' : 'We could not save that. Choose a country (and a province if it is the Philippines), then try again.');
                return false;
            }
            if (signature() !== requested) {
                status.textContent = 'Your origin changed while saving. Confirm it again to continue.';
                return false;
            }
            confirmed = requested;
            root?.removeAttribute('data-origin-pending');
            status.textContent = data.message || 'Origin confirmed. You can continue planning.';
            document.dispatchEvent(new CustomEvent('visitor-origin-confirmed'));
            return true;
        }).catch(() => {
            status.textContent = 'We could not save that. Check your connection and try again.';
            return false;
        }).finally(() => { pending = null; button.disabled = false; });
        return pending;
    };
    // Once the origin is answered the question steps aside: one line says what was chosen, with a way to change it. The fields
    // stay in the form (hidden), so the answer is still sent with the trip.
    const field = button.closest('[data-origin-field]');
    let summary = null;
    const choiceText = () => {
        const country = fields[0];
        const name = country ? (country.options[country.selectedIndex]?.textContent || '').trim() : '';
        return [name, fields[1]?.value, fields[2]?.value].map(part => (part || '').trim()).filter(Boolean).join(' · ');
    };
    const showSummary = () => {
        if (!field) return;
        if (!summary) {
            summary = document.createElement('p');
            summary.className = 'pc-origin-summary';
            summary.innerHTML = '<span>Travelling from <strong data-origin-summary-text></strong></span> <button type="button" data-origin-change>Change</button>';
            field.prepend(summary);
            summary.querySelector('[data-origin-change]').addEventListener('click', () => {
                field.classList.remove('is-origin-confirmed');
                summary.hidden = true;
                field.querySelector('[data-origin-pick]')?.focus();
            });
        }
        summary.querySelector('[data-origin-summary-text]').textContent = choiceText();
        summary.hidden = false;
        field.classList.add('is-origin-confirmed');
    };
    const hideSummary = () => { if (summary) summary.hidden = true; field?.classList.remove('is-origin-confirmed'); };
    document.addEventListener('visitor-origin-confirmed', showSummary);
    document.addEventListener('visitor-origin-invalidated', hideSummary);
    button.addEventListener('click', async () => {
        if (await window.confirmVisitorOrigin()) {
            showSummary();
            const start = document.getElementById('start_at');
            if (start && !start.closest('[hidden]')) start.focus();
        }
    });
})();
