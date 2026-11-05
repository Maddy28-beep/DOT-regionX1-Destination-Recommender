/* Admin Overview: the check-in line and the top places, switched by the 30 / 90 / All buttons. Reads the JSON the
   controller embedded; draws with the vendored Chart.js. Fewer than 3 visits in a period shows a plain message, not a flat line. */
(function () {
    'use strict';
    var holder = document.getElementById('overview-chart-data');
    var canvas = document.getElementById('checkin-line');
    if (!holder || !canvas) { return; }

    var data;
    try { data = JSON.parse(holder.textContent); } catch (e) { return; }

    var LABEL = { '30': 'Last 30 days', '90': 'Last 90 days', 'all': 'All time' };
    var MIN_VISITS = 3;
    var chart = null;
    var range = '90';

    function el(id) { return document.getElementById(id); }

    function plural(n, word) { return n + ' ' + word + (n === 1 ? '' : 's'); }

    function drawBars(top) {
        var box = el('top-places');
        box.textContent = '';
        if (!top.length) {
            var none = document.createElement('p');
            none.className = 'adm-bars__none';
            none.textContent = 'No check-ins in this period yet.';
            box.appendChild(none);
            return;
        }
        var max = top[0].count || 1;
        top.forEach(function (p) {
            var row = document.createElement('div');
            var head = document.createElement('div');
            head.className = 'adm-bar__row';
            var name = document.createElement('span');
            name.textContent = p.name;
            name.title = p.name;
            var count = document.createElement('span');
            count.textContent = p.count;
            head.appendChild(name);
            head.appendChild(count);
            var track = document.createElement('div');
            track.className = 'adm-bar__track';
            var fill = document.createElement('div');
            fill.className = 'adm-bar__fill';
            fill.style.width = Math.max(4, Math.round(p.count / max * 100)) + '%';
            track.appendChild(fill);
            row.appendChild(head);
            row.appendChild(track);
            box.appendChild(row);
        });
    }

    function render() {
        var d = data[range];
        var enough = d.total >= MIN_VISITS;

        el('checkin-summary').textContent = enough
            ? LABEL[range] + ': ' + plural(d.total, 'visit') + (d.peakDay ? ', busiest day ' + d.peakDay + ' (' + d.peak + ')' : '')
            : LABEL[range];
        el('checkin-empty').hidden = enough;
        canvas.style.visibility = enough ? 'visible' : 'hidden';
        canvas.setAttribute('aria-label', enough ? 'Daily QR check-ins, ' + LABEL[range].toLowerCase() + ': ' + plural(d.total, 'visit') : 'No chart: too few check-ins in this period');

        if (typeof Chart !== 'undefined' && enough) {
            if (chart) { chart.destroy(); }
            chart = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: d.labels,
                    datasets: [{
                        label: 'Check-ins',
                        data: d.values,
                        borderColor: '#0b6b4f',
                        backgroundColor: 'rgba(11,107,79,0.12)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.25,
                        pointRadius: d.labels.length > 45 ? 0 : 3,
                        pointHoverRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { maxTicksLimit: 8, maxRotation: 0 } },
                        y: { beginAtZero: true, ticks: { precision: 0, maxTicksLimit: 5 } }
                    }
                }
            });
        } else if (chart) {
            chart.destroy();
            chart = null;
        }

        drawBars(d.top);

        document.querySelectorAll('.adm-range button').forEach(function (b) {
            var on = b.dataset.range === range;
            b.classList.toggle('is-on', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    document.querySelectorAll('.adm-range button').forEach(function (b) {
        b.addEventListener('click', function () { range = b.dataset.range; render(); });
    });

    render();
})();
