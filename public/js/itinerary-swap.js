/*
 * Similar-place swaps on the itinerary page.
 * Each [data-swap] block has a swap button that opens a comparison panel under its card: a header of its own ("Similar
 * places", with a close button) and below it the most similar places, ranked by the pretrained embedding model on the
 * server, each with a button to use it instead. All text is inserted with textContent, never as HTML.
 */
(function () {
    'use strict';

    var SVG_NS = 'http://www.w3.org/2000/svg';

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    // A small line icon, built from fixed path data (never from the server).
    function icon(paths, className) {
        var svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        if (className) svg.setAttribute('class', className);
        paths.forEach(function (d) {
            var path = document.createElementNS(SVG_NS, 'path');
            path.setAttribute('d', d);
            svg.appendChild(path);
        });
        return svg;
    }

    var CLOSE_PATHS = ['M6 6l12 12', 'M18 6 6 18'];
    var PLACE_PATHS = ['M3 19l6-9 4 6 3-4 5 7z', 'M16 6.5a2 2 0 1 0 0 .01'];

    // The panel's own header and an empty body for the list. Built once per stop.
    function buildFrame(block, panel, toggle) {
        panel.textContent = '';

        var head = el('div', 'swap-panel__head');
        var titles = el('div', 'swap-panel__titles');
        titles.appendChild(el('p', 'swap-panel__title', 'Similar places'));
        titles.appendChild(el('p', 'swap-panel__sub', 'Ranked by how closely they match this stop'));
        head.appendChild(titles);

        var close = el('button', 'swap-panel__close');
        close.type = 'button';
        close.setAttribute('aria-label', 'Close similar places');
        close.appendChild(icon(CLOSE_PATHS));
        close.addEventListener('click', function () {
            setOpen(panel, toggle, false);
            toggle.focus();
        });
        head.appendChild(close);

        var body = el('div', 'swap-panel__body');
        panel.appendChild(head);
        panel.appendChild(body);

        return body;
    }

    function setOpen(panel, toggle, open) {
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
    }

    function render(block, body, data) {
        body.textContent = '';

        if (!data.alternatives.length) {
            body.appendChild(el('p', 'swap-empty', 'No similar open places were found nearby for this stop.'));
            return;
        }

        var list = el('ul', 'swap-list');

        data.alternatives.forEach(function (alt) {
            var li = el('li', 'swap-option');

            var thumb = el('span', 'swap-option__thumb');
            thumb.appendChild(icon(PLACE_PATHS));
            li.appendChild(thumb);

            var info = el('div', 'swap-option__info');

            var top = el('div', 'swap-option__top');
            var link = el('a', 'swap-option__name', alt.name);
            link.href = alt.url;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            top.appendChild(link);
            top.appendChild(el('span', 'swap-chip', alt.similarity + '%'));
            if (alt.sample) {
                var tag = el('span', 'swap-sample', 'Sample data');
                tag.title = "Sample data, not DOT-verified: this place's accreditation number is a placeholder, not an official DOT number.";
                top.appendChild(tag);
            }
            info.appendChild(top);

            var meta = [];
            if (alt.type) meta.push(alt.type);
            if (alt.distance_km !== null) meta.push(alt.distance_km + ' km away');
            if (meta.length) info.appendChild(el('span', 'swap-option__meta', meta.join(' · ')));
            // Interest fit and opening hours: plain gray, because "not one of your picked interests" is not good news.
            if (alt.reason) info.appendChild(el('span', 'swap-option__reason', alt.reason));
            li.appendChild(info);

            var form = el('form');
            form.method = 'POST';
            form.action = block.dataset.swapUrl;
            [['_token', block.dataset.token], ['original_id', block.dataset.destination], ['replacement_id', alt.id]]
                .forEach(function (pair) {
                    var input = el('input');
                    input.type = 'hidden';
                    input.name = pair[0];
                    input.value = pair[1];
                    form.appendChild(input);
                });
            var button = el('button', 'btn btn-outline swap-use', 'Use this instead');
            button.type = 'submit';
            form.appendChild(button);
            li.appendChild(form);

            list.appendChild(li);
        });

        body.appendChild(list);
    }

    document.querySelectorAll('[data-swap]').forEach(function (block) {
        var panel = block.querySelector('[data-swap-panel]');
        // The button lives in the card's action column, beside the heart; it points at its panel by id.
        var toggle = panel && document.querySelector('[data-swap-toggle][aria-controls="' + panel.id + '"]');
        if (!panel || !toggle) return;
        var body = null;
        var loaded = false;

        panel.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                setOpen(panel, toggle, false);
                toggle.focus();
            }
        });

        toggle.addEventListener('click', function () {
            var open = panel.hidden;
            setOpen(panel, toggle, open);

            if (!open || loaded) return;

            if (!body) body = buildFrame(block, panel, toggle);
            body.textContent = '';
            body.appendChild(el('p', 'swap-empty', 'Finding similar places…'));

            fetch(block.dataset.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (response) {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.json();
                })
                .then(function (data) {
                    loaded = true;
                    render(block, body, data);
                })
                .catch(function () {
                    body.textContent = '';
                    body.appendChild(el('p', 'swap-empty', 'Could not load suggestions. Please try again.'));
                });
        });
    });
})();
