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
    // A confirmed origin is remembered on this device for a day, so a refresh goes straight on to planning instead of asking again.
    // Only the place names are kept (country, province, city), the same ones the trip form holds; nothing is sent again.
    const storeKey = 'dvo-plan-origin-v1:' + (root?.dataset.draftOwner || 'guest');
    const KEEP_MS = 24 * 60 * 60 * 1000;
    const remember = () => {
        try { localStorage.setItem(storeKey, JSON.stringify({at: Date.now(), values: fields.map(field => field?.value || '')})); } catch (_) { /* nothing to remember */ }
    };
    const forget = () => { try { localStorage.removeItem(storeKey); } catch (_) { /* nothing to forget */ } };
    window.isVisitorOriginConfirmed = () => confirmed !== null && confirmed === signature();
    function invalidate() {
        if (confirmed === null || window.isVisitorOriginConfirmed()) return;
        confirmed = null;
        forget();
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
            remember();
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
            // Last, not first: the first screen's layout counts on the question's own label being the first child.
            field.append(summary);
            // Change goes back to the first screen with the answer filled in. Pressing Continue without changing it carries straight
            // on (nothing is sent again); changing it asks for a fresh confirmation.
            summary.querySelector('[data-origin-change]').addEventListener('click', () => {
                field.classList.remove('is-origin-confirmed');
                summary.hidden = true;
                status.textContent = '';
                root?.setAttribute('data-origin-pending', '');
                document.documentElement.classList.add('plan-origin-gating');
                document.dispatchEvent(new CustomEvent('visitor-origin-invalidated'));
                window.scrollTo({top: 0});
                field.querySelector('[data-origin-pick][aria-checked="true"], [data-origin-pick]')?.focus({preventScroll: true});
            });
        }
        summary.querySelector('[data-origin-summary-text]').textContent = choiceText();
        summary.hidden = false;
        field.classList.add('is-origin-confirmed');
    };
    const hideSummary = () => { if (summary) summary.hidden = true; field?.classList.remove('is-origin-confirmed'); };
    document.addEventListener('visitor-origin-confirmed', showSummary);
    // On load: put a remembered origin back (the choices, the province and the city), count it as confirmed and carry on.
    const restore = () => {
        let saved = null;
        try { saved = JSON.parse(localStorage.getItem(storeKey)); } catch (_) { saved = null; }
        const values = saved && Array.isArray(saved.values) && saved.values.length === 3 ? saved.values : null;
        // The page may already have dropped the question for a remembered origin; if it cannot be put back, ask again (and
        // tell the wizard, so a "welcome back" screen that was already showing steps aside).
        const regate = () => {
            if (root && !window.isVisitorOriginConfirmed()) {
                root.setAttribute('data-origin-pending', '');
                document.documentElement.classList.add('plan-origin-gating');
                document.dispatchEvent(new CustomEvent('visitor-origin-invalidated'));
            }
        };
        const fresh = values && saved.at && Date.now() - saved.at <= KEEP_MS && typeof values[0] === 'string' && values[0] !== '';
        if (!fresh) { if (values) forget(); regate(); return; }
        const [code, province, city] = values;
        if (fields[0].value !== '') {
            // The form already holds an origin (a saved trip): it counts only when it is the one that was confirmed.
            if (signature() !== JSON.stringify(values)) { regate(); return; }
        } else {
            const pick = field.querySelector('[data-origin-pick="' + (code === 'PH' ? 'PH' : 'OTHER') + '"]');
            pick?.click();
            if (code !== 'PH') { fields[0].value = code; fields[0].dispatchEvent(new Event('change', {bubbles: true})); }
            if (fields[1] && province) { fields[1].value = province; fields[1].dispatchEvent(new Event('change', {bubbles: true})); }
            if (fields[2] && city) { fields[2].value = city; fields[2].dispatchEvent(new Event('input', {bubbles: true})); }
            if (fields[0].value !== code) { forget(); regate(); return; }
        }
        confirmed = signature();
        root?.removeAttribute('data-origin-pending');
        document.documentElement.classList.remove('plan-origin-gating');
        document.dispatchEvent(new CustomEvent('visitor-origin-confirmed'));
    };
    // Nothing remembered: the question stays as the server drew it.
    // After every script of the page has run (the choices of the question are wired by plan-origin-select.js, which loads after this one).
    if (document.readyState === 'complete') restore(); else document.addEventListener('DOMContentLoaded', restore);
    document.addEventListener('visitor-origin-invalidated', hideSummary);
    button.addEventListener('click', async () => {
        if (await window.confirmVisitorOrigin()) {
            // Already confirmed (it was only being looked at again): leave the first screen without sending anything.
            if (root?.hasAttribute('data-origin-pending')) {
                root.removeAttribute('data-origin-pending');
                document.documentElement.classList.remove('plan-origin-gating');
                document.dispatchEvent(new CustomEvent('visitor-origin-confirmed'));
            }
            showSummary();
            const start = document.getElementById('start_at');
            if (start && !start.closest('[hidden]')) start.focus();
        }
    });
})();
