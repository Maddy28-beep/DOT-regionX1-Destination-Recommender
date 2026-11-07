/* Trip form, step 1, on a phone: the three optional parts (arrival time, departure details, where you start) fold behind
   a one-line row so the step is not four screens long. Nothing is removed or renamed: the fields stay in the page and in the
   submitted form, and the CSS only hides them on a phone (public/css/plan-trip-layout.css). A part opens by itself when it
   already holds an answer (a saved draft, a server error) or when the browser's own check lands on a field inside it, so a
   hidden field can never block "Next". Without JavaScript nothing is folded. */
(function () {
    'use strict';

    var step = document.getElementById('plan-step-0');
    if (!step) return;

    // Arrival time already holds a starting choice (Morning), so on its own that does not open it: the row shows the choice
    // instead ("Morning"), and opens by itself only for an error. The other two open when they hold something.
    function arrivalChoice(section) {
        var pressed = section.querySelector('.pc-slot[aria-pressed="true"] strong');
        var time = section.querySelector('input[type="time"]');
        if (pressed) return pressed.textContent.trim();
        return time && time.value ? time.value : '';
    }
    var parts = [
        { selector: '[data-arrival]', label: 'Arrival time', choice: arrivalChoice },
        { selector: '.pc-trip-departure', label: 'Departure details' },
        { selector: '.pc-trip-start', label: 'Where you start' }
    ];

    function holdsAnswer(section) {
        if (section.querySelector('.is-error, [role="alert"]')) return true;
        var fields = section.querySelectorAll('input:not([type="hidden"]):not([type="radio"]):not([type="checkbox"]), textarea');
        for (var i = 0; i < fields.length; i++) {
            if (fields[i].value && fields[i].value.trim() !== '') return true;
        }
        return false;
    }

    var built = [];

    parts.forEach(function (part, index) {
        var section = step.querySelector(part.selector);
        if (!section) return;

        var id = section.id || 'plan-collapsible-' + index;
        section.id = id;
        section.classList.add('pc-collapsible');

        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'pc-collapse pc-col-12';
        toggle.setAttribute('aria-controls', id);

        var name = document.createElement('span');
        name.className = 'pc-collapse__name';
        name.textContent = part.label;
        var hint = document.createElement('span');
        hint.className = 'pc-collapse__hint';
        toggle.appendChild(name);
        toggle.appendChild(hint);
        section.parentNode.insertBefore(toggle, section);

        function refresh() {
            var open = !section.classList.contains('is-collapsed');
            var shown = part.choice ? part.choice(section) : (holdsAnswer(section) ? 'Added' : '');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            hint.textContent = open ? 'Hide' : (shown || 'Optional');
        }
        function setOpen(open) {
            section.classList.toggle('is-collapsed', !open);
            refresh();
        }

        toggle.addEventListener('click', function () {
            setOpen(section.classList.contains('is-collapsed'));
        });
        section.addEventListener('input', refresh);
        section.addEventListener('change', refresh);
        section.addEventListener('click', function () { window.setTimeout(refresh, 0); });

        setOpen(part.choice ? !!section.querySelector('.is-error, [role="alert"]') : holdsAnswer(section));
        built.push({ section: section, open: function () { setOpen(true); } });
    });

    // The browser's check stops on the first invalid field; if that field sits inside a folded part, open the part so it can
    // be reached and the message can show.
    step.closest('form') && step.closest('form').addEventListener('invalid', function (event) {
        for (var i = 0; i < built.length; i++) {
            if (built[i].section.contains(event.target)) built[i].open();
        }
    }, true);
})();
