/*
 * Trip form, "Where you're from" and "Where you start".
 *
 *   - Where are you visiting from?  A picker over a short list of cities and countries, grouped Philippines then
 *     abroad. Anything typed is still accepted: the list only saves typing and keeps the spelling the same from one
 *     visitor to the next, which is what the visitor-origin figures need.
 *   - Common arrival points: a tap fills the start box with the place's name and its coordinates, so the plan starts
 *     exactly there and does not depend on a lookup finding the place by name.
 *
 * Without JavaScript the origin is a plain text box and the arrival points stay hidden, so none of this is needed
 * to submit the form.
 */
(() => {
    'use strict';

    // ---- where are you visiting from
    const input = document.getElementById('place_of_origin');
    const list = document.getElementById('origin-from-suggestions');

    const PLACES = [
        ['Philippines', ['Manila, Metro Manila', 'Quezon City, Metro Manila', 'Makati, Metro Manila', 'Cebu City, Cebu', 'Cagayan de Oro, Misamis Oriental',
            'Iloilo City, Iloilo', 'Bacolod, Negros Occidental', 'General Santos, South Cotabato', 'Zamboanga City, Zamboanga del Sur',
            'Baguio, Benguet', 'Angeles, Pampanga', 'Tacloban, Leyte', 'Butuan, Agusan del Norte', 'Cotabato City, Maguindanao del Norte']],
        ['Abroad', ['South Korea', 'Japan', 'China', 'Taiwan', 'Hong Kong', 'Singapore', 'Malaysia', 'Thailand', 'Vietnam', 'Indonesia',
            'United States', 'Canada', 'United Kingdom', 'Germany', 'France', 'Australia', 'United Arab Emirates', 'Saudi Arabia']],
    ];
    const HEADINGS = {Philippines: 'In the Philippines', Abroad: 'From abroad'};
    const MAX = 8;

    if (input && list) {
        let shown = [];
        let active = -1;

        const close = () => {
            list.hidden = true;
            list.innerHTML = '';
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            active = -1;
        };

        const choose = index => {
            if (!shown[index]) return;
            input.value = shown[index];
            input.dispatchEvent(new Event('change', {bubbles: true}));
            close();
        };

        const highlight = index => {
            list.querySelectorAll('[role="option"]').forEach((el, i) => {
                el.classList.toggle('is-active', i === index);
                el.setAttribute('aria-selected', i === index ? 'true' : 'false');
                if (i === index) {
                    input.setAttribute('aria-activedescendant', el.id);
                    el.scrollIntoView({block: 'nearest'});
                }
            });
            active = index;
        };

        const render = () => {
            const query = input.value.trim().toLowerCase();
            // The start of a word, so "ma" finds Manila and Malaysia but not Germany.
            const starts = name => (` ${name.toLowerCase().replace(/,/g, ' ')}`).includes(` ${query}`);
            shown = [];
            list.innerHTML = '';

            PLACES.forEach(([group, names]) => {
                const hits = names.filter(name => !query || starts(name)).slice(0, MAX - shown.length);
                if (!hits.length) return;

                const heading = document.createElement('li');
                heading.className = 'pc-places__group';
                heading.setAttribute('role', 'presentation');
                heading.textContent = HEADINGS[group];
                list.appendChild(heading);

                hits.forEach(name => {
                    const li = document.createElement('li');
                    li.id = `origin-from-${shown.length}`;
                    li.setAttribute('role', 'option');
                    li.setAttribute('aria-selected', 'false');
                    li.textContent = name;
                    const index = shown.length;
                    // mousedown, not click: the blur that follows a click would close the list first.
                    li.addEventListener('mousedown', event => { event.preventDefault(); choose(index); });
                    list.appendChild(li);
                    shown.push(name);
                });
            });

            if (!shown.length) { close(); return; }
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            active = -1;
        };

        input.addEventListener('focus', render);
        input.addEventListener('input', render);
        input.addEventListener('blur', () => setTimeout(close, 120));
        input.addEventListener('keydown', event => {
            if (list.hidden) return;
            if (event.key === 'ArrowDown') { event.preventDefault(); highlight((active + 1) % shown.length); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); highlight(active <= 0 ? shown.length - 1 : active - 1); }
            else if (event.key === 'Enter' && active >= 0) { event.preventDefault(); choose(active); }
            else if (event.key === 'Escape') close();
        });
    }

    // ---- common arrival points, and the start box for a local
    const start = document.getElementById('origin_label');
    const lat = document.getElementById('origin_lat');
    const lng = document.getElementById('origin_lng');
    const status = document.getElementById('origin-status');
    const clear = document.getElementById('origin-clear');
    const points = document.querySelector('[data-arrival-points]');

    if (start && points) {
        points.hidden = false;
        points.querySelectorAll('[data-start-pick]').forEach(button => button.addEventListener('click', () => {
            start.value = button.dataset.startPick;
            if (lat) lat.value = button.dataset.lat || '';
            if (lng) lng.value = button.dataset.lng || '';
            if (clear) clear.hidden = false;
            if (status) status.textContent = `Starting from ${button.textContent.trim()}.`;
            start.focus({preventScroll: true}); // the page stays where it is
        }));
    }

})();
