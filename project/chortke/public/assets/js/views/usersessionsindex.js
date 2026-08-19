(function () {
  'use strict';
  const root = document.getElementById('accountSessionsRoot');
  if (!root) return;
  const base = root.dataset.terminateBase || '/sessions/terminate';
  const csrf = root.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  let notifier = null;
  function notify(type, message) {
    if (!notifier && typeof window.Notyf !== 'undefined') notifier = new window.Notyf({ duration: 4500, position: { x:'left', y:'top' }, dismissible:true });
    if (notifier && typeof notifier[type] === 'function') return notifier[type](message);
    console[type === 'error' ? 'error' : 'log'](message);
  }
  document.addEventListener('click', async function (event) {
    const btn = event.target.closest('.btn-terminate');
    if (!btn) return;
    const id = btn.dataset.id;
    let confirmed = true;
    if (window.Swal) {
      const result = await window.Swal.fire({ title:'خروج از دستگاه', text:'آیا مطمئنید که می‌خواهید از این دستگاه خارج شوید؟', icon:'warning', showCancelButton:true, confirmButtonText:'بله، خارج شود', cancelButtonText:'انصراف', confirmButtonColor:'#F6465D' });
      confirmed = result.isConfirmed;
    }
    if (!confirmed) return;
    btn.disabled = true;
    try {
      const response = await fetch(base + '/' + encodeURIComponent(id), { method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':csrf } });
      const data = await response.json().catch(() => ({}));
      if (response.ok && data.success) { notify('success', data.message || 'نشست خارج شد'); setTimeout(() => location.reload(), 900); }
      else notify('error', data.message || 'خروج از نشست انجام نشد');
    } catch (_) { notify('error', 'خطا در ارتباط با سرور'); }
    finally { btn.disabled = false; }
  });
})();
