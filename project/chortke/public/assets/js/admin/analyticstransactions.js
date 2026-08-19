/* admin/analytics-transactions.js — نمودار حجم تراکنش‌ها */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var canvas = document.getElementById('transactionVolumeChart');
        if (!canvas || typeof Chart === 'undefined') return;
        var chartUrl = canvas.dataset.chartUrl;
        if (!chartUrl) return;

        fetch(chartUrl)
            .then(function (response) { return response.json(); })
            .then(function (data) {
                var ctx = canvas.getContext('2d');
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.data.map(function (item) { return item.date; }),
                        datasets: [{
                            label: 'واریز‌ها',
                            data: data.data.map(function (item) { return item.deposits; }),
                            borderColor: 'rgb(40, 167, 69)',
                            backgroundColor: 'rgba(40, 167, 69, 0.1)',
                            tension: 0.4
                        }, {
                            label: 'برداشت‌ها',
                            data: data.data.map(function (item) { return item.withdrawals; }),
                            borderColor: 'rgb(220, 53, 69)',
                            backgroundColor: 'rgba(220, 53, 69, 0.1)',
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: { y: { beginAtZero: true } }
                    }
                });
            })
            .catch(function (error) { console.error('Error loading chart data:', error); });
    });
})();
