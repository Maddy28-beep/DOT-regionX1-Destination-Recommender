// Copy buttons for the private-link panel on the itinerary page.
(function () {
    'use strict';
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-keep-copy]');
        if (!button) return;
        var input = document.getElementById(button.getAttribute('data-keep-copy'));
        if (!input) return;
        input.select();
        var done = function () { var was = button.textContent; button.textContent = 'Copied'; setTimeout(function () { button.textContent = was; }, 1600); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(input.value).then(done, function () { document.execCommand('copy'); done(); });
        } else { document.execCommand('copy'); done(); }
    });
    document.addEventListener('focusin', function (event) { if (event.target.matches('[data-keep-input]')) event.target.select(); });
})();
