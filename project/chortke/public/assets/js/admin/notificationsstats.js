/* admin/notifications-stats.js — نمودار روند روزانهٔ اعلان‌ها */
(function () {
    'use strict';
    function readJSON(id) {
        var el = document.getElementById(id);
        if (!el) return null;
        try { return JSON.parse(el.textContent || 'null'); } catch (e) { return null; }
    }
    document.addEventListener('DOMContentLoaded', function () {
        var daily = readJSON('notifications-stats-data') || [];
        if (!daily.length || typeof Chart === 'undefined') return;

        var labels  = daily.map(function (d) { return d.date; });
        var sent    = daily.map(function (d) { return parseInt(d.sent); });
        var read    = daily.map(function (d) { return parseInt(d.read_count); });
        var clicked = daily.map(function (d) { return parseInt(d.click_count); });

        var canvas = document.getElementById('dailyChart');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    { label: 'ارسال', data: sent,    borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,.1)', tension: .4, fill: true },
                    { label: 'خوانده', data: read,   borderColor: '#198754', backgroundColor: 'rgba(25,135,84,.1)',  tension: .4, fill: false },
                    { label: 'کلیک',  data: clicked, borderColor: '#ffc107', backgroundColor: 'rgba(255,193,7,.1)',  tension: .4, fill: false }
                ]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'top' } },
                scales: { x: { ticks: { maxTicksLimit: 10 } }, y: { beginAtZero: true } }
            }
        });
    });
})();
