/*
 * Admin: every add / edit form in a modal -- listings, advisories, events and homepage slides.
 *
 * Every "Add" and "Edit" is a real link to the full-page form (marked data-modal-form), so without JavaScript nothing
 * changes. With it, the link opens the same form (the server sends just the form when asked with ?modal=1) in a <dialog>, posts it as JSON,
 * shows a validation error under its field, and on success goes back to the list, where the usual toast waits.
 *
 * The dialog is the browser's own, so Esc closes it, focus stays inside while it is open, and the page behind it can
 * not be tabbed into. A click on the dim backdrop closes it too, but never while something typed would be lost.
 */
(function () {
    'use strict';

    var dialog = document.getElementById('adminModal');
    if (!dialog || typeof dialog.showModal !== 'function') return;

    var body = document.getElementById('adminModalBody');
    var title = document.getElementById('adminModalTitle');
    var sub = document.getElementById('adminModalSub');
    var opener = null;
    var loadedValues = '';

    function snapshot() {
        var form = body.querySelector('form');
        return form ? new URLSearchParams(new FormData(form)).toString() : '';
    }
    function isDirty() { return snapshot() !== loadedValues; }

    function close() {
        dialog.close();
        body.textContent = '';
        if (opener && document.contains(opener)) opener.focus();
    }

    function clearErrors(form) {
        form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); el.removeAttribute('aria-invalid'); });
        form.querySelectorAll('.field-error').forEach(function (el) { el.remove(); });
        var summary = body.querySelector('.adm-modal__errors');
        if (summary) summary.remove();
    }

    // Laravel names a field "name" or "items.0.name"; the form's field is "name" or "items[]".
    function fieldFor(form, key) {
        var plain = key.split('.')[0];
        return form.querySelector('[name="' + plain + '"]') || form.querySelector('[name="' + plain + '[]"]');
    }

    function showErrors(form, errors) {
        clearErrors(form);
        var summary = document.createElement('div');
        summary.className = 'adm-modal__errors alert alert-error';
        summary.setAttribute('role', 'alert');
        var first = null;

        Object.keys(errors).forEach(function (key) {
            var message = errors[key][0];
            var line = document.createElement('div');
            line.textContent = message;
            summary.appendChild(line);

            var field = fieldFor(form, key);
            if (!field) return;
            field.classList.add('is-invalid');
            field.setAttribute('aria-invalid', 'true');
            var note = document.createElement('div');
            note.className = 'field-error';
            note.textContent = message;
            (field.closest('.field') || field.parentNode).appendChild(note);
            if (!first) first = field;
        });

        body.insertBefore(summary, body.firstChild);
        body.scrollTop = 0;
        if (first) first.focus({ preventScroll: false });
    }

    function submit(form) {
        var button = form.querySelector('button[type="submit"]');
        if (button) { button.disabled = true; button.dataset.label = button.textContent; button.textContent = 'Saving…'; }

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) { return { ok: response.ok, status: response.status, data: data }; });
        }).then(function (result) {
            if (result.ok && result.data.redirect) {
                loadedValues = snapshot();       // saved, so closing is not "losing" anything
                window.location.assign(result.data.redirect);
                return;
            }
            if (button) { button.disabled = false; button.textContent = button.dataset.label; }
            if (result.status === 422 && result.data.errors) { showErrors(form, result.data.errors); return; }
            showErrors(form, { form: [result.status === 419 ? 'Your session expired. Refresh the page and try again.' : 'We could not save this. Please try again.'] });
        }).catch(function () {
            if (button) { button.disabled = false; button.textContent = button.dataset.label; }
            showErrors(form, { form: ['Connection interrupted. Your answers are still here; check your connection and try again.'] });
        });
    }

    // A form can carry a little script of its own (the advisory's "applies to" list). Markup set with innerHTML does
    // not run scripts, so each one is replaced by a copy that does. They come from this site, never from a user.
    function runScripts(container) {
        container.querySelectorAll('script').forEach(function (old) {
            var copy = document.createElement('script');
            copy.textContent = old.textContent;
            old.replaceWith(copy);
        });
    }

    function open(link) {
        opener = link;
        title.textContent = link.dataset.title || 'Listing';
        sub.textContent = link.dataset.sub || '';
        body.innerHTML = '<p class="adm-modal__loading">Loading…</p>';
        dialog.showModal();

        var url = new URL(link.href, window.location.href);
        url.searchParams.set('modal', '1');
        fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (response) { if (!response.ok) throw new Error('HTTP ' + response.status); return response.text(); })
            .then(function (html) {
                body.innerHTML = html;
                runScripts(body);
                var form = body.querySelector('form');
                loadedValues = snapshot();
                var first = form && form.querySelector('input:not([type="hidden"]):not([type="checkbox"]), select, textarea');
                if (first) first.focus();
            })
            .catch(function () {
                // Nothing to show in the dialog: fall back to the page, which does the same job.
                dialog.close();
                window.location.assign(link.href);
            });
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('[data-modal-form]');
        if (link && !event.ctrlKey && !event.metaKey && !event.shiftKey) { event.preventDefault(); open(link); return; }
        if (event.target.closest('[data-modal-close]') && dialog.open) {
            event.preventDefault();
            if (isDirty() && !window.confirm('Close without saving? What you typed will be lost.')) return;
            close();
        }
    });

    body.addEventListener('submit', function (event) {
        event.preventDefault();
        submit(event.target);
    });

    // A click on the dim backdrop is a click on the dialog itself, not on anything inside it.
    dialog.addEventListener('click', function (event) {
        if (event.target !== dialog) return;
        if (isDirty()) {
            dialog.animate([{ transform: 'translateX(0)' }, { transform: 'translateX(-6px)' }, { transform: 'translateX(6px)' }, { transform: 'none' }], { duration: 220 });
            return;
        }
        close();
    });

    // Esc: the browser closes the dialog, so the opener gets its focus back and the form is cleared like any other close.
    dialog.addEventListener('close', function () {
        body.textContent = '';
        if (opener && document.contains(opener)) opener.focus();
    });
    dialog.addEventListener('cancel', function (event) {
        if (isDirty() && !window.confirm('Close without saving? What you typed will be lost.')) event.preventDefault();
    });
})();
