/* admin/users-index.js — استخراج‌شده از views/admin/users/index.php (CSP-safe) */
(function(){
'use strict';
var __D=(function(){var el=document.getElementById('users-index-data');try{return JSON.parse(el.textContent);}catch(e){return [];}})();

async function postJson(url, payload={}) {
  const res = await fetch(url, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':__D[0]}, body:JSON.stringify({...payload,_token:__D[1]}) });
  try { return await res.json(); } catch(e) { throw new Error('پاسخ سرور نامعتبر'); }
}
document.addEventListener('click', async function(e) {
  const banBtn = e.target.closest('.js-user-ban');
  if (banBtn) {
    e.preventDefault();
    const willBan = banBtn.dataset.status !== 'banned';
    const c = await Swal.fire({ title: willBan?'بن کردن کاربر':'رفع بن کاربر', text: willBan?'کاربر مسدود و دسترسی او قطع می‌شود.':'کاربر دوباره فعال خواهد شد.', icon:'warning', showCancelButton:true, confirmButtonText: willBan?'⛔ بن شود':'✅ آزاد شود', cancelButtonText:'انصراف', confirmButtonColor: willBan?'#ef4444':'#10b981' });
    if (!c.isConfirmed) return;
    try { const data = await postJson(banBtn.dataset.url); if(data.success){notyf.success(data.message||'انجام شد');setTimeout(()=>location.reload(),900);}else notyf.error(data.message||'خطا'); } catch(err) { notyf.error(err.message); }
    return;
  }
  const susBtn = e.target.closest('.js-user-suspend');
  if (susBtn) {
    e.preventDefault();
    const willSuspend = susBtn.dataset.status !== 'suspended';
    const c = await Swal.fire({ title: willSuspend?'تعلیق کاربر':'برداشتن تعلیق', text: willSuspend?'کاربر موقتاً محدود می‌شود.':'محدودیت برداشته می‌شود.', icon:'warning', showCancelButton:true, confirmButtonText: willSuspend?'⏸ تعلیق':'▶️ فعال', cancelButtonText:'انصراف', confirmButtonColor:'#f59e0b' });
    if (!c.isConfirmed) return;
    try { const data = await postJson(susBtn.dataset.url); if(data.success){notyf.success(data.message||'انجام شد');setTimeout(()=>location.reload(),900);}else notyf.error(data.message||'خطا'); } catch(err) { notyf.error(err.message); }
    return;
  }
  const delBtn = e.target.closest('.js-user-delete');
  if (delBtn) {
    e.preventDefault();
    const c = await Swal.fire({ title:'حذف کاربر', text:'کاربر به صورت نرم حذف می‌شود.', icon:'warning', showCancelButton:true, confirmButtonText:'🗑 حذف شود', cancelButtonText:'انصراف', confirmButtonColor:'#ef4444' });
    if (!c.isConfirmed) return;
    try { const data = await postJson(delBtn.dataset.url); if(data.success){notyf.success(data.message||'حذف شد');setTimeout(()=>location.reload(),900);}else notyf.error(data.message||'خطا'); } catch(err) { notyf.error(err.message); }
    return;
  }
});

})();
