/* admin/kpi.js — نمودارهای ماژول KPI (index داشبورد + financial + users)
 * هر صفحه با وجود canvas مربوطه تشخیص داده می‌شود.
 * data-* روی #kpiRoot:
 *   data-chart-url => /admin/kpi/chart-data  (صفحهٔ index)
 * داده‌های financial/users از تگ‌های JSON خوانده می‌شوند:
 *   #kpi-dw-data ، #kpi-revenue-data ، #kpi-users-data
 */
(function () {
    'use strict';
    function root() { return document.getElementById('kpiRoot') || document.body; }
    function d(k, fb) { var v = root().dataset[k]; return v !== undefined ? v : (fb || ''); }
    function readJSON(id) { var el = document.getElementById(id); if (!el) return null; try { return JSON.parse(el.textContent || 'null'); } catch (e) { return null; } }

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof Chart === 'undefined') return;

        /* ════ financial: dwChart + dailyRevenueChart ════ */
        var dwData = readJSON('kpi-dw-data');
        if (dwData && document.getElementById('dwChart')) {
            var depDates = (dwData.deposits || []).map(function (x) { return x.date; });
            var depValues = (dwData.deposits || []).map(function (x) { return parseFloat(x.total); });
            var wdDates = (dwData.withdrawals || []).map(function (x) { return x.date; });
            var wdValues = (dwData.withdrawals || []).map(function (x) { return parseFloat(x.total); });
            var allDates = Array.from(new Set(depDates.concat(wdDates))).sort();
            var depMap = {}; depDates.forEach(function (x, i) { depMap[x] = depValues[i]; });
            var wdMap = {}; wdDates.forEach(function (x, i) { wdMap[x] = wdValues[i]; });
            new Chart(document.getElementById('dwChart'), {
                type: 'line',
                data: { labels: allDates, datasets: [
                    { label: 'واریز', data: allDates.map(function (x) { return depMap[x] || 0; }), borderColor: '#4caf50', backgroundColor: 'rgba(76,175,80,0.1)', tension: 0.4, fill: true },
                    { label: 'برداشت', data: allDates.map(function (x) { return wdMap[x] || 0; }), borderColor: '#f44336', backgroundColor: 'rgba(244,67,54,0.1)', tension: 0.4, fill: true }
                ] },
                options: { responsive: true, scales: { y: { beginAtZero: true } } }
            });
        }
        var revData = readJSON('kpi-revenue-data');
        if (revData && document.getElementById('dailyRevenueChart')) {
            new Chart(document.getElementById('dailyRevenueChart'), {
                type: 'bar',
                data: { labels: revData.map(function (x) { return x.date; }), datasets: [{ label: 'درآمد', data: revData.map(function (x) { return parseFloat(x.total); }), backgroundColor: 'rgba(156,39,176,0.6)', borderColor: '#9c27b0', borderWidth: 1, borderRadius: 4 }] },
                options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
            });
        }

        /* ════ users: regChart ════ */
        var usersData = readJSON('kpi-users-data');
        if (usersData && document.getElementById('regChart')) {
            new Chart(document.getElementById('regChart'), {
                type: 'line',
                data: { labels: usersData.map(function (x) { return x.date; }), datasets: [{ label: 'ثبت‌نام', data: usersData.map(function (x) { return parseInt(x.count); }), borderColor: '#4fc3f7', backgroundColor: 'rgba(79,195,247,0.15)', tension: 0.4, fill: true, pointRadius: 3, pointBackgroundColor: '#4fc3f7' }] },
                options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
            });
        }

        /* ════ index: نمودارهای AJAX (chart-data) ════ */
        var chartBaseUrl = d('chartUrl');
        if (chartBaseUrl && document.getElementById('revenueChart')) {
            var revenueChartInstance = null;
            function loadChart(type, days, cb) {
                fetch(chartBaseUrl + '?type=' + type + '&days=' + days).then(function (r) { return r.json(); }).then(function (res) { if (res.success) cb(res.data); });
            }
            function renderRevenueChart(days) {
                loadChart('revenue', days, function (data) {
                    var labels = data.map(function (x) { return x.date; });
                    var values = data.map(function (x) { return parseFloat(x.total); });
                    if (revenueChartInstance) revenueChartInstance.destroy();
                    revenueChartInstance = new Chart(document.getElementById('revenueChart'), {
                        type: 'line',
                        data: { labels: labels, datasets: [{ label: 'درآمد', data: values, borderColor: '#4fc3f7', backgroundColor: 'rgba(79,195,247,0.1)', tension: 0.4, fill: true, pointRadius: 3, pointBackgroundColor: '#4fc3f7' }] },
                        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return v.toLocaleString(); } } } } }
                    });
                });
            }
            loadChart('registrations', 30, function (data) {
                new Chart(document.getElementById('registrationChart'), {
                    type: 'bar',
                    data: { labels: data.map(function (x) { return x.date; }), datasets: [{ label: 'ثبت‌نام', data: data.map(function (x) { return x.count; }), backgroundColor: 'rgba(76,175,80,0.6)', borderColor: '#4caf50', borderWidth: 1, borderRadius: 4 }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
                });
            });
            loadChart('tasks', 30, function (data) {
                new Chart(document.getElementById('taskChart'), {
                    type: 'bar',
                    data: { labels: data.map(function (x) { return x.date; }), datasets: [{ label: 'تسک تکمیل‌شده', data: data.map(function (x) { return x.count; }), backgroundColor: 'rgba(255,167,38,0.6)', borderColor: '#ffa726', borderWidth: 1, borderRadius: 4 }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
                });
            });
            loadChart('platforms', 30, function (data) {
                var colors = { instagram: '#e91e63', youtube: '#f44336', telegram: '#2196f3', tiktok: '#000', twitter: '#1da1f2', google: '#4caf50' };
                var bgColors = data.map(function (x) { var p = (typeof x === 'object' && x !== null) ? (x.platform || 'other') : 'other'; return colors[p] || '#9e9e9e'; });
                new Chart(document.getElementById('platformChart'), {
                    type: 'doughnut',
                    data: { labels: data.map(function (x) { return x.platform || x[0] || '?'; }), datasets: [{ data: data.map(function (x) { return parseInt(x.count || x[1] || 0); }), backgroundColor: bgColors, borderWidth: 2, borderColor: '#fff' }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 10 } } } }
                });
            });
            document.querySelectorAll('.btn-chart-period').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    document.querySelectorAll('.btn-chart-period').forEach(function (b) { b.classList.remove('active'); });
                    this.classList.add('active');
                    renderRevenueChart(parseInt(this.dataset.days));
                });
            });
            renderRevenueChart(30);
        }
    });
})();
