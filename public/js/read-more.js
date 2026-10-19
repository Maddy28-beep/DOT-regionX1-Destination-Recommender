/* "Read more" on a phone: long copy is folded behind a button there, and shown in full everywhere else.

   A button carries data-read-more="<id of the folded text>". Without this script the button stays hidden and the
   text stays visible, so nothing is ever lost; with it, the stylesheet folds the text on a phone only. */
(function () {
    'use strict';

    [].slice.call(document.querySelectorAll('[data-read-more]')).forEach(function (button) {
        var text = document.getElementById(button.getAttribute('data-read-more'));
        if (!text) return;

        var holder = button.parentNode;
        holder.classList.add('has-fold');
        button.hidden = false;

        button.addEventListener('click', function () {
            var open = text.classList.toggle('is-open');
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            button.textContent = open ? 'Read less' : 'Read more';
        });
    });
})();
