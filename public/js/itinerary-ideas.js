/* The free-time ideas on the itinerary page: a toggle that shows or hides the list under it. They start open;
   aria-expanded follows the panel. */
(function () {
    'use strict';

    document.querySelectorAll('[data-ideas]').forEach(function (block) {
        var button = block.querySelector('.tl-ideas__toggle');
        var panel = block.querySelector('.tl-ideas__panel');
        if (!button || !panel) return;

        button.addEventListener('click', function () {
            var open = button.getAttribute('aria-expanded') !== 'true';
            button.setAttribute('aria-expanded', String(open));
            panel.hidden = !open;
        });
    });
})();
