/* Events calendar: week-row month grid with multi-day bars, category filter,
   and the month / day panel. Reads the server-embedded #eventsData JSON; the
   server-rendered upcoming list stays in place if this never runs.

   card() mirrors resources/views/events/card.blade.php -- change both together. */
(function () {
    'use strict';

    var dataEl = document.getElementById('eventsData');
    var grid = document.getElementById('eventsGrid');
    if (!dataEl || !grid) return;

    var events;
    try { events = JSON.parse(dataEl.textContent) || []; } catch (e) { return; }

    var calendar = document.getElementById('eventsCalendar');
    var chips = document.getElementById('eventCategoryChips');
    var monthLabel = document.getElementById('eventsMonth');
    var detailTitle = document.getElementById('eventsDetailTitle');
    var detailSub = document.getElementById('eventsDetailSub');
    var detailList = document.getElementById('eventsDetailList');

    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var SHORT_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var MAX_LANES = 3;

    var PIN = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>';
    var CLOCK = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>';

    var now = new Date();
    var view = { year: now.getFullYear(), month: now.getMonth() };
    var category = 'all';
    var selected = null; // 'YYYY-MM-DD' or null (= the whole month)

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function iso(date) { return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()); }
    var todayIso = iso(now);

    function parse(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
    function dayDiff(a, b) { return Math.round((Date.UTC(+b.slice(0, 4), +b.slice(5, 7) - 1, +b.slice(8, 10)) - Date.UTC(+a.slice(0, 4), +a.slice(5, 7) - 1, +a.slice(8, 10))) / 86400000); }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text != null) node.textContent = text;
        return node;
    }

    function inCategory(e) { return category === 'all' || e.category === category; }
    function visibleEvents() { return events.filter(inCategory); }
    function eventsOn(day) { return visibleEvents().filter(function (e) { return e.start <= day && day <= e.end; }); }
    function monthBounds() {
        return { first: iso(new Date(view.year, view.month, 1)), last: iso(new Date(view.year, view.month + 1, 0)) };
    }
    function inMonth(list) {
        var b = monthBounds();
        return list.filter(function (e) { return e.start <= b.last && e.end >= b.first; });
    }

    /* ---------- Month grid ---------- */

    function weeksOfMonth() {
        var first = new Date(view.year, view.month, 1);
        var cursor = new Date(view.year, view.month, 1 - first.getDay());
        var last = new Date(view.year, view.month + 1, 0);
        var weeks = [];
        while (cursor <= last) {
            var week = [];
            for (var i = 0; i < 7; i++) {
                week.push({ iso: iso(cursor), d: cursor.getDate(), outside: cursor.getMonth() !== view.month });
                cursor = new Date(cursor.getFullYear(), cursor.getMonth(), cursor.getDate() + 1);
            }
            weeks.push(week);
        }
        return weeks;
    }

    function renderWeek(week) {
        var wrap = el('div', 'cal-week');
        var bg = el('div', 'cal-week__bg');
        var weekStart = week[0].iso, weekEnd = week[6].iso;

        week.forEach(function (day, col) {
            var count = eventsOn(day.iso).length;
            var cell = el('button', 'cal-cell' + (day.outside ? ' is-outside' : '') + (day.iso === selected ? ' is-selected' : ''));
            cell.type = 'button';
            cell.dataset.date = day.iso;
            var date = parse(day.iso);
            cell.setAttribute('aria-label', MONTHS[date.getMonth()] + ' ' + day.d + (count ? ', ' + count + (count === 1 ? ' event' : ' events') : ''));
            cell.setAttribute('aria-pressed', day.iso === selected ? 'true' : 'false');
            bg.appendChild(cell);

            var num = el('div', 'cal-num' + (day.outside ? ' is-outside' : '') + (day.iso === todayIso ? ' is-today' : ''));
            num.style.gridColumn = String(col + 1);
            num.appendChild(el('span', null, day.d));
            wrap.appendChild(num);
        });
        wrap.insertBefore(bg, wrap.firstChild);

        // Longest-first within a start day so a multi-day bar keeps one lane across the week.
        var inWeek = visibleEvents().filter(function (e) { return e.start <= weekEnd && e.end >= weekStart; })
            .sort(function (a, b) {
                return a.start < b.start ? -1 : a.start > b.start ? 1 : dayDiff(b.start, b.end) - dayDiff(a.start, a.end) || a.title.localeCompare(b.title);
            });

        var laneEnds = [];
        var hidden = [0, 0, 0, 0, 0, 0, 0];

        inWeek.forEach(function (e) {
            var from = e.start <= weekStart ? 0 : dayDiff(weekStart, e.start);
            var to = e.end >= weekEnd ? 6 : dayDiff(weekStart, e.end);

            var lane = 0;
            while (laneEnds[lane] !== undefined && laneEnds[lane] >= from) lane++;
            laneEnds[lane] = to;

            if (lane >= MAX_LANES) {
                for (var c = from; c <= to; c++) hidden[c]++;
                return;
            }

            var fromBefore = e.start < weekStart, runsOn = e.end > weekEnd;
            var bar = el('div', 'cal-bar cal-bar--' + e.category + (fromBefore ? ' cal-bar--from-before' : '') + (runsOn ? ' cal-bar--runs-on' : ''),
                (fromBefore ? '\u25C2 ' : '') + e.title);
            bar.setAttribute('aria-hidden', 'true');
            bar.style.gridRow = String(lane + 2);
            bar.style.gridColumn = (from + 1) + ' / ' + (to + 2);
            wrap.appendChild(bar);
        });

        hidden.forEach(function (n, col) {
            if (!n) return;
            var more = el('div', 'cal-more', '+' + n + ' more');
            more.style.gridColumn = String(col + 1);
            wrap.appendChild(more);
        });

        return wrap;
    }

    function renderGrid() {
        grid.textContent = '';
        monthLabel.textContent = MONTHS[view.month] + ' ' + view.year;
        weeksOfMonth().forEach(function (week) { grid.appendChild(renderWeek(week)); });
    }

    /* ---------- Chips ---------- */

    function renderChips() {
        var monthEvents = inMonth(events);
        chips.querySelectorAll('[data-category]').forEach(function (chip) {
            var key = chip.dataset.category;
            var n = key === 'all' ? monthEvents.length : monthEvents.filter(function (e) { return e.category === key; }).length;
            chip.querySelector('.chip__count').textContent = n;
            chip.classList.toggle('is-empty', n === 0);
        });
    }

    /* ---------- Cards ---------- */

    function countdown(e) {
        if (e.end < todayIso) return 'Ended';
        if (e.start <= todayIso) return e.start === e.end && e.start === todayIso ? 'Today' : 'Happening now';
        var days = dayDiff(todayIso, e.start);
        return days === 1 ? 'Tomorrow' : 'In ' + days + ' days';
    }

    function card(e) {
        var article = el('article', 'event-card event-card--' + e.category);
        article.innerHTML =
            '<div class="event-card__stub">' +
                '<span class="event-card__month">' + esc(e.month) + '</span>' +
                '<span class="event-card__day">' + esc(e.day) + '</span>' +
                '<span class="event-card__foot">' + esc(e.stubFoot) + '</span>' +
            '</div>' +
            '<div class="event-card__body">' +
                '<div class="event-card__top">' +
                    '<span class="event-card__tag">' + e.icon + ' ' + esc(e.categoryLabel) + '</span>' +
                    '<span class="event-card__when">' + esc(countdown(e)) + '</span>' +
                '</div>' +
                '<h3 class="event-card__title">' + esc(e.title) + '</h3>' +
                '<p class="event-card__meta">' + PIN + ' ' + esc(e.location) + '</p>' +
                (e.time ? '<p class="event-card__meta">' + CLOCK + ' ' + esc(e.time) + '</p>' : '') +
                (e.description ? '<p class="event-card__desc">' + esc(e.description) + '</p>' : '') +
            '</div>';

        var add = el('button', 'event-card__add', 'Add to calendar');
        add.type = 'button';
        add.addEventListener('click', function () { downloadIcs(e); });
        article.querySelector('.event-card__body').appendChild(add);
        return article;
    }

    function icsText(s) { return String(s || '').replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\r?\n/g, '\\n'); }
    function compact(s) { return s.replace(/-/g, ''); }

    function downloadIcs(e) {
        var after = parse(e.end);
        after.setDate(after.getDate() + 1);
        var endExclusive = iso(after);
        var lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//ExploreDVO//Events//EN', 'BEGIN:VEVENT',
            'UID:event-' + e.id + '@exploredvo',
            'DTSTAMP:' + new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d+/, ''),
            'DTSTART;VALUE=DATE:' + compact(e.start),
            'DTEND;VALUE=DATE:' + compact(endExclusive),
            'SUMMARY:' + icsText(e.title),
            'LOCATION:' + icsText(e.location),
            'DESCRIPTION:' + icsText((e.time ? e.time + '. ' : '') + (e.description || '')),
            'END:VEVENT', 'END:VCALENDAR'
        ];
        var blob = new Blob([lines.join('\r\n')], { type: 'text/calendar;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = e.title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') + '.ics';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
        if (window.showToast) window.showToast('Added to your calendar download.');
    }

    /* ---------- Panel ---------- */

    function renderDetail() {
        detailList.textContent = '';
        var list, empty;

        if (selected) {
            var date = parse(selected);
            detailTitle.textContent = SHORT_MONTHS[date.getMonth()] + ' ' + date.getDate() + ', ' + date.getFullYear();
            list = eventsOn(selected);
            empty = 'Nothing scheduled this day.';
        } else {
            detailTitle.textContent = MONTHS[view.month] + ' events';
            list = inMonth(visibleEvents());
            empty = 'No events this month' + (category === 'all' ? '' : ' in this category') + '.';
        }

        list.sort(function (a, b) { return a.start < b.start ? -1 : a.start > b.start ? 1 : a.title.localeCompare(b.title); });
        detailSub.textContent = list.length + (list.length === 1 ? ' event' : ' events');

        if (!list.length) {
            detailList.appendChild(el('p', 'events-empty', empty));
            return;
        }
        list.forEach(function (e) { detailList.appendChild(card(e)); });
    }

    function render() { renderGrid(); renderChips(); renderDetail(); }

    /* ---------- Wiring ---------- */

    function shiftMonth(delta) {
        var d = new Date(view.year, view.month + delta, 1);
        view = { year: d.getFullYear(), month: d.getMonth() };
        selected = null;
        render();
    }

    grid.addEventListener('click', function (ev) {
        var cell = ev.target.closest('.cal-cell');
        if (!cell) return;
        selected = selected === cell.dataset.date ? null : cell.dataset.date;
        render();
    });

    document.getElementById('eventsPrev').addEventListener('click', function () { shiftMonth(-1); });
    document.getElementById('eventsNext').addEventListener('click', function () { shiftMonth(1); });
    document.getElementById('eventsToday').addEventListener('click', function () {
        view = { year: now.getFullYear(), month: now.getMonth() };
        selected = null;
        render();
    });

    chips.addEventListener('click', function (ev) {
        var chip = ev.target.closest('[data-category]');
        if (!chip) return;
        category = chip.dataset.category;
        chips.querySelectorAll('[data-category]').forEach(function (c) {
            var on = c === chip;
            c.classList.toggle('active', on);
            c.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        render();
    });

    // Both are hidden in the markup so a no-JS visitor sees only the list.
    calendar.hidden = false;
    chips.hidden = false;
    render();
})();
