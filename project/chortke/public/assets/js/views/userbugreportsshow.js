(function () {
  'use strict';
  const root = document.getElementById('supportBugShowRoot');
  if (!root) return;
  const csrf = root.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  let notifier = null;
  function notify(type, message) {
    if (!notifier && typeof window.Notyf !== 'undefined') notifier = new window.Notyf({ duration:4500, position:{x:'left',y:'top'}, dismissible:true });
    if (notifier && typeof notifier[type] === 'function') return notifier[type](message);
    console[type === 'error' ? 'error' : 'log'](message);
  }
  document.getElementById('sendComment')?.addEventListener('click', async function () {
    const input = document.getElementById('userComment');
    const comment = (input?.value || '').trim();
    if (!comment) return notify('error', 'متن پیام را وارد کنید.');
    this.disabled = true;
    try {
      const response = await fetch(root.dataset.commentUrl, { method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':csrf }, body:JSON.stringify({ comment }) });
      const data = await response.json().catch(() => ({}));
      if (response.ok && data.success) { notify('success', 'پیام ارسال شد'); setTimeout(() => location.reload(), 700); }
      else notify('error', data.message || 'ارسال پیام انجام نشد');
    } catch (_) { notify('error', 'خطا در ارتباط با سرور'); }
    finally { this.disabled = false; }
  });
})();
