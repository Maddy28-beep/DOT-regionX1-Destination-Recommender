/* "Why this pick?" on a stop with a popular pairing: a button that opens a small panel under the pairing pill. The
   button's aria-expanded follows the panel, and Escape closes it. The panel's text is rendered by the server. */
(function () {
    'use strict';

    document.querySelectorAll('[data-pairing]').forEach(function (block) {
        var button = block.querySelector('.pairing-why__btn');
        var panel = block.querySelector('.pairing-why__panel');
        if (!button || !panel) return;

        function setOpen(open) {
            button.setAttribute('aria-expanded', String(open));
            panel.hidden = !open;
        }

        button.addEventListener('click', function () {
            setOpen(button.getAttribute('aria-expanded') !== 'true');
        });
        block.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !panel.hidden) {
                setOpen(false);
                button.focus();
            }
        });
    });
})();
