/* "What Davao is known for" on a phone or tablet: the swipeable row also moves by itself, like a slideshow.

   Every few seconds the row slides to the next card (and back to the first after the last). It stops the moment the
   visitor touches it, and starts again a few seconds after they let go; it only runs while the row is on screen and
   the tab is open; and clickable dots under it show which card is up and let a visitor pick one. With reduced
   motion, or on a wide screen where all five cards already show, nothing here runs: the row simply swipes. */
(function () {
    'use strict';

    var row = document.querySelector('.moods__row');
    if (!row || !row.children.length) return;

    var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)');
    var narrow = window.matchMedia && window.matchMedia('(max-width: 1100px)');   // where the cards are a swipe row
    if (!narrow || (calm && calm.matches)) return;

    var items = [].slice.call(row.children);
    var count = items.length;
    if (count < 2) return;

    var STEP_MS = 4200;          // how long a card stays
    var RESUME_MS = 7000;        // how long after a touch before it starts moving again
    var timer = null;
    var resumeTimer = null;
    var touching = false;
    var onScreen = false;
    var current = 0;

    // ---- controls (built here so the page needs nothing without script) ----
    var controls = document.createElement('div');
    controls.className = 'moods__controls';

    var dots = document.createElement('div');
    dots.className = 'moods__dots';
    dots.setAttribute('role', 'group');
    dots.setAttribute('aria-label', 'Choose a card');
    var dotButtons = items.map(function (item, i) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'moods__dot';
        var name = item.querySelector('.mood__name');
        b.setAttribute('aria-label', 'Show ' + (name ? name.textContent.trim() : 'card ' + (i + 1)));
        b.addEventListener('click', function () { goTo(i); holdAfterTouch(); });
        dots.appendChild(b);
        return b;
    });

    controls.appendChild(dots);
    row.parentNode.insertBefore(controls, row.nextSibling);

    // ---- moving ----
    function leftFor(i) {
        var pad = parseFloat(window.getComputedStyle(row).scrollPaddingInlineStart || window.getComputedStyle(row).scrollPaddingLeft) || 0;
        return row.scrollLeft + (items[i].getBoundingClientRect().left - row.getBoundingClientRect().left) - pad;
    }

    function goTo(i) {
        current = (i + count) % count;
        row.scrollTo({ left: Math.max(0, leftFor(current)), behavior: 'smooth' });
        mark(current);
    }

    function mark(i) {
        dotButtons.forEach(function (b, n) {
            if (n === i) b.setAttribute('aria-current', 'true'); else b.removeAttribute('aria-current');
        });
    }

    // Which card is at the left edge now, whoever moved the row (the timer, a finger, a dot).
    function nearest() {
        var best = 0, bestDistance = Infinity, edge = row.getBoundingClientRect().left;
        items.forEach(function (item, n) {
            var d = Math.abs(item.getBoundingClientRect().left - edge - 20);
            if (d < bestDistance) { bestDistance = d; best = n; }
        });
        return best;
    }

    var scrollFrame = null;
    row.addEventListener('scroll', function () {
        if (scrollFrame) return;
        scrollFrame = window.requestAnimationFrame(function () {
            scrollFrame = null;
            current = nearest();
            mark(current);
        });
    }, { passive: true });

    function tick() { goTo(current + 1); }

    // ---- when it runs ----
    function running() {
        return narrow.matches && onScreen && !touching && !document.hidden;
    }

    function sync() {
        window.clearInterval(timer);
        timer = null;
        if (running()) timer = window.setInterval(tick, STEP_MS);
    }

    // A touch, a drag or the wheel stops the slideshow; it comes back a few seconds after the last one.
    function holdAfterTouch() {
        touching = true;
        window.clearTimeout(resumeTimer);
        sync();
        resumeTimer = window.setTimeout(function () { touching = false; sync(); }, RESUME_MS);
    }
    ['touchstart', 'pointerdown', 'wheel'].forEach(function (name) {
        row.addEventListener(name, holdAfterTouch, { passive: true });
    });
    row.addEventListener('focusin', holdAfterTouch);

    document.addEventListener('visibilitychange', sync);
    if (narrow.addEventListener) narrow.addEventListener('change', sync); else if (narrow.addListener) narrow.addListener(sync);

    if ('IntersectionObserver' in window) {
        new window.IntersectionObserver(function (entries) {
            onScreen = entries[0].isIntersecting;
            sync();
        }, { threshold: 0.5 }).observe(row);
    }

    mark(0);
})();
