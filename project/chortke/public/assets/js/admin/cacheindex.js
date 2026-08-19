/* admin/cache-index.js — استخراج‌شده از views/admin/cache/index.php (CSP-safe) */
(function(){
'use strict';
var __D=(function(){var el=document.getElementById('cache-index-data');try{return JSON.parse(el.textContent);}catch(e){return [];}})();

const csrfToken = __D[0];
window.clearCache = function(type) {
    const labels = {all: 'کل cache سایت', settings: 'cache تنظیمات', kpi: 'cache KPI'};
    Swal.fire({
        title: 'پاک‌سازی ' + labels[type],
        text: 'آیا مطمئن هستید؟',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'بله، پاک شود',
        cancelButtonText: 'انصراف',
    }).then(result => {
        if (!result.isConfirmed) return;
        fetch(__D[1], {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
            body: JSON.stringify({type})
        })
        .then(r => r.json())
        .then(d => {
            notyf.success(d.message || 'Cache پاک شد');
            setTimeout(() => location.reload(), 1000);
        })
        .catch(() => notyf.error('خطا در پاک‌سازی'));
    });
}
window.clearCacheKey = function(key) {
    fetch(__D[2], {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
        body: JSON.stringify({key})
    })
    .then(r => r.json())
    .then(d => {
        notyf.success(`کلید "${key}" حذف شد`);
        setTimeout(() => location.reload(), 500);
    })
    .catch(() => notyf.error('خطا'));
}

})();
