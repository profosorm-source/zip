/* admin/referral-index.js — استخراج‌شده از views/admin/referral/index.php (CSP-safe) */
(function(){
'use strict';
var __D=(function(){var el=document.getElementById('referral-index-data');try{return JSON.parse(el.textContent);}catch(e){return [];}})();
window.cancelCommission = function(id) {
    Swal.fire({
        title: 'لغو کمیسیون',
        input: 'text',
        inputLabel: 'دلیل لغو:',
        inputPlaceholder: 'مثال: تقلب زیرمجموعه',
        showCancelButton: true,
        confirmButtonColor: '#f44336',
        confirmButtonText: 'لغو کمیسیون',
        cancelButtonText: 'انصراف',
        inputValidator: function(value) {
            if (!value) return 'لطفاً دلیل لغو را وارد کنید';
        }
    }).then(function(result) {
        if (result.isConfirmed) {
            fetch(__D[0] + id + '/cancel', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': __D[1]
                },
                body: JSON.stringify({csrf_token: __D[2], reason: result.value})
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var notyf = new Notyf({duration: 3000, position: {x:'left',y:'top'}});
                if (data.success) {
                    notyf.success(data.message);
                    location.reload();
                } else {
                    notyf.error(data.message);
                }
            });
        }
    });
}
window.batchPay = function(currency) {
    Swal.fire({
        title: 'پرداخت دسته‌ای',
        text: 'تمام کمیسیون‌های در انتظار پرداخت خواهند شد. آیا مطمئن هستید؟',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#4caf50',
        confirmButtonText: 'بله، پرداخت کن',
        cancelButtonText: 'انصراف'
    }).then(function(result) {
        if (result.isConfirmed) {
            fetch(__D[3], {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': __D[4]
                },
                body: JSON.stringify({csrf_token: __D[5], currency: currency})
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var notyf = new Notyf({duration: 5000, position: {x:'left',y:'top'}});
                if (data.success) {
                    notyf.success(data.message);
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    notyf.error(data.message);
                }
            });
        }
    });
}

})();
