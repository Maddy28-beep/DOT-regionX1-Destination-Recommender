/*
 * Home page: the first screen is one screen, at any window size.
 *
 * The picture, the search card, the popular searches and the stats are laid out for a window about 770px tall. On a
 * shorter window, or a phone, the stats and the "Discover Davao" cue fell below the fold. Here the group is measured
 * at its natural size and, when it is taller than the window, scaled down by exactly the amount that makes it fit. It
 * is scaled with a transform and given a wider box to match, so the layout inside is the one for the real window
 * width and only its size changes. A window that is tall enough is left alone, and below MIN_SCALE the group is
 * not shrunk any further (it scrolls instead) so the text never becomes too small to read.
 *
 * The search drop-downs are attached to <body>, outside the scaled group, so they still open in the right place.
 */
(() => {
    'use strict';

    const screen = document.querySelector('.hero-screen');
    if (!screen) return;

    const MIN_SCALE = 0.62;
    const coarse = window.matchMedia('(pointer: coarse)').matches;
    let lastWidth = 0;
    let lastHeight = 0;

    const reset = () => {
        screen.style.transform = '';
        screen.style.transformOrigin = '';
        screen.style.width = '';
        screen.style.minHeight = '';
        screen.style.marginBottom = '';
    };

    const fit = () => {
        const height = window.innerHeight;
        reset();

        // The natural height: the content itself, without the "at least one window tall" rule.
        screen.style.minHeight = '0';
        const natural = screen.offsetHeight;
        screen.style.minHeight = '';
        if (natural <= height) return;

        const scale = Math.max(MIN_SCALE, height / natural);
        const box = height / scale;
        screen.style.transformOrigin = '0 0';
        screen.style.transform = `scale(${scale})`;
        screen.style.width = `${100 / scale}%`;
        screen.style.minHeight = `${Math.max(box, natural)}px`;
        screen.style.marginBottom = `${-(Math.max(box, natural) * (1 - scale))}px`;
    };

    const refit = () => {
        const width = window.innerWidth;
        const height = window.innerHeight;
        // On a phone the address bar shrinking as you scroll changes the height a little: that is not a new size.
        if (coarse && width === lastWidth && Math.abs(height - lastHeight) < 140) return;
        lastWidth = width;
        lastHeight = height;
        fit();
    };

    let timer = null;
    window.addEventListener('resize', () => { clearTimeout(timer); timer = setTimeout(refit, 80); });
    window.addEventListener('orientationchange', () => setTimeout(refit, 200));
    window.addEventListener('load', () => { lastWidth = 0; refit(); });
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => { lastWidth = 0; refit(); });
    refit();
})();
