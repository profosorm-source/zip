/* admin/messages-reports.js — فیلتر وضعیت گزارش‌های پیام */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var sel = document.getElementById('status-filter');
        if (sel) {
            sel.addEventListener('change', function (e) {
                window.location.href = '?status=' + encodeURIComponent(e.target.value);
            });
        }
    });
})();
