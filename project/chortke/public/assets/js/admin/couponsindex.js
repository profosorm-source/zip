/* admin/coupons-index.js — استخراج‌شده از views/admin/coupons/index.php (CSP-safe) */
(function(){
'use strict';
var __D=(function(){var el=document.getElementById('coupons-index-data');try{return JSON.parse(el.textContent);}catch(e){return [];}})();

$(document).ready(function() {
    // DataTable
    $('#couponsTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/fa.json'
        }
    });

    // تغییر وضعیت فعال/غیرفعال
    $('.toggle-active').on('change', function() {
        const couponId = $(this).data('id');
        const checkbox = $(this);

        $.ajax({
            url: __D[0],
            method: 'POST',
            data: JSON.stringify({ id: couponId }),
            contentType: 'application/json',
            success: function(response) {
                if (response.success) {
                    showAlert('success', response.message);
                } else {
                    checkbox.prop('checked', !checkbox.is(':checked'));
                    showAlert('error', response.message);
                }
            },
            error: function() {
                checkbox.prop('checked', !checkbox.is(':checked'));
                showAlert('error', 'خطا در تغییر وضعیت');
            }
        });
    });

    // حذف کوپن
    $('.delete-coupon').on('click', function() {
        const couponId = $(this).data('id');
        const row = $(this).closest('tr');

        if (confirm('آیا از حذف این کوپن اطمینان دارید؟')) {
            $.ajax({
                url: __D[1],
                method: 'POST',
                data: JSON.stringify({ id: couponId }),
                contentType: 'application/json',
                success: function(response) {
                    if (response.success) {
                        row.fadeOut(300, function() { $(this).remove(); });
                        showAlert('success', response.message);
                    } else {
                        showAlert('error', response.message);
                    }
                },
                error: function() {
                    showAlert('error', 'خطا در حذف کوپن');
                }
            });
        }
    });
});

})();
