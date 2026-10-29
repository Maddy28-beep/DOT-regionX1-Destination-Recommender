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
