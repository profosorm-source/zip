/* admin/levels-index.js — استخراج‌شده از views/admin/levels/index.php (CSP-safe) */
(function(){
'use strict';
var __D=(function(){var el=document.getElementById('levels-index-data');try{return JSON.parse(el.textContent);}catch(e){return [];}})();

document.querySelectorAll('.btn-delete-level').forEach(btn => {
    btn.addEventListener('click', function() {
        const id   = this.dataset.id;
        const name = this.dataset.name;
        if (!confirm('آیا مطمئن هستید که سطح «' + name + '» را حذف کنید؟\nاین عمل قابل بازگشت نیست.')) return;

        fetch(`${__D[0]}${id}/delete`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': __D[1] },
            body: JSON.stringify({ csrf_token: __D[2] }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                this.closest('tr').remove();
            } else {
                alert(data.message || 'خطا در حذف سطح');
            }
        })
        .catch(() => alert('خطا در ارتباط با سرور'));
    });
});

})();
