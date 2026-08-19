/* public/assets/js/admin/coupons-redemptions.js — استخراج‌شده از views/admin/coupons/redemptions.php برای سازگاری با CSP و کش‌پذیری */
(function () {
  'use strict';
$(document).ready(function() {
    $('#redemptionsTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/fa.json'
        },
        order: [[0, 'desc']]
    });
});
})();
