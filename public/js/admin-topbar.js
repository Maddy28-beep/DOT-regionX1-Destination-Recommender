/*
 * DOT Admin top bar: the menus (+ New, what needs attention, the account) and the search.
 *
 * Menus: one open at a time. A click elsewhere or Tab closes it; Esc closes it and gives focus back to its button; the
 * arrow keys, Home and End move through its items. The search asks the server (/portal/admin/search) as the admin
 * types, and everything that comes back is put into the page as text, never as HTML.
 *
 * Ctrl K (or Cmd K), or "/" outside a field, jumps into the search.
 */
(function () {
    'use strict';

    var menus = [].slice.call(document.querySelectorAll('[data-menu]'));
    var searchBox = document.getElementById('admSearch');
    var input = document.getElementById('admSearchInput');
    var list = document.getElementById('admSearchList');

    // ---------------- menus ----------------
    function closeMenus(except) {
        menus.forEach(function (menu) {
            if (menu === except) return;
            menu.querySelector('[data-menu-btn]').setAttribute('aria-expanded', 'false');
            menu.querySelector('.adm-pop').hidden = true;
        });
    }

    menus.forEach(function (menu) {
        var button = menu.querySelector('[data-menu-btn]');
        var pop = menu.querySelector('.adm-pop');
        var items = function () { return [].slice.call(pop.querySelectorAll('[role="menuitem"], a.adm-att')); };

        function open(focusFirst) {
            closeMenus(menu);
            closeSearch();
            pop.hidden = false;
            button.setAttribute('aria-expanded', 'true');
            if (focusFirst && items()[0]) items()[0].focus();
        }
        function close(returnFocus) {
            pop.hidden = true;
            button.setAttribute('aria-expanded', 'false');
            if (returnFocus) button.focus();
        }

        button.addEventListener('click', function () { if (pop.hidden) open(false); else close(false); });
        button.addEventListener('keydown', function (event) { if (event.key === 'ArrowDown') { event.preventDefault(); open(true); } });
        pop.addEventListener('keydown', function (event) {
            var all = items();
            var i = all.indexOf(document.activeElement);
            if (!all.length) { if (event.key === 'Escape') close(true); return; }
            if (event.key === 'ArrowDown') { event.preventDefault(); all[(i + 1) % all.length].focus(); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); all[(i - 1 + all.length) % all.length].focus(); }
            else if (event.key === 'Home') { event.preventDefault(); all[0].focus(); }
            else if (event.key === 'End') { event.preventDefault(); all[all.length - 1].focus(); }
            else if (event.key === 'Escape') { event.preventDefault(); close(true); }
            else if (event.key === 'Tab') close(false);
        });
        // choosing something closes the menu; the add links are opened by the modal script, which listens on the page
        pop.addEventListener('click', function (event) { if (event.target.closest('a')) close(false); });
    });

    document.addEventListener('mousedown', function (event) {
        var target = event.target.closest ? event.target : document.body;
        if (!target.closest('[data-menu]')) closeMenus();
        if (!target.closest('#admSearch')) closeSearch();
    });

    if (!input || !list) return;

    // ---------------- search ----------------
    var ICONS = {
        'map-pin': '<path d="M12 21s7-6.5 7-12a7 7 0 10-14 0c0 5.5 7 12 7 12z"/><circle cx="12" cy="9" r="2.3"/>',
        'building': '<path d="M3 21V9l9-6 9 6v12"/><path d="M9 21v-6h6v6"/>',
        'utensils': '<path d="M3 2v7a3 3 0 003 3v10M7 2v7M11 2v7a3 3 0 01-3 3"/><path d="M17 2c-2 2-3 5-3 8h3v12"/>',
        'package': '<path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'bag': '<path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 01-8 0"/>',
        'van': '<path d="M3 16V7a1 1 0 011-1h10l5 5v5"/><path d="M3 16h18"/><circle cx="7.5" cy="17.5" r="1.8"/><circle cx="16.5" cy="17.5" r="1.8"/>',
        'alert-triangle': '<path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        'calendar': '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'camera': '<path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/>',
        'target': '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'database': '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'chat': '<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>',
        'link': '<path d="M10 13a5 5 0 007.5.5l3-3a5 5 0 00-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 00-7.5-.5l-3 3a5 5 0 007 7l1.7-1.7"/>',
        'shield-check': '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>',
        'clock': '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'chart': '<path d="M18 20V10M12 20V4M6 20v-6"/>'
    };
    var TYPING_DELAY = 180;
    var shown = [];
    var cursor = -1;
    var timer = null;
    var request = null;
    var lastTerm = null;

    function svg(name) {
        var el = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        el.setAttribute('viewBox', '0 0 24 24');
        el.setAttribute('fill', 'none');
        el.setAttribute('stroke', 'currentColor');
        el.setAttribute('stroke-width', '2');
        el.setAttribute('stroke-linecap', 'round');
        el.setAttribute('stroke-linejoin', 'round');
        el.setAttribute('aria-hidden', 'true');
        el.innerHTML = ICONS[name] || ICONS['map-pin'];       // a fixed set of paths from this file, never server text
        return el;
    }

    // "Aliwagwag" with "wag" matched: text nodes around a <mark>, so what the server sent is never read as markup.
    function highlight(target, text, term) {
        var at = term ? text.toLowerCase().indexOf(term.toLowerCase()) : -1;
        if (at < 0) { target.appendChild(document.createTextNode(text)); return; }
        target.appendChild(document.createTextNode(text.slice(0, at)));
        var mark = document.createElement('mark');
        mark.textContent = text.slice(at, at + term.length);
        target.appendChild(mark);
        target.appendChild(document.createTextNode(text.slice(at + term.length)));
    }

    function hint() {
        var el = document.createElement('div');
        el.className = 'adm-pop__hint';
        [['↑ ↓', 'move'], ['↵', 'open'], ['Esc', 'close']].forEach(function (pair) {
            var span = document.createElement('span');
            var kbd = document.createElement('kbd');
            kbd.textContent = pair[0];
            span.appendChild(kbd);
            span.appendChild(document.createTextNode(' ' + pair[1]));
            el.appendChild(span);
        });
        return el;
    }

    function message(text) {
        list.textContent = '';
        var p = document.createElement('p');
        p.className = 'adm-pop__empty';
        p.textContent = text;
        list.appendChild(p);
        shown = [];
        cursor = -1;
        input.removeAttribute('aria-activedescendant');
    }

    function select(index) {
        cursor = index;
        [].forEach.call(list.querySelectorAll('.adm-res'), function (el, i) { el.setAttribute('aria-selected', String(i === index)); });
        var current = list.querySelector('.adm-res[aria-selected="true"]');
        if (current) { input.setAttribute('aria-activedescendant', current.id); current.scrollIntoView({ block: 'nearest' }); }
        else input.removeAttribute('aria-activedescendant');
    }

    function render(results, term) {
        list.textContent = '';
        shown = results;
        var group = '';
        results.forEach(function (result, i) {
            if (result.group !== group) {
                group = result.group;
                var label = document.createElement('div');
                label.className = 'adm-pop__group';
                label.setAttribute('role', 'presentation');
                label.textContent = group;
                list.appendChild(label);
            }
            var row = document.createElement('div');
            row.className = 'adm-res';
            row.id = 'admRes' + i;
            row.setAttribute('role', 'option');
            row.setAttribute('aria-selected', 'false');
            row.dataset.index = String(i);

            var icon = document.createElement('span');
            icon.className = 'adm-res__ic';
            icon.appendChild(svg(result.icon));
            var text = document.createElement('span');
            text.className = 'adm-res__t';
            highlight(text, result.title, term);
            var sub = document.createElement('small');
            sub.textContent = result.sub;
            text.appendChild(sub);
            var go = document.createElement('span');
            go.className = 'adm-res__go';
            go.textContent = result.action + ' ↵';

            row.appendChild(icon);
            row.appendChild(text);
            row.appendChild(go);
            list.appendChild(row);
        });
        list.appendChild(hint());
        select(results.length ? 0 : -1);
    }

    function openList() {
        closeMenus();
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }
    function closeSearch() {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        clearTimeout(timer);
        if (request) request.abort();
    }

    function ask() {
        var term = input.value.trim();
        if (term === lastTerm) return;
        lastTerm = term;
        if (request) request.abort();
        if (term.length < 2) { message('Type at least two letters to search.'); return; }

        request = new AbortController();
        var url = new URL(searchBox.dataset.url, window.location.href);
        url.searchParams.set('q', term);
        fetch(url.toString(), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal: request.signal })
            .then(function (response) { if (!response.ok) throw new Error('HTTP ' + response.status); return response.json(); })
            .then(function (data) {
                if (input.value.trim() !== term) return;       // the box has moved on
                if (!data.results.length) message('No matches for “' + term + '”. Try part of a name, or a page like “reports”.');
                else render(data.results, term);
            })
            .catch(function (error) { if (error.name !== 'AbortError') message('Search is not available right now. Try again in a moment.'); });
    }

    function go(index) {
        var result = shown[index];
        if (!result) return;
        closeSearch();
        input.value = '';
        lastTerm = null;
        input.blur();
        if (result.modal) {
            // The modal script opens whatever is marked data-modal-form, so give it a link to open.
            var link = document.createElement('a');
            link.href = result.url;
            link.setAttribute('data-modal-form', '');
            link.dataset.title = result.modal;
            link.dataset.sub = result.title;
            link.hidden = true;
            document.body.appendChild(link);
            link.click();
            link.remove();
        } else {
            window.location.assign(result.url);
        }
    }

    function start() {
        openList();
        if (input.value.trim().length < 2) { lastTerm = null; message('Type at least two letters to search.'); }
        else { lastTerm = null; ask(); }
    }

    input.addEventListener('focus', start);
    input.addEventListener('input', function () {
        if (list.hidden) openList();
        clearTimeout(timer);
        timer = setTimeout(ask, TYPING_DELAY);
    });
    input.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown') { event.preventDefault(); if (list.hidden) start(); else select(Math.min(shown.length - 1, cursor + 1)); }
        else if (event.key === 'ArrowUp') { event.preventDefault(); select(Math.max(0, cursor - 1)); }
        else if (event.key === 'Enter') { event.preventDefault(); if (cursor > -1) go(cursor); }
        else if (event.key === 'Escape') {
            if (input.value) { input.value = ''; lastTerm = null; message('Type at least two letters to search.'); }
            else { closeSearch(); input.blur(); }
        }
    });
    list.addEventListener('mousedown', function (event) {
        var row = event.target.closest('.adm-res');
        if (row) { event.preventDefault(); go(Number(row.dataset.index)); }
    });
    list.addEventListener('mousemove', function (event) {
        var row = event.target.closest('.adm-res');
        if (row && Number(row.dataset.index) !== cursor) select(Number(row.dataset.index));
    });

    document.addEventListener('keydown', function (event) {
        var typing = /^(input|textarea|select)$/i.test(event.target.tagName || '') || event.target.isContentEditable;
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); input.focus(); input.select(); start(); }
        else if (event.key === '/' && !typing && !document.querySelector('dialog[open]')) { event.preventDefault(); input.focus(); start(); }
    });
})();
