/* Establishment amenities editor: "Your amenities" strip, per-group counts, select all / clear, and the Renamed marker. */
(function () {
    function labelOf(box) {
        return box.closest('[data-amenity-option]').querySelector('[data-amenity-label]').textContent.trim();
    }

    function refresh(editor) {
        const checked = editor.querySelectorAll('input[name="amenities[]"]:checked');
        editor.querySelector('[data-amenity-count]').textContent = checked.length + ' selected';

        editor.querySelectorAll('.ptr-amenity-group').forEach(function (group) {
            const total = group.querySelectorAll('input[name="amenities[]"]').length;
            const on = group.querySelectorAll('input[name="amenities[]"]:checked').length;
            group.querySelector('[data-amenity-group-count]').textContent = on + '/' + total;
        });

        const list = editor.querySelector('[data-amenity-picks-list]');
        list.textContent = '';
        checked.forEach(function (box) {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'ptr-amenity-chip';
            chip.setAttribute('aria-label', 'Remove ' + labelOf(box));
            chip.textContent = labelOf(box) + ' \u00d7';
            chip.addEventListener('click', function () {
                box.checked = false;
                refresh(editor);
            });
            list.appendChild(chip);
        });
        editor.querySelector('[data-amenity-picks]').hidden = checked.length === 0;
    }

    document.addEventListener('change', function (event) {
        if (!event.target.matches('[data-amenity-editor] input[name="amenities[]"]')) return;
        refresh(event.target.closest('[data-amenity-editor]'));
    });

    document.addEventListener('click', function (event) {
        const editor = event.target.closest('[data-amenity-editor]');
        if (!editor) return;

        if (event.target.matches('[data-amenity-group-all], [data-amenity-group-none]')) {
            const on = event.target.matches('[data-amenity-group-all]');
            event.target.closest('.ptr-amenity-group').querySelectorAll('[data-amenity-option]:not([hidden]) input[name="amenities[]"]').forEach(function (box) { box.checked = on; });
            refresh(editor);
        } else if (event.target.matches('[data-amenity-clear-all]')) {
            editor.querySelectorAll('input[name="amenities[]"]').forEach(function (box) { box.checked = false; });
            refresh(editor);
        }
    });

    /* A typed display name replaces the label and marks the row Renamed; clearing it puts the category name back. */
    document.addEventListener('input', function (event) {
        if (!event.target.matches('[data-amenity-editor] input[name^="amenity_names"]')) return;
        const option = event.target.closest('[data-amenity-option]');
        const custom = event.target.value.trim();
        option.querySelector('[data-amenity-label]').textContent = custom || option.dataset.amenityOption;
        option.querySelector('[data-amenity-renamed]').hidden = custom === '';
        refresh(option.closest('[data-amenity-editor]'));
    });

    /* The editor is loaded into a modal after the page, so set it up whenever it appears. */
    function setup(root) {
        (root.matches && root.matches('[data-amenity-editor]') ? [root] : root.querySelectorAll('[data-amenity-editor]')).forEach(refresh);
    }
    document.addEventListener('DOMContentLoaded', function () { setup(document); });
    new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
            m.addedNodes.forEach(function (node) { if (node.nodeType === 1) setup(node); });
        });
    }).observe(document.documentElement, { childList: true, subtree: true });
})();

document.addEventListener('input', function (event) {
    if (!event.target.matches('[data-amenity-search]')) return;

    const editor = event.target.closest('[data-amenity-editor]');
    const term = event.target.value.trim().toLowerCase();
    let anyMatch = false;

    editor.querySelectorAll('.ptr-amenity-group').forEach(function (group) {
        let groupMatch = false;
        group.querySelectorAll('[data-amenity-option]').forEach(function (option) {
            const name = option.querySelector('input[type="text"]').value;
            const matches = (option.dataset.amenityOption + ' ' + name).toLowerCase().includes(term);
            option.hidden = !matches;
            groupMatch ||= matches;
        });
        group.hidden = !groupMatch;
        if (term && groupMatch) group.open = true;
        anyMatch ||= groupMatch;
    });
    editor.querySelector('[data-amenity-empty]').hidden = anyMatch;
});

/*
 * Partner portal: drag a photo to change the order.
 *
 * The up and down buttons on each photo still work without this (they are real forms), so dragging is the quick way,
 * not the only way. The new order is sent as the list of photo ids, first to last, and the page is left as it is.
 */
(function () {
    'use strict';

    var grid = document.getElementById('photo-grid');
    if (!grid || !grid.dataset.reorderUrl) return;

    var token = document.querySelector('meta[name="csrf-token"]');
    var dragged = null;

    function tiles() { return [].slice.call(grid.querySelectorAll('.ptr-photo')); }

    grid.addEventListener('dragstart', function (event) {
        var tile = event.target.closest('.ptr-photo');
        if (!tile) return;
        dragged = tile;
        tile.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        try { event.dataTransfer.setData('text/plain', tile.dataset.id); } catch (e) { /* some browsers refuse; the drag still works */ }
    });

    grid.addEventListener('dragover', function (event) {
        var tile = event.target.closest('.ptr-photo');
        if (!tile || !dragged || tile === dragged) return;
        event.preventDefault();
        tiles().forEach(function (t) { t.classList.toggle('is-over', t === tile); });
    });

    grid.addEventListener('drop', function (event) {
        var tile = event.target.closest('.ptr-photo');
        if (!tile || !dragged || tile === dragged) return;
        event.preventDefault();

        var all = tiles();
        // dropping on a tile puts the dragged photo where that tile was
        if (all.indexOf(dragged) < all.indexOf(tile)) tile.after(dragged); else tile.before(dragged);

        fetch(grid.dataset.reorderUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token ? token.content : '' },
            body: JSON.stringify({ order: tiles().map(function (t) { return Number(t.dataset.id); }) })
        }).then(function (response) {
            if (!response.ok) window.location.reload();   // the order on screen may not be the saved one: show what is saved
        }).catch(function () { window.location.reload(); });
    });

    grid.addEventListener('dragend', function () {
        dragged = null;
        tiles().forEach(function (t) { t.classList.remove('is-dragging', 'is-over'); });
    });
})();
