/* Listing pages: the filter panel as a bottom sheet on a phone.

   Wide screens keep the panel beside the grid and this script does nothing there. On a phone the panel is a
   sheet over the page, so opening it never pushes the listing down. It closes with the x, the dim layer, Esc,
   or by growing the window past the phone breakpoint. */
(function () {
    'use strict';

    var panel = document.getElementById('filterPanel');
    var opener = document.querySelector('[data-filter-sheet-open]');
    if (!panel || !opener) return;

    var overlay = document.querySelector('[data-filter-sheet-overlay]');
    var phone = window.matchMedia('(max-width: 900px)');
    var returnTo = null;

    function isOpen() { return panel.classList.contains('open'); }

    function focusable() {
        return [].slice.call(panel.querySelectorAll('a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])'))
            .filter(function (el) { return !el.disabled && el.offsetParent !== null; });
    }

    function open() {
        if (!phone.matches || isOpen()) return;
        returnTo = document.activeElement;
        panel.classList.add('open');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        panel.setAttribute('aria-labelledby', 'filterPanelTitle');
        document.body.classList.add('filter-sheet-open');
        opener.setAttribute('aria-expanded', 'true');
        panel.scrollTop = 0;
        // Wait a frame so the focus move does not fight the slide-in.
        window.requestAnimationFrame(function () { panel.focus({ preventScroll: true }); });
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
        opener.setAttribute('aria-expanded', 'false');
        if (restoreFocus !== false && returnTo && returnTo.focus) returnTo.focus({ preventScroll: true });
        returnTo = null;
    }

    opener.addEventListener('click', open);
    [].slice.call(panel.querySelectorAll('[data-filter-sheet-close]')).forEach(function (b) {
        b.addEventListener('click', function () { close(); });
    });
    if (overlay) overlay.addEventListener('click', function () { close(); });

    document.addEventListener('keydown', function (e) {
        if (!isOpen()) return;
        if (e.key === 'Escape') { e.preventDefault(); close(); return; }
        if (e.key !== 'Tab') return;
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
