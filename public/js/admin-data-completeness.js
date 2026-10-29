/*
 * Data completeness: fix a gap on the spot.
 *
 * Every gap in the list is a link to the listing's edit form, so without JavaScript each one still goes somewhere
 * useful. With it, a gap that can be closed by typing one thing (a map position, opening hours, a photo credit)
 * opens a small editor under its row; saving posts it, and the row, the checks and the ring are redrawn from what
 * the server answers, so the numbers on the page are always the server's.
 *
 * Anything typed is put into the page as text, never as HTML.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-completeness]');
    var templates = document.getElementById('completenessFixes');
    if (!root || !templates) return;

    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var work = root.querySelector('[data-work]');
    var open = null;                        // { row, check, editor }
    var TYPABLE = ['coords', 'hours', 'credit'];

    function closeEditor() {
        if (!open) return;
        open.editor.remove();
        open.chip.classList.remove('open');
        open = null;
    }

    function openEditor(chip) {
        var row = chip.closest('tr');
        var check = chip.dataset.miss;
        var fix = chip.dataset.fix;

        if (open && open.chip === chip) { closeEditor(); return; }
        closeEditor();

        var form = templates.content.querySelector('[data-fix-form="' + fix + '"]');
        if (!form) return;

        var tr = document.createElement('tr');
        tr.className = 'adm-fix';
        var td = document.createElement('td');
        td.colSpan = 2;
        var box = document.createElement('div');
        box.className = 'adm-fix__box';
        box.appendChild(form.cloneNode(true));
        var inner = box.firstElementChild;

        var link = inner.querySelector('[data-edit-link]');
        if (link) link.href = chip.href;

        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn btn-ghost';
        cancel.textContent = TYPABLE.indexOf(fix) > -1 ? 'Cancel' : 'Close';
        cancel.addEventListener('click', closeEditor);
        inner.appendChild(cancel);

        td.appendChild(box);
        tr.appendChild(td);
        row.parentNode.insertBefore(tr, row.nextSibling);
        chip.classList.add('open');
        open = { row: row, check: check, fix: fix, editor: tr, chip: chip, box: inner };

        var input = inner.querySelector('input');
        if (input) input.focus();
    }

    function hint(message, isError) {
        var el = open && open.box.querySelector('[data-hint]');
        if (!el) return;
        el.textContent = message;
        el.classList.toggle('is-error', !!isError);
    }

    function save() {
        if (!open || TYPABLE.indexOf(open.fix) < 0) return;
        var data = new FormData();
        data.append('check', open.check);
        open.box.querySelectorAll('input').forEach(function (input) { data.append(input.name, input.value); });
        var button = open.box.querySelector('[data-save]');
        if (button) button.disabled = true;

        fetch(root.dataset.fixUrl + '/' + open.row.dataset.row, {
            method: 'POST',
            body: data,
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (body) { return { ok: response.ok, status: response.status, body: body }; });
        }).then(function (result) {
            if (button) button.disabled = false;
            if (!result.ok) {
                var errors = result.body.errors || {};
                var first = Object.keys(errors)[0];
                hint(first ? errors[first][0] : (result.status === 419 ? 'Your session expired. Refresh the page and try again.' : 'We could not save this. Please try again.'), true);
                return;
            }
            applyResult(result.body);
        }).catch(function () {
            if (button) button.disabled = false;
            hint('Connection interrupted. Check your connection and try again.', true);
        });
    }

    var ICONS = { accred: 'shield-check', coords: 'map-pin', hours: 'clock', embed: 'sparkles', credit: 'lock', desc: 'text', photo: 'image' };

    function applyResult(body) {
        var row = open.row;
        closeEditor();

        // the row: keep only the gaps that are still open
        var still = body.row.miss;
        row.querySelectorAll('[data-miss]').forEach(function (chip) { if (still.indexOf(chip.dataset.miss) < 0) chip.remove(); });
        if (still.length === 0) {
            var cell = row.querySelector('[data-misses]');
            cell.textContent = '';
            var pill = document.createElement('span');
            pill.className = 'status-pill status-active';
            pill.textContent = 'All fixed';
            cell.appendChild(pill);
            row.classList.add('adm-rowdone');
        }

        // the checks on the left, the filter chips, and the ring
        var s = body.summary;
        Object.keys(s.missing).forEach(function (check) {
            var missing = s.missing[check];
            var percent = s.total === 0 ? 100 : Math.round((1 - missing / s.total) * 100);
            var chk = root.querySelector('[data-check="' + check + '"]');
            if (chk) {
                chk.querySelector('[data-chk-count]').textContent = missing > 0 ? missing + ' of ' + s.total + ' missing' : 'all done';
                var bar = chk.querySelector('[data-chk-bar]');
                bar.className = 'adm-bar ' + (percent < 40 ? 'bad' : percent < 80 ? 'warn' : '');
                bar.firstElementChild.style.width = percent + '%';
            }
            var chip = root.querySelector('[data-filter="' + check + '"] [data-filter-count]');
            if (chip) chip.textContent = missing;
        });
        var ring = root.querySelector('[data-ring]');
        ring.style.setProperty('--p', s.readiness);
        ring.style.setProperty('--c', s.readiness >= 80 ? 'var(--primary)' : s.readiness >= 50 ? '#e0a100' : 'var(--danger)');
        root.querySelector('[data-ring-value]').textContent = s.readiness + '%';

        var note = root.querySelector('[data-ready-note]');
        var worst = null;
        Object.keys(s.missing).forEach(function (c) {
            if (s.missing[c] > 0 && (worst === null || s.missing[c] / s.total > s.missing[worst] / s.total)) worst = c;
        });
        var label = worst ? (root.querySelector('[data-check="' + worst + '"] .adm-chk__t') || {}).firstChild : null;
        note.textContent = worst && label ? 'Biggest gap: ' + label.textContent.trim().toLowerCase() + ' (' + s.missing[worst] + ' of ' + s.total + ' missing).' : 'Everything on this list is complete.';

        if (window.showToast) window.showToast('success', body.message, body.detail);
    }

    work.addEventListener('click', function (event) {
        var chip = event.target.closest('[data-miss]');
        if (!chip || event.ctrlKey || event.metaKey || event.shiftKey) return;
        event.preventDefault();
        openEditor(chip);
    });

    work.addEventListener('click', function (event) {
        if (event.target.closest('[data-save]')) save();
    });

    document.addEventListener('keydown', function (event) {
        if (!open) return;
        if (event.key === 'Escape') { closeEditor(); return; }
        if (event.key === 'Enter' && event.target.closest('.adm-fix') && event.target.tagName === 'INPUT') { event.preventDefault(); save(); }
    });
})();
