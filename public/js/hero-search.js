/* Landing page search card.

   The tabs are radio inputs named "purpose", so the form already works without this script. It adds:
     - a placeholder that names what the typed text will search,
     - only the boxes the chosen tab can use: every tab can narrow by place, and then by what only that
       catalogue records (an interest, a kind of stay, a cuisine, a trip length). The others are hidden and
       disabled, so they are never sent. SearchController drops them on the server too.
     - the budget pills, hidden for the tab whose catalogue records no price tier,
   and nothing else. */
(function () {
    'use strict';

    var form = document.querySelector('[data-hero-search]');
    if (!form) return;

    var radios = [].slice.call(form.querySelectorAll('input[name="purpose"]'));
    var field = form.querySelector('[data-search-field]');
    var hint = form.querySelector('[data-search-hint]');
    var row = form.querySelector('[data-search-filters]');
    var boxes = [].slice.call(form.querySelectorAll('[data-filter]'));
    var pills = form.querySelector('[data-budget-pills]');

    function setUsed(box, used) {
        var select = box.querySelector('select');
        box.hidden = !used;
        // Disabled controls are not submitted, so a hidden box can never reach the search.
        if (select) select.disabled = !used;
    }

    function sync() {
        var chosen = radios.filter(function (r) { return r.checked; })[0];
        if (!chosen) return;

        var allowed = (chosen.getAttribute('data-filters') || '').split(/\s+/).filter(Boolean);
        var used = 0;

        boxes.forEach(function (box) {
            var on = allowed.indexOf(box.getAttribute('data-filter')) !== -1;
            setUsed(box, on);
            if (on) used++;
        });
        if (row) row.style.setProperty('--hs-cols', String(Math.max(1, used)));

        if (field) field.placeholder = chosen.getAttribute('data-placeholder') || field.placeholder;

        if (pills) {
            var hasBudget = chosen.getAttribute('data-budget') === '1';
            pills.hidden = !hasBudget;
            [].slice.call(pills.querySelectorAll('input')).forEach(function (i) { i.disabled = !hasBudget; });
        }

        if (hint) {
            hint.textContent = chosen.value === 'souvenir_centers'
                ? 'Souvenir shops are searched by name and place.'
                : '';
        }
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
