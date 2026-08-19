/* admin/task-disputes-show.js — استخراج‌شده از views/admin/task-disputes/show.php (CSP-safe) */
(function(){
'use strict';
var __D=(function(){var el=document.getElementById('task-disputes-show-data');try{return JSON.parse(el.textContent);}catch(e){return [];}})();

window.resolveDispute = function(endpoint) {
    const decision = document.getElementById('decisionText').value;
    const penalty = parseFloat(document.getElementById('penaltyAmount').value) || 0;

    if (!decision) { notyf.error('توضیح تصمیم الزامی است.'); return; }

    fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': __D[0] },
        body: JSON.stringify({ decision, penalty_amount: penalty, _csrf_token: __D[1] })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) { notyf.success(d.message); setTimeout(() => location.reload(), 1000); }
        else notyf.error(d.message);
    })
    .catch(() => notyf.error('خطا'));
}

var _bE=document.getElementById('btnForExecutor');
if(_bE) _bE.addEventListener('click', () => {
    Swal.fire({ title: 'تایید', text: 'به نفع انجام‌دهنده حل شود و پاداش پرداخت شود؟', icon: 'question', showCancelButton: true, confirmButtonText: 'بله', cancelButtonText: 'انصراف', confirmButtonColor: '#4caf50' })
    .then(r => { if (r.isConfirmed) resolveDispute(__D[2]); });
});

var _bA=document.getElementById('btnForAdvertiser');
if(_bA) _bA.addEventListener('click', () => {
    Swal.fire({ title: 'تایید', text: 'به نفع سفارش‌دهنده حل شود و تسک رد شود؟', icon: 'question', showCancelButton: true, confirmButtonText: 'بله', cancelButtonText: 'انصراف', confirmButtonColor: '#2196f3' })
    .then(r => { if (r.isConfirmed) resolveDispute(__D[3]); });
});

})();
