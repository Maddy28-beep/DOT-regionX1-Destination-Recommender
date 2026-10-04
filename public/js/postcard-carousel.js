/* "Popular Right Now" carousel.

   Slides crossfade; the thumbnail progress line is the autoplay timer (its
   animationend advances the slide, and pausing is animation-play-state, so
   resuming continues from the same point). Autoplay is off entirely under
   prefers-reduced-motion and for a single slide. */
(function () {
    'use strict';

    var root = document.querySelector('[data-postcard-carousel]');
    if (!root) return;

    var stage = root.querySelector('[data-stage]');
    var slides = [].slice.call(root.querySelectorAll('[data-slide]'));
    var thumbs = [].slice.call(root.querySelectorAll('[data-thumb]'));
    var captions = [].slice.call(root.querySelectorAll('[data-caption-item]'));
    var ctas = [].slice.call(root.querySelectorAll('[data-cta]'));
    var captionBox = root.querySelector('[data-caption]');
    var toggle = root.querySelector('[data-toggle]');
    var count = slides.length;
    if (count < 2) return;

    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var index = 0;
    var userPaused = reduced;      // reduced motion starts (and stays) stopped
    var hoverPaused = false;
    var focusPaused = false;
    var manual = false;            // true after the visitor navigated by hand

    function paused() { return userPaused || hoverPaused || focusPaused; }

    // Captions are announced only when the visitor is driving: during autoplay
    // the region is off, so it does not keep talking.
    function syncState() {
        var isPaused = paused();
        root.classList.toggle('is-paused', isPaused);
        captionBox.setAttribute('aria-live', isPaused || manual ? 'polite' : 'off');
        if (toggle) {
            toggle.setAttribute('aria-pressed', userPaused ? 'true' : 'false');
            toggle.setAttribute('aria-label', userPaused ? 'Play autoplay' : 'Pause autoplay');
        }
    }

    function show(next, fromUser) {
        next = (next + count) % count;
        if (next === index) return;
        if (fromUser) manual = true; else manual = false;
        // Polite before the text changes, so the new caption is announced.
        syncState();

        slides[index].classList.remove('is-active');
        slides[index].setAttribute('aria-hidden', 'true');
        slides[next].classList.add('is-active');
        slides[next].removeAttribute('aria-hidden');

        thumbs.forEach(function (t, i) {
            var on = i === next;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
        });
        captions.forEach(function (c, i) { c.hidden = i !== next; });
        ctas.forEach(function (c, i) { c.hidden = i !== next; });

        index = next;
    }

    // The progress line finishing is what moves autoplay on.
    root.addEventListener('animationend', function (e) {
        if (e.animationName === 'pc-progress' && !reduced && !paused()) show(index + 1, false);
    });

    var prev = root.querySelector('[data-prev]');
    var next = root.querySelector('[data-next]');
    if (prev) prev.addEventListener('click', function () { show(index - 1, true); });
    if (next) next.addEventListener('click', function () { show(index + 1, true); });

    thumbs.forEach(function (t, i) {
        t.addEventListener('click', function () { show(i, true); });
    });

    if (toggle) {
        toggle.addEventListener('click', function () {
            userPaused = !userPaused;
            if (!userPaused) manual = false;
            syncState();
        });
    }

    // Hover and keyboard focus anywhere inside pause autoplay.
    root.addEventListener('mouseenter', function () { hoverPaused = true; syncState(); });
    root.addEventListener('mouseleave', function () { hoverPaused = false; syncState(); });
    root.addEventListener('focusin', function () { focusPaused = true; syncState(); });
    root.addEventListener('focusout', function (e) {
        if (!root.contains(e.relatedTarget)) { focusPaused = false; syncState(); }
    });

    // Left / right arrow keys while focus is inside the carousel.
    root.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
        var onThumb = document.activeElement && document.activeElement.hasAttribute('data-thumb');
        show(index + (e.key === 'ArrowRight' ? 1 : -1), true);
        if (onThumb) thumbs[index].focus();
        e.preventDefault();
    });

    // Swipe: a mostly-horizontal move of more than 50px. The stage keeps
    // touch-action: pan-y so vertical scrolling still works.
    var startX = null, startY = null;
    stage.addEventListener('pointerdown', function (e) {
        if (e.pointerType === 'mouse') return;
        startX = e.clientX; startY = e.clientY;
    });
    stage.addEventListener('pointerup', function (e) {
        if (startX === null) return;
        var dx = e.clientX - startX, dy = e.clientY - startY;
        startX = startY = null;
        if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) show(index + (dx < 0 ? 1 : -1), true);
    });
    stage.addEventListener('pointercancel', function () { startX = startY = null; });

    syncState();
})();
