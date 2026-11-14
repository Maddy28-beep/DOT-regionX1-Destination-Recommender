/* Copies a promo code when its chip is pressed, and says so for a moment. Without script the code is still printed on the chip. */
(function () {
    function done(button, text) {
        var msg = button.querySelector('[data-copy-msg]');
        if (!msg) { return; }
        msg.textContent = text;
        window.setTimeout(function () { msg.textContent = 'Copy'; }, 1800);
    }

    function fallback(code) {
        var field = document.createElement('textarea');
        field.value = code;
        field.setAttribute('readonly', '');
        field.style.position = 'absolute';
        field.style.left = '-9999px';
        document.body.appendChild(field);
        field.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(field);
        return ok;
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-copy-code]');
        if (!button) { return; }
        var code = button.getAttribute('data-copy-code');

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(code).then(function () { done(button, 'Copied'); }, function () { done(button, fallback(code) ? 'Copied' : 'Select it'); });
        } else {
            done(button, fallback(code) ? 'Copied' : 'Select it');
        }
    });
}());
