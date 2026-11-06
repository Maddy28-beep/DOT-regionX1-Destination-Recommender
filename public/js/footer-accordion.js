/* Footer accordions on a phone: "For Travelers" starts open, "For Partners" and "Department of Tourism" start closed.
   Each header is a button whose aria-expanded follows its panel. On wider screens the buttons are not shown and the
   CSS shows every panel whatever its state (see the footer rules in app.css), so nothing here matters there. */
(function () {
    'use strict';

    document.querySelectorAll('[data-footer-acc]').forEach(function (section) {
        var button = section.querySelector('.footer-acc__btn');
        var panel = section.querySelector('.footer-acc__panel');
        if (!button || !panel) return;

        function setOpen(open) {
            button.setAttribute('aria-expanded', String(open));
            panel.hidden = !open;
        }

        setOpen(section.getAttribute('data-footer-acc') === 'open');
        button.addEventListener('click', function () {
            setOpen(button.getAttribute('aria-expanded') !== 'true');
        });
    });
})();
