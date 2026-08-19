/* admin/email-queue-index.js — استخراج‌شده از views/admin/email-queue/index.php (CSP-safe) */
(function(){
'use strict';
var __D=(function(){var el=document.getElementById('email-queue-index-data');try{return JSON.parse(el.textContent);}catch(e){return [];}})();

const csrfToken = __D[0];
window.processQueue = function() {
    fetch(__D[1], {
        method: 'POST', headers: {'X-CSRF-TOKEN': csrfToken}
    }).then(r => r.json()).then(d => {
        notyf.success(`ارسال شد: ${d.sent ?? 0} | ناموفق: ${d.failed ?? 0}`);
        setTimeout(() => location.reload(), 1500);
    });
}
window.retryFailed = function() {
    fetch(__D[2], {
        method: 'POST', headers: {'X-CSRF-TOKEN': csrfToken}
    }).then(r => r.json()).then(d => {
        notyf.success(`${d.count ?? 0} ایمیل برای تلاش مجدد آماده شد`);
        setTimeout(() => location.reload(), 1500);
    });
}
window.retrySingle = function(id) {
    fetch(`${__D[3]}/${id}/retry`, {
        method: 'POST', headers: {'X-CSRF-TOKEN': csrfToken}
    }).then(r => r.json()).then(d => {
        if (d.success) notyf.success('آماده تلاش مجدد');
        else notyf.error(d.message);
        setTimeout(() => location.reload(), 1000);
    });
}

})();
