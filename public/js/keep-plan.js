// Copy buttons for the private-link panel on the itinerary page.
(function () {
    'use strict';
    var managed = document.querySelector('[data-keep-managed]');
    var manageLinks = document.querySelectorAll('[data-keep-manage]');
    function showManagedPanel() {
        if (!managed) return;
        managed.hidden = false;
        manageLinks.forEach(function (link) { link.setAttribute('aria-expanded', 'true'); });
    }
    manageLinks.forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (!managed) return;
            event.preventDefault();
            showManagedPanel();
            managed.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
        });
    });
    document.querySelectorAll('[data-keep-panel-close]').forEach(function (button) {
        button.hidden = false;
        button.addEventListener('click', function () {
            managed.hidden = true;
            var device = managed.querySelector('[data-keep-device]');
            if (device) device.open = false;
            manageLinks.forEach(function (link) { link.setAttribute('aria-expanded', 'false'); });
            if (manageLinks[0]) manageLinks[0].focus({ preventScroll: true });
        });
    });
    if (window.location.hash === '#keep') showManagedPanel();
    // Enhance the existing confirmation section into a native modal. Without JS,
    // the original anchor and POST form still work as an inline fallback.
    var confirmation = document.querySelector('[data-keep-confirm]');
    if (confirmation && typeof HTMLDialogElement !== 'undefined' && typeof HTMLDialogElement.prototype.showModal === 'function') {
        var dialog = document.createElement('dialog');
        dialog.id = 'keepConfirmDialog';
        dialog.className = 'keep-dialog';
        dialog.setAttribute('aria-labelledby', 'keepTitle');
        confirmation.replaceWith(dialog);
        dialog.appendChild(confirmation);
        var opener;
        document.querySelectorAll('[data-keep-open]').forEach(function (link) {
            link.setAttribute('aria-haspopup', 'dialog');
            link.setAttribute('aria-controls', dialog.id);
            link.setAttribute('aria-expanded', 'false');
            link.addEventListener('click', function (event) {
                event.preventDefault();
                opener = link;
                link.setAttribute('aria-expanded', 'true');
                if (!dialog.open) dialog.showModal();
            });
        });
        confirmation.querySelectorAll('[data-keep-dismiss]').forEach(function (button) {
            button.hidden = false;
            button.addEventListener('click', function () { dialog.close(); });
        });
        dialog.addEventListener('close', function () {
            if (opener) {
                opener.setAttribute('aria-expanded', 'false');
                opener.focus({ preventScroll: true });
            }
        });
    }
    document.querySelectorAll('[data-keep-qr-close]').forEach(function (button) {
        button.hidden = false;
        button.addEventListener('click', function () {
            var device = button.closest('[data-keep-device]');
            device.open = false;
            device.querySelector('summary').focus({ preventScroll: true });
        });
    });
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-keep-copy]');
        if (!button) return;
        var input = document.getElementById(button.getAttribute('data-keep-copy'));
        if (!input) return;
        input.select();
        var done = function () {
            var was = button.textContent;
            button.textContent = 'Copied';
            var status = document.querySelector('[data-keep-copy-status]');
            if (status) status.textContent = button.getAttribute('data-keep-copy') === 'keepEdit' ? 'Private link copied.' : 'View-only link copied.';
            setTimeout(function () { button.textContent = was; }, 1600);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(input.value).then(done, function () { document.execCommand('copy'); done(); });
        } else { document.execCommand('copy'); done(); }
    });
    document.addEventListener('focusin', function (event) { if (event.target.matches('[data-keep-input]')) event.target.select(); });
})();
