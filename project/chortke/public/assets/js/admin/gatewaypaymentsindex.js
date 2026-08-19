/* admin/gateway-payments-index.js — استخراج‌شده از views/admin/gateway-payments/index.php (CSP-safe) */
(function(){
'use strict';
var __D=(function(){var el=document.getElementById('gateway-payments-index-data');try{return JSON.parse(el.textContent);}catch(e){return [];}})();
window.verifyPayment = function(paymentId, authority) {
    Swal.fire({
        title: 'استعلام مجدد و تایید دستی پرداخت',
        html: `
            <p>سیستم به صورت مستقیم با درگاه بانکی ارتباط برقرار کرده و صحت تراکنش <strong>#${paymentId}</strong> را بررسی می‌کند.</p>
            <p style="font-size: 13px; color: #666;">کد مرجع درگاه: <code>${authority}</code></p>
            <div style="margin-top: 15px; padding: 15px; background: #e8f5e9; border-radius: 8px; text-align: right;">
                <strong style="color: #2e7d32;">راهنما:</strong>
                <p style="margin: 5px 0 0 0; font-size: 12px; color: #555;">
                    اگر تراکنش در درگاه موفقیت‌آمیز بوده باشد، کیف پول کاربر شارژ شده و وضعیت تراکنش به <strong>تکمیل شده (Completed)</strong> تغییر خواهد یافت.
                </p>
            </div>
        `,
        icon: 'info',
        showCancelButton: true,
        confirmButtonColor: '#2e7d32',
        cancelButtonColor: '#757575',
        confirmButtonText: 'بله، استعلام و تأیید شود',
        cancelButtonText: 'انصراف',
        showLoaderOnConfirm: true,
        preConfirm: () => {
            const formData = new FormData();
            formData.append('payment_id', paymentId);
            formData.append(__D[0], __D[1]);

            return fetch(__D[2], {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(err => { throw new Error(err.message || 'خطا در انجام عملیات') });
                }
                return response.json();
            })
            .catch(error => {
                Swal.showValidationMessage(`خطا: ${error.message}`);
            });
        },
        allowOutsideClick: () => !Swal.isLoading()
    }).then((result) => {
        if (result.isConfirmed && result.value) {
            if (result.value.success) {
                Swal.fire({
                    title: 'تأیید موفقیت‌آمیز',
                    text: result.value.message || 'تراکنش با موفقیت تأیید و کیف پول کاربر شارژ شد.',
                    icon: 'success',
                    confirmButtonText: 'باشه'
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire({
                    title: 'خطا در تایید',
                    text: result.value.message || 'تایید تراکنش ناموفق بود.',
                    icon: 'error',
                    confirmButtonText: 'باشه'
                });
            }
        }
    });
}

})();
