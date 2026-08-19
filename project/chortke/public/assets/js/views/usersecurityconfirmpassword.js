(function () {
  'use strict';
  const root = document.getElementById('twoFactorConfirmRoot');
  const form = document.getElementById('confirmForm');
  if (!root || !form) return;
  const action = root.dataset.action || '/two-factor/authorize';
  const csrf = root.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  let notifier = null;
  function notify(type, message) {
    if (!notifier && typeof window.Notyf !== 'undefined') notifier = new window.Notyf({ duration:4500, position:{x:'left',y:'top'}, dismissible:true });
    if (notifier && typeof notifier[type] === 'function') return notifier[type](message);
    const msg = document.getElementById('message');
    if (msg) { msg.style.display = 'flex'; msg.textContent = message; }
  }
  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    const btn = document.getElementById('confirmBtn');
    if (btn) { btn.disabled = true; btn.dataset.originalText = btn.innerHTML; btn.innerHTML = '<i class="material-icons" style="animation:spin .8s linear infinite">refresh</i> در حال بررسی...'; }
    try {
      const response = await fetch(action, { method:'POST', credentials:'same-origin', headers:{ 'X-CSRF-TOKEN':csrf, Accept:'application/json', 'X-Requested-With':'XMLHttpRequest' }, body:new FormData(form) });
      const data = await response.json().catch(() => ({}));
      if (response.ok && data.success) {
        notify('success', data.message || 'تأیید شد');
        window.location.href = data.data?.redirect || data.redirect || '/two-factor';
      } else {
        notify('error', data.message || 'رمز عبور اشتباه است.');
      }
    } catch (_) {
      notify('error', 'خطا در ارتباط با سرور.');
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = btn.dataset.originalText || 'تأیید و ادامه'; }
    }
  });
  const style = document.createElement('style'); style.textContent='@keyframes spin{to{transform:rotate(360deg)}}'; document.head.appendChild(style);
})();
