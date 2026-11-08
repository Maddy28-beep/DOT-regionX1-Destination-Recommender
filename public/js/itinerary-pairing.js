/* "Why this pick?" on a stop: a button that opens a small card that floats over the page (it takes no room in the card). The
   button's aria-expanded follows the card; the close button, Escape and a click anywhere else close it, and focus goes
   back to the button. Only one card is open at a time. The card's text is rendered by the server. */
(function () {
    'use strict';

    var blocks = document.querySelectorAll('[data-pairing]');
    if (!blocks.length) return;

    function setOpen(block, open, giveFocusBack) {
        var button = block.querySelector('.pairing-why__btn');
        var panel = block.querySelector('.pairing-why__panel');
        if (!button || !panel) return;
        button.setAttribute('aria-expanded', String(open));
        panel.hidden = !open;
        if (open) {
            var close = panel.querySelector('.pairing-why__close');
            if (close) close.focus({ preventScroll: true });
        } else if (giveFocusBack) {
            button.focus({ preventScroll: true });
        }
    }

    function closeOthers(except) {
        blocks.forEach(function (block) {
            if (block !== except) setOpen(block, false, false);
        });
    }

    blocks.forEach(function (block) {
        var button = block.querySelector('.pairing-why__btn');
        var panel = block.querySelector('.pairing-why__panel');
        if (!button || !panel) return;

        button.addEventListener('click', function () {
            var open = button.getAttribute('aria-expanded') !== 'true';
            if (open) closeOthers(block);
            setOpen(block, open, false);
        });
        var close = panel.querySelector('.pairing-why__close');
        if (close) close.addEventListener('click', function () { setOpen(block, false, true); });
        block.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !panel.hidden) {
                setOpen(block, false, true);
            }
        });
    });

    document.addEventListener('click', function (event) {
        blocks.forEach(function (block) {
            if (!block.contains(event.target)) setOpen(block, false, false);
        });
    });
})();
