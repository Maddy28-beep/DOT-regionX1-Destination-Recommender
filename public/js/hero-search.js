/* Landing page search card.

   The tabs are radio inputs named "purpose", so the form already works without this script. It adds:
     - only the boxes the chosen tab uses (the radio's data-tab-fields lists them). The others are hidden and
       disabled, so they are never sent. SearchController ignores them on the server too.
     - the Budget dropdown of the chosen tab (each tab has its own: what a budget means differs by catalogue),
       and the two amount boxes when "Custom budget" is chosen, checked before the form is sent,
     - the +/- guest counters (never below their minimum or above their maximum) and their total,
     - a check-out date that cannot be before the check-in date,
     - Reset, which puts every box back on "Any".
   Nothing else. */
(function () {
    'use strict';

    var form = document.querySelector('[data-hero-search]');
    if (!form) return;

    var radios = [].slice.call(form.querySelectorAll('input[name="purpose"]'));
    var hint = form.querySelector('[data-search-hint]');
    var grid = form.querySelector('[data-search-filters]');
    var boxes = [].slice.call(form.querySelectorAll('[data-for], [data-budget-box]'));
    var custom = form.querySelector('[data-custom-budget]');
    var minBox = form.querySelector('[data-budget-min]');
    var maxBox = form.querySelector('[data-budget-max]');
    var budgetError = form.querySelector('[data-budget-error]');
    var guestTotal = form.querySelector('[data-guest-total]');
    var fromBox = form.querySelector('[data-date-from]');
    var toBox = form.querySelector('[data-date-to]');
    var resetBtn = form.querySelector('[data-search-reset]');

    var HINTS = {
        destinations: 'Everything is optional. Leave a box on Any to see every destination.',
        accommodations: 'Dates and guests are optional. They are kept with your search but are not checked against availability, which is not recorded.',
        restaurants: 'A meal shows restaurants open at that hour. Dietary needs and plated or buffet service are not recorded for restaurants yet.',
        packages: 'The budget is the total: the per-person price times the number of travelers.',
        souvenir_centers: 'Souvenir shops are searched by place. Their prices and categories are not recorded.'
    };

    function controls(box) {
        return [].slice.call(box.querySelectorAll('input, select, button[data-step]'));
    }

    function setUsed(box, used) {
        box.hidden = !used;
        // Disabled controls are not submitted, so a hidden box can never reach the search.
        controls(box).forEach(function (c) { c.disabled = !used; });
        var inner = box.querySelectorAll('[data-counter]');
        if (used) [].slice.call(inner).forEach(refreshCounter);
    }

    function chosen() {
        return radios.filter(function (r) { return r.checked; })[0];
    }

    function budgetSelect() {
        var radio = chosen();
        if (!radio) return null;
        var box = form.querySelector('[data-budget-box="' + radio.value + '"]');
        return box ? box.querySelector('select') : null;
    }

    function sync() {
        var radio = chosen();
        if (!radio) return;

        var allowed = (radio.getAttribute('data-tab-fields') || '').split(/\s+/).filter(Boolean);

        boxes.forEach(function (box) {
            var kind = box.getAttribute('data-for');
            var on;
            if (kind === 'budget') {
                // Only the chosen tab's own budget dropdown.
                on = allowed.indexOf('budget') !== -1 && box.getAttribute('data-budget-box') === radio.value;
            } else {
                on = allowed.indexOf(kind) !== -1;
            }
            setUsed(box, on);
        });

        syncCustom();
        syncGuests();
        if (hint) hint.textContent = HINTS[radio.value] || '';
    }

    // "Custom budget" reveals the two amount boxes; any other choice hides and clears them.
    function syncCustom() {
        if (!custom) return;
        var select = budgetSelect();
        var on = !!select && !select.disabled && select.value === 'custom';
        custom.hidden = !on;
        [minBox, maxBox].forEach(function (b) {
            if (!b) return;
            b.disabled = !on;
            if (!on) b.value = '';
        });
        showError('');
    }

    function showError(message) {
        if (!budgetError) return;
        budgetError.textContent = message;
        budgetError.hidden = !message;
    }

    function amount(box) {
        var text = (box.value || '').replace(/[,\s₱]/g, '');
        if (text === '') return null;
        return /^\d+(\.\d{1,2})?$/.test(text) ? parseFloat(text) : NaN;
    }

    function checkBudget() {
        if (!custom || custom.hidden) return true;
        var lo = amount(minBox), hi = amount(maxBox);
        if (isNaN(lo) || isNaN(hi)) { showError('Enter the budget as a number, for example 1500.'); return false; }
        if (lo !== null && hi !== null && lo > hi) { showError('The minimum budget is higher than the maximum.'); return false; }
        showError('');
        return true;
    }

    function refreshCounter(counter) {
        var input = counter.querySelector('input');
        var minus = counter.querySelector('[data-step="-1"]');
        var plus = counter.querySelector('[data-step="1"]');
        var n = parseInt(input.value, 10) || 0;
        var lo = parseInt(input.getAttribute('data-min'), 10) || 0;
        var hi = parseInt(input.getAttribute('data-max'), 10) || 99;
        n = Math.max(lo, Math.min(hi, n));
        input.value = String(n);
        if (!input.disabled) {
            minus.disabled = n <= lo;
            plus.disabled = n >= hi;
        }
    }

    function syncGuests() {
        if (!guestTotal) return;
        var total = 0;
        [].slice.call(form.querySelectorAll('[data-guest]')).forEach(function (i) { total += parseInt(i.value, 10) || 0; });
        guestTotal.textContent = total === 0 ? 'Any group size' : 'Total: ' + total + (total === 1 ? ' guest' : ' guests');
    }

    form.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-step]');
        if (!btn || btn.disabled) return;
        var counter = btn.closest('[data-counter]');
        var input = counter.querySelector('input');
        input.value = String((parseInt(input.value, 10) || 0) + parseInt(btn.getAttribute('data-step'), 10));
        refreshCounter(counter);
        syncGuests();
    });

    form.addEventListener('change', function (e) {
        if (e.target.matches && e.target.matches('[data-budget-select]')) syncCustom();
        if (e.target === fromBox && toBox) {
            toBox.min = fromBox.value || toBox.getAttribute('min');
            if (toBox.value && fromBox.value && toBox.value < fromBox.value) toBox.value = '';
        }
    });

    form.addEventListener('submit', function (e) {
        if (!checkBudget()) {
            e.preventDefault();
            (amount(minBox) !== null && isNaN(amount(minBox)) ? minBox : maxBox).focus();
        }
    });

    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            var keep = chosen();
            form.reset();
            if (keep) keep.checked = true;
            // The shared dropdown script shows the native value; tell it the selects changed.
            [].slice.call(form.querySelectorAll('select')).forEach(function (s) {
                s.dispatchEvent(new Event('change', { bubbles: true }));
            });
            if (toBox) toBox.min = fromBox ? fromBox.getAttribute('min') : '';
            [].slice.call(form.querySelectorAll('[data-counter]')).forEach(refreshCounter);
            sync();
        });
    }

    // On a phone the tab strip scrolls: keep the chosen tab in view, so picking "Packages" never hides it.
    function reveal(radio) {
        var tab = radio.parentNode;
        var strip = tab && tab.parentNode;
        if (!strip || strip.scrollWidth <= strip.clientWidth) return;
        // Measured against the strip itself: the tab's offsetLeft is relative to the card, not the strip.
        var offset = tab.getBoundingClientRect().left - strip.getBoundingClientRect().left + strip.scrollLeft;
        var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        strip.scrollTo({ left: offset - (strip.clientWidth - tab.offsetWidth) / 2, behavior: calm ? 'auto' : 'smooth' });
    }

    radios.forEach(function (r) {
        r.addEventListener('change', function () { sync(); reveal(r); });
    });
    // Back/forward navigation can restore a different tab than the markup's default.
    window.addEventListener('pageshow', sync);
    sync();
})();
