/* admin/coupons-create.js — فرم ایجاد کوپن (نوع تخفیف + ارسال AJAX) */
(function () {
    'use strict';
    $(document).ready(function () {
        // تغییر نوع تخفیف
        $('#discountType').on('change', function () {
            if ($(this).val() === 'percent') {
                $('#valueHint').text('درصد تخفیف (0-100)');
                $('#maxDiscountField').show();
            } else {
                $('#valueHint').text('مبلغ تخفیف به تومان');
                $('#maxDiscountField').hide();
            }
        });

        var $form = $('#createCouponForm');
        if (!$form.length) return;
        var actionUrl = $form.data('action-url');

        $form.on('submit', function (e) {
            e.preventDefault();
            var formData = new FormData(this);
            var data = Object.fromEntries(formData);
            $.ajax({
                url: actionUrl,
                method: 'POST',
                data: JSON.stringify(data),
                contentType: 'application/json',
                headers: { 'X-CSRF-TOKEN': window.csrfToken },
                success: function (response) {
                    if (response.success) {
                        showAlert('success', response.message);
                        setTimeout(function () { window.location.href = response.redirect; }, 1500);
                    } else {
                        if (response.errors) {
                            var errorMsg = '';
                            for (var field in response.errors) {
                                errorMsg += response.errors[field].join('<br>') + '<br>';
                            }
                            showAlert('error', errorMsg);
                        } else {
                            showAlert('error', response.message);
                        }
                    }
                },
                error: function () { showAlert('error', 'خطا در ایجاد کوپن'); }
            });
        });
    });
})();
