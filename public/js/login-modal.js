/* The sign-in modal, opened by the heart on a place when the visitor is not signed in.

   - A tap on a heart form marked data-login-required opens the modal instead of saving to the browser token. (Without this
     script the form still posts and saves as before.)
   - The modal's two stylesheets are fetched the first time it opens, and it is shown once they have arrived, so the card never
     appears unstyled.
   - The form posts to the Sign In route over fetch. A wrong alias or password is shown in the modal; a success reloads the
     page, which then shows the visitor signed in, the place saved (the server saves it with the sign-in) and its message.
   - Escape, the close button and a tap on the dim area close it; focus is kept inside it and goes back to the heart. */
(function () {
    'use strict';

    var modal = document.querySelector('[data-login-modal]');
    if (!modal) return;

    var dialog = modal.querySelector('[role="dialog"]');
    var form = modal.querySelector('[data-login-form]');
    var errorBox = modal.querySelector('[data-login-error]');
    var context = modal.querySelector('[data-login-context]');
    var submit = modal.querySelector('[data-login-submit]');
    var aliasInput = form.querySelector('[name="alias"]');
    var passwordInput = form.querySelector('[name="password"]');
    var saveType = form.querySelector('[name="save_type"]');
    var saveId = form.querySelector('[name="save_id"]');
    var DEFAULT_CONTEXT = context ? context.textContent : '';
    var opener = null;
    var stylesReady = null;

    function loadStyles() {
        if (stylesReady) return stylesReady;
        var urls = [];
        try { urls = JSON.parse(modal.getAttribute('data-styles') || '[]'); } catch (e) { urls = []; }
        stylesReady = Promise.all(urls.map(function (href) {
            return new Promise(function (resolve) {
                if (document.querySelector('link[href="' + href + '"]')) { resolve(); return; }
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = href;
                link.onload = link.onerror = function () { resolve(); };
                document.head.appendChild(link);
            });
        }));
        return stylesReady;
    }

    function setError(message) {
        errorBox.textContent = message || '';
        errorBox.hidden = !message;
    }

    function focusables() {
        return [].slice.call(dialog.querySelectorAll('a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled])'))
            .filter(function (el) { return el.offsetParent !== null; });
    }

    function open(heartForm, trigger) {
        opener = trigger || document.activeElement;
        saveType.value = heartForm.getAttribute('data-save-type') || '';
        saveId.value = heartForm.getAttribute('data-save-id') || '';
        var name = heartForm.getAttribute('data-save-name');
        if (context) {
            context.textContent = name ? 'Sign in to save ' + name + ' to your favorites.' : DEFAULT_CONTEXT;
        }
        setError('');
        passwordInput.value = '';

        loadStyles().then(function () {
            modal.hidden = false;
            document.documentElement.classList.add('login-modal-open');
            (aliasInput.value ? passwordInput : aliasInput).focus();
        });
    }

    function close() {
        if (modal.hidden) return;
        modal.hidden = true;
        document.documentElement.classList.remove('login-modal-open');
        submit.disabled = false;
        submit.textContent = 'Sign In';
        if (opener && document.contains(opener) && opener.focus) opener.focus();
        opener = null;
    }

    // Capture phase, so this runs before the page's own heart handler (which would save to the browser token).
    document.addEventListener('submit', function (event) {
        var heartForm = event.target;
        if (!heartForm || !heartForm.matches || !heartForm.matches('form[data-login-required]')) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        open(heartForm, event.submitter || heartForm.querySelector('button'));
    }, true);

    modal.addEventListener('click', function (event) {
        if (event.target.closest && event.target.closest('[data-login-close]')) close();
    });

    document.addEventListener('keydown', function (event) {
        if (modal.hidden) return;
        if (event.key === 'Escape') { event.preventDefault(); close(); return; }
        if (event.key !== 'Tab') return;
        var items = focusables();
        if (!items.length) return;
        var first = items[0], last = items[items.length - 1];
        if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog)) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (submit.disabled) return;
        if (!aliasInput.value.trim() || !passwordInput.value) {
            setError('Enter your alias and password.');
            (aliasInput.value.trim() ? passwordInput : aliasInput).focus();
            return;
        }
        setError('');
        submit.disabled = true;
        submit.textContent = 'Signing in…';

        fetch(form.getAttribute('action'), {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) { return { status: response.status, data: data }; });
        }).then(function (result) {
            if (result.status === 200 && result.data && result.data.ok) {
                submit.textContent = 'Signed in…';
                window.location.reload();
                return;
            }
            var message = 'Something went wrong. Please try again.';
            if (result.status === 429) message = 'Too many attempts. Wait a minute and try again.';
            else if (result.status === 419) message = 'This page has been open too long. Reload it and try again.';
            else if (result.data && result.data.errors) {
                var first = Object.keys(result.data.errors)[0];
                message = result.data.errors[first][0] || message;
            }
            setError(message);
            submit.disabled = false;
            submit.textContent = 'Sign In';
            passwordInput.value = '';
            passwordInput.focus();
        }).catch(function () {
            setError('Could not reach the site. Check your connection and try again.');
            submit.disabled = false;
            submit.textContent = 'Sign In';
        });
    });
})();
