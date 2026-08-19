/* admin/logs-dashboard.js — استخراج‌شده از views/admin/logs/dashboard.php (CSP-safe) */
(function(){
'use strict';
var __D=(function(){var el=document.getElementById('logs-dashboard-data');try{return JSON.parse(el.textContent);}catch(e){return [];}})();

// نمودار خطاها
fetch('/admin/logs/api-stats?type=errors&period=__D[0]')
    .then(r => r.json())
    .then(data => {
        const ctx = document.getElementById('errorsChart');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.map(d => d.date),
                datasets: [{
                    label: 'خطاها',
                    data: data.map(d => d.count),
                    borderColor: 'rgb(220, 53, 69)',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    });

// نمودار عملکرد
fetch('/admin/logs/api-stats?type=performance&period=__D[1]')
    .then(r => r.json())
    .then(data => {
        const ctx = document.getElementById('performanceChart');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.map(d => d.date),
                datasets: [{
                    label: 'زمان اجرا (ms)',
                    data: data.map(d => d.avg_time),
                    borderColor: 'rgb(13, 110, 253)',
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    });

// بروزرسانی خودکار هر 30 ثانیه
setInterval(() => {
    location.reload();
}, 30000);

})();
