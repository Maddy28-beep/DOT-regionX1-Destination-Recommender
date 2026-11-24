// "Print or save as PDF" on a shared trip plan. The browser's print dialog has "Save as PDF" on every device.
(function () {
    'use strict';
    var script = document.currentScript;
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-print-plan]');
        if (button) { event.preventDefault(); window.print(); }
    });
    if (script && script.hasAttribute('data-auto-print')) {
        window.addEventListener('load', function () { window.print(); });
    }
})();
