/* admin/api-tokens-index.js — استخراج‌شده از views/admin/api-tokens/index.php (CSP-safe) */
(function(){
'use strict';
var __D=(function(){var el=document.getElementById('api-tokens-index-data');try{return JSON.parse(el.textContent);}catch(e){return [];}})();

const csrfToken = __D[0];
window.revokeToken = function(id) {
    if (!confirm('این توکن را باطل کنید؟')) return;
    fetch(`${__D[1]}/${id}/revoke`, {
        method: 'POST', headers: {'X-CSRF-TOKEN': csrfToken}
    }).then(r => r.json()).then(d => {
        if (d.success) { notyf.success('توکن باطل شد'); setTimeout(() => location.reload(), 1000); }
        else notyf.error(d.message);
    });
}
window.revokeExpired = function() {
    if (!confirm('همه توکن‌های منقضی باطل شوند؟')) return;
    fetch(__D[2], {
        method: 'POST', headers: {'X-CSRF-TOKEN': csrfToken}
    }).then(r => r.json()).then(d => {
        notyf.success(`${d.count ?? 0} توکن باطل شد`);
        setTimeout(() => location.reload(), 1000);
    });
}

})();
