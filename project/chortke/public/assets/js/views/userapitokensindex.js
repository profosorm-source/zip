(function () {
  'use strict';
  const root = document.getElementById('accountApiTokensRoot');
  if (!root) return;
  const revokeBase = root.dataset.revokeBase || '/api-tokens';
  const csrf = root.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  let notifier = null;
  function notify(type, message) {
    if (!notifier && typeof window.Notyf !== 'undefined') notifier = new window.Notyf({ duration: 4500, position:{x:'left', y:'top'}, dismissible:true });
    if (notifier && typeof notifier[type] === 'function') return notifier[type](message);
    console[type === 'error' ? 'error' : 'log'](message);
  }
  async function copyToken() {
    const input = document.getElementById('newTokenInput');
    if (!input) return;
    try { await navigator.clipboard.writeText(input.value); notify('success', 'توکن کپی شد'); }
    catch (_) { input.select(); document.execCommand('copy'); notify('success', 'توکن کپی شد'); }
  }
  async function revokeToken(id, name) {
    let confirmed = true;
    if (window.Swal) {
      const result = await window.Swal.fire({ title:'ابطال توکن؟', text:'توکن "' + name + '" باطل شود؟ اتصال‌هایی که از آن استفاده می‌کنند قطع می‌شوند.', icon:'warning', showCancelButton:true, confirmButtonText:'بله، باطل شود', cancelButtonText:'انصراف', confirmButtonColor:'#F6465D' });
      confirmed = result.isConfirmed;
    } else {
      confirmed = window.confirm('آیا مطمئنید که توکن "' + name + '" را باطل کنید؟');
    }
    if (!confirmed) return;
    try {
      const response = await fetch(revokeBase + '/' + encodeURIComponent(id) + '/revoke', { method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':csrf } });
      const data = await response.json().catch(() => ({}));
      if (response.ok && data.success) { notify('success', 'توکن باطل شد'); setTimeout(() => location.reload(), 900); }
      else notify('error', data.message || 'ابطال توکن انجام نشد');
    } catch (_) { notify('error', 'خطا در ارتباط با سرور'); }
  }
  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-action="copy-token"]')) copyToken();
    const revoke = event.target.closest('[data-action="revoke-token"]');
    if (revoke) revokeToken(revoke.dataset.tokenId, revoke.dataset.tokenName || '');
  });
})();
