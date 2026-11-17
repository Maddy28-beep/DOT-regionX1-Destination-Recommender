/* Listing pages: expand the refined filters in the mobile page; retain the older sheet fallback.

   Wide screens keep the panel beside the grid. The refined panel uses document scrolling and normal tab
   navigation. It closes with the x, Escape, or by growing the window past the phone breakpoint. */
(function () {
    'use strict';

    var panel = document.getElementById('filterPanel');
    var opener = document.querySelector('[data-filter-sheet-open]');
    if (!panel || !opener) return;

    var overlay = document.querySelector('[data-filter-sheet-overlay]');
    var phone = window.matchMedia('(max-width: 900px)');
    var returnTo = null;
    var pageScroll = panel.classList.contains('filter-panel--page-scroll');
    if (pageScroll) opener.removeAttribute('aria-haspopup');

    function isOpen() { return panel.classList.contains('open'); }

    function focusable() {
        return [].slice.call(panel.querySelectorAll('a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])'))
            .filter(function (el) { return !el.disabled && el.offsetParent !== null; });
    }

    function open() {
        if (!phone.matches || isOpen()) return;
        returnTo = document.activeElement;
        panel.classList.add('open');
        panel.setAttribute('role', pageScroll ? 'region' : 'dialog');
        if (!pageScroll) panel.setAttribute('aria-modal', 'true');
        panel.setAttribute('aria-labelledby', 'filterPanelTitle');
        document.body.classList.add(pageScroll ? 'filter-inline-open' : 'filter-sheet-open');
        opener.setAttribute('aria-expanded', 'true');
        panel.scrollTop = 0;
        var fields = panel.querySelector('.filter-panel__fields');
        if (fields) fields.scrollTop = 0;
        // Wait a frame so the focus move does not fight the slide-in.
        window.requestAnimationFrame(function () {
            if (pageScroll) panel.scrollIntoView({block:'start', behavior:'auto'});
            panel.focus({ preventScroll: true });
        });
    }

    function close(restoreFocus) {
        if (!isOpen()) return;
        // A dropdown list open inside the sheet floats over the page on its own: close it with the sheet.
        [].slice.call(panel.querySelectorAll('.site-dropdown-trigger[aria-expanded="true"]')).forEach(function (t) {
            t.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        });
        panel.classList.remove('open');
        panel.removeAttribute('role');
        panel.removeAttribute('aria-modal');
        panel.removeAttribute('aria-labelledby');
        document.body.classList.remove('filter-sheet-open');
        document.body.classList.remove('filter-inline-open');
        opener.setAttribute('aria-expanded', 'false');
        if (restoreFocus !== false && returnTo && returnTo.focus) returnTo.focus({ preventScroll: true });
        returnTo = null;
    }

    opener.addEventListener('click', function () {
        if (pageScroll && isOpen()) close();
        else open();
    });
    document.querySelectorAll('[data-adjust-filters]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (phone.matches) {
                open();
            } else {
                panel.scrollIntoView({ block: 'nearest' });
                panel.focus({ preventScroll: true });
            }
        });
    });
    [].slice.call(panel.querySelectorAll('[data-filter-sheet-close]')).forEach(function (b) {
        b.addEventListener('click', function () { close(); });
    });
    if (overlay) overlay.addEventListener('click', function () { close(); });

    document.addEventListener('keydown', function (e) {
        if (!isOpen()) return;
        if (e.key === 'Escape') { e.preventDefault(); close(); return; }
        if (e.key !== 'Tab' || pageScroll) return;
        var items = focusable();
        if (!items.length) return;
        var first = items[0], last = items[items.length - 1];
        if (e.shiftKey && (document.activeElement === first || document.activeElement === panel)) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    // Rotating or resizing up to a wide layout puts the panel back beside the grid.
    var onChange = function () { if (!phone.matches) close(false); };
    if (phone.addEventListener) phone.addEventListener('change', onChange); else phone.addListener(onChange);

    // Back/forward can restore the page with the sheet "open": start closed.
    window.addEventListener('pageshow', function () { close(false); });

    // Bring the chip that is on into view, so a long row does not hide the category you are in.
    var row = document.querySelector('.catalog-categories');
    var current = row && row.querySelector('[aria-current="true"]');
    if (row && current && row.scrollWidth > row.clientWidth) {
        var offset = current.getBoundingClientRect().left - row.getBoundingClientRect().left + row.scrollLeft;
        row.scrollLeft = Math.max(0, offset - 20);
    }
})();
