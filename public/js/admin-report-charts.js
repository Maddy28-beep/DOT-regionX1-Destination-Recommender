/* Reports page charts. Reads the JSON the controller embedded (same date range and filters as the table) and draws
   it with the vendored Chart.js. Charts are built when their tab is first shown, because a hidden canvas has no size. */
(function () {
    'use strict';
    var holder = document.getElementById('report-chart-data');
    if (!holder || typeof Chart === 'undefined') { return; }

    var data;
    try { data = JSON.parse(holder.textContent); } catch (e) { return; }

    var PRIMARY = '#0b6b4f';
    var PALETTE = ['#0b6b4f', '#ff6b35', '#f5a623', '#3a7ca5', '#8a5a99', '#6b7f78', '#c0362c', '#2f9e73'];
    var built = {};

    function build(key) {
        if (built[key] || !data[key]) { return; }
        var canvas = document.querySelector('canvas[data-chart="' + key + '"]');
        if (!canvas) { return; }
        var c = data[key];
        var horizontal = key === 'top';
        var isBreakdown = key === 'breakdown';
        var line = key === 'timeline' && c.labels.length > 1;
        var fill = line ? 'rgba(11,107,79,0.15)' : PRIMARY;

        built[key] = new Chart(canvas, {
            type: line ? 'line' : 'bar',
            data: {
                labels: c.labels,
                datasets: [{
                    label: c.title,
                    data: c.values,
                    backgroundColor: isBreakdown ? c.labels.map(function (_, i) { return PALETTE[i % PALETTE.length]; }) : fill,
                    borderColor: PRIMARY,
                    borderWidth: line ? 2 : 0,
                    fill: line,
                    tension: 0.2,
                    pointRadius: c.labels.length > 45 ? 0 : 3,
                    borderRadius: line ? 0 : 3
                }]
            },
            options: {
                indexAxis: horizontal ? 'y' : 'x',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { intersect: false, mode: 'index' } },
                scales: {
                    x: horizontal
                        ? { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#e1e8e4' } }
                        : { title: { display: !!c.axis, text: c.axis }, ticks: { autoSkip: true, maxRotation: 60 }, grid: { display: false } },
                    y: horizontal
                        ? { grid: { display: false } }
                        : { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#e1e8e4' } }
                }
            }
        });
    }

    var first = document.querySelector('canvas[data-chart]');
    if (first) { build(first.getAttribute('data-chart')); }

    document.querySelectorAll('#report-chart-tabs [data-bs-toggle="tab"]').forEach(function (btn) {
        btn.addEventListener('shown.bs.tab', function () {
            build(btn.id.replace('tab-', ''));
            Object.keys(built).forEach(function (k) { built[k].resize(); });
        });
    });
})();
