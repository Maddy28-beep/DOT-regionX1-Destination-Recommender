/*
 * Similar-place swaps on the itinerary page.
 * Each [data-swap] block has a button that loads the most similar places (ranked
 * by the pretrained embedding model on the server) and lets the traveller pick
 * one. All text is inserted with textContent, never as HTML.
 */
(function () {
    'use strict';

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function render(block, panel, data) {
        panel.textContent = '';

        if (!data.alternatives.length) {
            panel.appendChild(el('p', 'swap-empty', 'No similar open places were found nearby for this stop.'));
            return;
        }

        panel.appendChild(el('p', 'swap-heading', 'Most similar places, ranked by meaning'));
        var list = el('ul', 'swap-list');

        data.alternatives.forEach(function (alt) {
            var li = el('li', 'swap-option');
            var info = el('div', 'swap-option__info');

            var link = el('a', 'swap-option__name', alt.name);
            link.href = alt.url;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            info.appendChild(link);

            var meta = alt.similarity + '% similar';
            if (alt.type) meta += ' · ' + alt.type;
            if (alt.distance_km !== null) meta += ' · ' + alt.distance_km + ' km from this stop';
            info.appendChild(el('span', 'swap-option__meta', meta));
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

        panel.appendChild(list);
    }

    document.querySelectorAll('[data-swap]').forEach(function (block) {
        var toggle = block.querySelector('[data-swap-toggle]');
        var panel = block.querySelector('[data-swap-panel]');
        var loaded = false;

        toggle.addEventListener('click', function () {
            var open = panel.hidden;
            panel.hidden = !open;
            toggle.setAttribute('aria-expanded', String(open));

            if (!open || loaded) return;

            panel.textContent = '';
            panel.appendChild(el('p', 'swap-empty', 'Finding similar places…'));

            fetch(block.dataset.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (response) {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.json();
                })
                .then(function (data) {
                    loaded = true;
                    render(block, panel, data);
                })
                .catch(function () {
                    panel.textContent = '';
                    panel.appendChild(el('p', 'swap-empty', 'Could not load suggestions. Please try again.'));
                });
        });
    });
})();
