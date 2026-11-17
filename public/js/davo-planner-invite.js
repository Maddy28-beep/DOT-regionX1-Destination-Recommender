(function () {
    'use strict';
    var invite = document.querySelector('[data-davo-invite]');
    var launcher = document.getElementById('chatbot-toggle');
    var panel = document.getElementById('chatbot-panel');
    if (!invite || !launcher || !panel) return;

    // A visit is this tab's browser session: reloads and same-tab navigation do not repeat it.
    var key = invite.getAttribute('data-invite-key') || 'dvo-planner-invite-seen';
    var seen = false;
    try { seen = sessionStorage.getItem(key) === '1'; } catch (_) { /* Keep dismissal usable if storage is blocked. */ }
    function remember() {
        seen = true;
        try { sessionStorage.setItem(key, '1'); } catch (_) { /* No persistent storage required. */ }
    }
    var timer;
    var hideTimer;
    var lastInteraction = Date.now();
    var hovering = false;
    function busy() {
        return document.hidden || !panel.hidden ||
            document.querySelector('dialog[open], .site-topbar [aria-expanded="true"]') ||
            Array.from(document.querySelectorAll('[aria-modal="true"]:not([inert])')).some(function (modal) { return modal.getClientRects().length > 0; }) ||
            (document.activeElement && document.activeElement.closest('[data-hero-search]')) ||
            Date.now() - lastInteraction < 4000;
    }
    function dismiss() {
        clearTimeout(timer);
        clearTimeout(hideTimer);
        invite.hidden = true;
        remember();
    }
    function scheduleHide() {
        clearTimeout(hideTimer);
        if (invite.hidden || hovering || invite.contains(document.activeElement)) return;
        hideTimer = setTimeout(dismiss, 12000);
    }
    function offerWhenIdle() {
        if (seen) return;
        if (busy()) {
            timer = setTimeout(offerWhenIdle, 1000);
            return;
        }
        invite.hidden = false;
        remember();
        scheduleHide();
    }
    timer = setTimeout(offerWhenIdle, 9000);
    // Watch only presentation activity; search, menu, chat and planner handlers stay untouched.
    function activity(event) {
        if (invite.contains(event.target)) return;
        lastInteraction = Date.now();
        if (!invite.hidden) dismiss();
    }
    ['pointerdown', 'keydown', 'input'].forEach(function (name) {
        document.addEventListener(name, activity, { passive: true });
    });
    window.addEventListener('scroll', function () {
        lastInteraction = Date.now();
        if (!invite.hidden) dismiss();
    }, { passive: true });
    invite.addEventListener('pointerenter', function (event) {
        if (event.pointerType !== 'mouse') return;
        hovering = true;
        clearTimeout(hideTimer);
    });
    invite.addEventListener('pointerleave', function () { hovering = false; scheduleHide(); });
    invite.addEventListener('focusin', function () { clearTimeout(hideTimer); });
    invite.addEventListener('focusout', function () { setTimeout(scheduleHide, 0); });
    invite.querySelector('[data-davo-invite-close]').addEventListener('click', function () {
        dismiss();
        launcher.focus({ preventScroll: true });
    });
    invite.querySelector('[data-davo-invite-link]').addEventListener('click', dismiss);
    // Only dismiss the invitation; the existing chat handler still owns opening the chat.
    launcher.addEventListener('click', function () { clearTimeout(timer); dismiss(); });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !invite.hidden) {
            var hadFocus = invite.contains(document.activeElement);
            dismiss();
            if (hadFocus) launcher.focus({ preventScroll: true });
        }
    });
})();
