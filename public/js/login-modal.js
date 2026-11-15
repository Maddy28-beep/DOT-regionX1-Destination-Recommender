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
    var signinPane = modal.querySelector('[data-signin-pane]');
    var signupPane = modal.querySelector('[data-signup-pane]');
    var signupForm = modal.querySelector('[data-signup-form]');
    var signupError = modal.querySelector('[data-signup-error]');
    var signupContext = modal.querySelector('[data-signup-context]');
    var signupSubmit = modal.querySelector('[data-signup-submit]');
    var SIGNUP_DEFAULT_CONTEXT = signupContext ? signupContext.textContent : '';
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

    /* The card's two stylesheets and its photo are fetched ahead of the first tap: once the page has settled, and again the
       moment a heart or a "Log in" link is touched or pointed at, so the card does not wait on them when it is asked for. */
    function prefetch() {
        loadStyles();
        modal.querySelectorAll('img[loading="lazy"]').forEach(function (img) { img.loading = 'eager'; });
    }
    function prefetchWhenIdle() {
        var go = function () { prefetch(); };
        if ('requestIdleCallback' in window) window.requestIdleCallback(go, { timeout: 4000 }); else window.setTimeout(go, 2500);
    }
    if (document.readyState === 'complete') prefetchWhenIdle(); else window.addEventListener('load', prefetchWhenIdle, { once: true });
    ['pointerover', 'touchstart', 'focusin'].forEach(function (name) {
        document.addEventListener(name, function (event) {
            if (event.target.closest && event.target.closest('form[data-login-required], [data-login-open]')) prefetch();
        }, { passive: true });
    });

    function setError(message) {
        errorBox.textContent = message || '';
        errorBox.hidden = !message;
    }

    function focusables() {
        return [].slice.call(dialog.querySelectorAll('a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled])'))
            .filter(function (el) { return el.offsetParent !== null; });
    }

    function setSignupError(message) {
        if (!signupError) return;
        signupError.textContent = message || '';
        signupError.hidden = !message;
    }

    /* The card turns between its two sides in place: Sign In or Sign Up (the heading the dialog is named by follows). */
    function showPane(which) {
        if (!signupPane) return;
        var up = which === 'signup';
        signinPane.hidden = up;
        signupPane.hidden = !up;
        dialog.setAttribute('aria-labelledby', up ? 'signupModalTitle' : 'loginModalTitle');
        // The photo side says what suits the side that is showing (the words are those of the Sign In and Create Account pages).
        var headline = modal.querySelector('.portal-poster__headline');
        var copy = modal.querySelector('.portal-poster__copy');
        if (headline && copy) {
            if (!headline.dataset.signin) { headline.dataset.signin = headline.textContent; copy.dataset.signin = copy.textContent; }
            headline.textContent = up ? 'Keep your trips, skip the paperwork.' : headline.dataset.signin;
            copy.textContent = up ? 'Pick an alias and a password. Your itineraries and saved places follow you across visits and devices.' : copy.dataset.signin;
        }
        if (!modal.hidden) {
            var field = up ? signupForm.querySelector('[name="alias"]') : (aliasInput.value ? passwordInput : aliasInput);
            if (field) field.focus();
        }
    }

    function open(heartForm, trigger) {
        opener = trigger || document.activeElement;
        // heartForm is null when the visitor opened the card from a "Log in" link instead of a heart: nothing to save then.
        saveType.value = heartForm ? (heartForm.getAttribute('data-save-type') || '') : '';
        saveId.value = heartForm ? (heartForm.getAttribute('data-save-id') || '') : '';
        var name = heartForm ? heartForm.getAttribute('data-save-name') : '';
        if (context) {
            context.textContent = name ? 'Sign in to save ' + name + ' to your favorites.' : (heartForm ? DEFAULT_CONTEXT : 'Sign in to pick up your saved places and itineraries.');
        }
        setError('');
        passwordInput.value = '';
        if (signupForm) {
            signupForm.reset();
            signupForm.querySelector('[name="save_type"]').value = saveType.value;
            signupForm.querySelector('[name="save_id"]').value = saveId.value;
            if (signupContext) signupContext.textContent = name ? 'Sign up to save ' + name + ' to your favorites.' : (heartForm ? SIGNUP_DEFAULT_CONTEXT : 'Create a free account to keep your saved places and itineraries.');
            setSignupError('');
        }
        showPane('signin');

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
        if (signupSubmit) { signupSubmit.disabled = false; signupSubmit.textContent = 'Sign Up'; }
        if (opener && document.contains(opener) && opener.focus) opener.focus();
        opener = null;
    }

    // Capture phase, so this runs before the page's own heart handler (which would save to the browser token).
    document.addEventListener('submit', function (event) {
        var heartForm = event.target;
        if (!heartForm || !heartForm.matches || !heartForm.matches('form[data-login-required]')) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        var trigger = event.submitter || heartForm.querySelector('button');
        if (heartForm.dataset.teasing) return; // a second tap while the heart is still beating
        prefetch(); // the card's files arrive while the heart beats
        tease(heartForm, function () { open(heartForm, trigger); });
    }, true);

    /* The heart answers the tap before the sign-in card comes: it fills red, beats, rings and (on the card and square hearts)
       sends a few small hearts outward, then is put back as it was (nothing has been saved) and the card opens. Under
       reduced motion there is no wait. */
    var HEART_PATH = 'M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z';
    function tease(heartForm, done) {
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { done(); return; }

        var button = heartForm.querySelector('button');
        var svg = button && button.querySelector('svg');
        heartForm.dataset.teasing = '1';
        heartForm.classList.remove('save-form--pop');
        void heartForm.offsetWidth; // restart the beat if it was just played
        heartForm.classList.add('save-form--pop', 'save-form--teasing');
        var wasFilled = svg ? svg.getAttribute('fill') : null;
        if (svg) svg.setAttribute('fill', 'currentColor');

        if (heartForm.classList.contains('save-form--icon') || heartForm.classList.contains('save-form--square')) {
            [[-18, -26], [0, -34], [18, -26], [-10, -18], [10, -18], [0, -22]].forEach(function (offset) {
                var particle = document.createElement('span');
                particle.className = 'save-heart-particle';
                particle.style.setProperty('--particle-end', 'translate(' + offset[0] + 'px, ' + offset[1] + 'px) scale(1)');
                particle.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="' + HEART_PATH + '"/></svg>';
                heartForm.appendChild(particle);
                particle.addEventListener('animationend', function () { particle.remove(); });
            });
        }

        window.setTimeout(function () {
            heartForm.classList.remove('save-form--pop', 'save-form--teasing');
            delete heartForm.dataset.teasing;
            if (svg) { if (wasFilled === null) svg.removeAttribute('fill'); else svg.setAttribute('fill', wasFilled); }
            done();
        }, 620);
    }

    modal.addEventListener('click', function (event) {
        if (event.target.closest && event.target.closest('[data-login-close]')) close();
    });

    // A "Log in" link marked data-login-open (the top bar's, the phone menu's) opens the card too; without this script it is an ordinary link to the Sign In page.
    document.addEventListener('click', function (event) {
        var link = event.target.closest && event.target.closest('[data-login-open]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        var menuClose = link.closest('.mobile-menu') && document.getElementById('mobileMenuClose');
        if (menuClose) menuClose.click(); // the phone menu steps aside first
        open(null, link);
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
                window.dispatchEvent(new CustomEvent('app:navigating', { detail: { label: 'Signing you in\u2026' } }));
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

    // "Sign Up" under the sign-in form (and "Sign In" under the sign-up form) turns the card instead of leaving the page.
    modal.addEventListener('click', function (event) {
        var link = event.target.closest && event.target.closest('[data-login-switch]');
        if (!link || !signupPane) return;
        event.preventDefault();
        showPane(link.getAttribute('data-login-switch'));
    });

    if (signupForm) {
        signupForm.addEventListener('submit', function (event) {
            event.preventDefault();
            if (signupSubmit.disabled) return;

            var alias = signupForm.querySelector('[name="alias"]');
            var password = signupForm.querySelector('[name="password"]');
            var confirm = signupForm.querySelector('[name="password_confirmation"]');
            var problem = '';
            var focus = null;
            if (!/^[A-Za-z0-9_-]{3,30}$/.test(alias.value.trim())) { problem = 'Pick an alias of 3 to 30 letters, numbers or dashes.'; focus = alias; }
            else if (password.value.length < 8) { problem = 'Your password needs at least 8 characters.'; focus = password; }
            else if (confirm.value !== password.value) { problem = 'The two passwords do not match.'; focus = confirm; }
            if (problem) { setSignupError(problem); focus.focus(); return; }

            setSignupError('');
            signupSubmit.disabled = true;
            signupSubmit.textContent = 'Signing up\u2026';

            fetch(signupForm.getAttribute('action'), {
                method: 'POST',
                body: new FormData(signupForm),
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (data) { return { status: response.status, data: data }; });
            }).then(function (result) {
                if (result.status === 200 && result.data && result.data.ok) {
                    signupSubmit.textContent = 'Welcome\u2026';
                    window.dispatchEvent(new CustomEvent('app:navigating', { detail: { label: 'Creating your account\u2026' } }));
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
                setSignupError(message);
                signupSubmit.disabled = false;
                signupSubmit.textContent = 'Sign Up';
            }).catch(function () {
                setSignupError('Could not reach the site. Check your connection and try again.');
                signupSubmit.disabled = false;
                signupSubmit.textContent = 'Sign Up';
            });
        });
    }
})();
