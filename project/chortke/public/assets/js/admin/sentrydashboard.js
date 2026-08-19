/* admin/sentry-dashboard.js — استخراج‌شده از views/admin/sentry/dashboard.php (CSP-safe) */
(function(){
'use strict';
var __D=(function(){var el=document.getElementById('sentry-dashboard-data');try{return JSON.parse(el.textContent);}catch(e){return [];}})();

        // Errors Chart
        new Chart(document.getElementById('errorsChart'), {
            type: 'line',
            data: {
                labels: __D[0],
                datasets: [{
                    label: 'خطاها',
                    data: __D[1],
                    borderColor: '#f56565',
                    backgroundColor: 'rgba(245, 101, 101, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                }
            }
        });

        // Performance Chart
        new Chart(document.getElementById('performanceChart'), {
            type: 'line',
            data: {
                labels: __D[2],
                datasets: [{
                    label: 'زمان پاسخ (ms)',
                    data: __D[3],
                    borderColor: '#48bb78',
                    backgroundColor: 'rgba(72, 187, 120, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                }
            }
        });
    
})();
