/* Create account: live checks for the alias, the password and the confirmation. Progressive enhancement only: the form
   still validates on the server, and without this script it works as a plain form. */
(function () {
    'use strict';
    var form = document.querySelector('[data-register-form]');
    if (!form) { return; }

    var alias = form.querySelector('#alias');
    var pw = form.querySelector('#password');
    var confirm = form.querySelector('#password_confirmation');
    var aliasOk = form.querySelector('[data-alias-ok]');
    var strength = form.querySelector('[data-strength]');
    var strengthText = form.querySelector('[data-strength-text]');
    var match = form.querySelector('[data-match]');
    var checks = {
        length: form.querySelector('[data-check="length"]'),
        mix: form.querySelector('[data-check="mix"]')
    };
    var WORDS = ['', 'Weak password', 'Fair password', 'Good password', 'Strong password'];

    function setCheck(el, ok) { if (el) { el.classList.toggle('is-ok', ok); } }

    function aliasCheck() {
        var ok = /^[A-Za-z0-9_-]{3,30}$/.test(alias.value);
        aliasOk.hidden = !ok;
    }

    function passwordCheck() {
        var v = pw.value;
        var long = v.length >= 8;
        var mix = /[A-Za-z]/.test(v) && /[0-9]/.test(v);
        var symbol = /[^A-Za-z0-9]/.test(v);
        // 0 for empty; otherwise one step each for length, 12+ characters, a letter/number mix and a symbol.
        var level = v === '' ? 0 : Math.max(1, (long ? 1 : 0) + (v.length >= 12 ? 1 : 0) + (mix ? 1 : 0) + (symbol ? 1 : 0));
        if (!long && v !== '') { level = 1; }
        strength.dataset.level = String(level);
        strengthText.textContent = WORDS[level];
        setCheck(checks.length, long);
        setCheck(checks.mix, mix);
        matchCheck();
    }

    function matchCheck() {
        match.classList.remove('is-ok', 'is-bad');
        if (confirm.value === '') { match.textContent = ''; return; }
        if (confirm.value === pw.value) {
            match.textContent = 'Passwords match.';
            match.classList.add('is-ok');
        } else {
            match.textContent = 'Passwords do not match yet.';
            match.classList.add('is-bad');
        }
    }

    alias.addEventListener('input', aliasCheck);
    pw.addEventListener('input', passwordCheck);
    confirm.addEventListener('input', matchCheck);
    aliasCheck();
    passwordCheck();
})();
