/* admin/audit-trail.js — فعال‌سازی Popover های Bootstrap */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof bootstrap === 'undefined') return;
        document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
            new bootstrap.Popover(el, { placement: 'left' });
        });
    });
})();
