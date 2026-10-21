/* Counting numbers.

   An element marked data-count-up holds its final number in the page ("25+", "221+", "5.0", "1,240", "44"). When it
   scrolls into view the number counts up from zero to that value, once. Nothing here is needed to read the page: with
   no script, with reduced motion, or in a browser without IntersectionObserver the number simply shows as written, and
   a screen reader is always given the final number, never a half-counted one. */
(function () {
    'use strict';

    var els = [].slice.call(document.querySelectorAll('[data-count-up]'));
    if (!els.length) return;
    if (!('IntersectionObserver' in window) || !window.requestAnimationFrame) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    var DURATION = 1500;

    // "25+" -> prefix "", number 25, suffix "+"; "1,240" keeps its comma; "5.0" keeps its decimal.
    function parse(text) {
        var m = /^(\D*?)(\d[\d,]*(?:\.\d+)?)(\D*)$/.exec(text.trim());
        if (!m) return null;
        var digits = m[2];
        return {
            prefix: m[1], suffix: m[3],
            target: parseFloat(digits.replace(/,/g, '')),
            decimals: (digits.split('.')[1] || '').length,
            commas: digits.indexOf(',') > -1,
            text: text.trim()
        };
    }

    function format(value, p) {
        var s = value.toFixed(p.decimals);
        if (p.commas) {
            var parts = s.split('.');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            s = parts.join('.');
        }
        return p.prefix + s + p.suffix;
    }

    // The animated text is hidden from assistive technology and the final number is given to it in plain text.
    function prepare(el, p) {
        el.textContent = '';
        var view = document.createElement('span');
        view.setAttribute('aria-hidden', 'true');
        view.textContent = p.text;
        var plain = document.createElement('span');
        plain.className = 'sr-only';
        plain.textContent = p.text;
        el.appendChild(view);
        el.appendChild(plain);
        return view;
    }

    function run(view, p) {
        var start = null;
        view.textContent = format(0, p);

        function frame(now) {
            if (start === null) start = now;
            var k = Math.min(1, (now - start) / DURATION);
            var eased = 1 - Math.pow(1 - k, 3);                  // fast at first, settling on the number
            view.textContent = k < 1 ? format(p.target * eased, p) : p.text;
            if (k < 1) window.requestAnimationFrame(frame);
        }
        window.requestAnimationFrame(frame);
    }

    var seen = new window.IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;
            seen.unobserve(entry.target);
            var p = entry.target._count;
            if (p) run(p.view, p);
        });
    }, { threshold: 0.5 });

    els.forEach(function (el) {
        var p = parse(el.textContent);
        if (!p || !isFinite(p.target)) return;
        p.view = prepare(el, p);
        el._count = p;
        seen.observe(el);
    });
})();
