(function () {
  'use strict';

  const root = document.getElementById('twoFactorRoot');
  if (!root) return;
  const csrf = root.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const enableUrl = root.dataset.enableUrl || '/two-factor/enable';
  const disableUrl = root.dataset.disableUrl || '/two-factor/disable';
  let notifier = null;

  function notify(type, message) {
    if (!notifier && typeof window.Notyf !== 'undefined') notifier = new window.Notyf({ duration: 4500, position: { x: 'left', y: 'top' }, dismissible: true });
    if (notifier && typeof notifier[type] === 'function') return notifier[type](message);
    console[type === 'error' ? 'error' : 'log'](message);
  }

  const recoveryModalEl = document.getElementById('recoveryModal');
  if (recoveryModalEl && typeof bootstrap !== 'undefined') window.recoveryModal = new bootstrap.Modal(recoveryModalEl);

  document.addEventListener('click', function (event) {
    const actionEl = event.target.closest('[data-action]');
    const action = actionEl?.dataset.action;
    if (!action) return;

    if (action === 'download-recovery-codes') {
      const codes = window.recoveryCodes || [];
      const text = 'کدهای بازیابی چرتکه\n' + new Date().toLocaleDateString('fa-IR') + '\n\n' + codes.join('\n');
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
      a.download = 'chortke-recovery-codes.txt';
      a.click();
    }

    if (action === 'confirm-saved') {
      if (window.recoveryModal) window.recoveryModal.hide();
      setTimeout(() => location.reload(), 500);
    }
  });

  const enableForm = document.getElementById('enable-2fa-form');
  if (enableForm) {
    enableForm.addEventListener('submit', async function (event) {
      event.preventDefault();
      const btn = document.getElementById('enable-btn');
      const codeInput = document.getElementById('enable-code');
      const code = (codeInput?.value || '').trim();

      if (!/^\d{6}$/.test(code)) return notify('error', 'کد باید ۶ رقم باشد');

      if (btn) { btn.disabled = true; btn.dataset.originalText = btn.innerHTML; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> در حال فعال‌سازی...'; }
      try {
        const response = await fetch(enableUrl, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' }, body: new URLSearchParams(new FormData(enableForm)) });
        const data = await response.json().catch(() => ({}));
        if (response.ok && data.success) {
          window.recoveryCodes = data.recovery_codes || data.data?.recovery_codes || [];
          showRecoveryCodes(window.recoveryCodes);
          notify('success', data.message || '2FA فعال شد');
        } else {
          notify('error', data.message || 'خطا در فعال‌سازی');
          if (codeInput) { codeInput.value = ''; codeInput.focus(); }
        }
      } catch (_) { notify('error', 'خطای شبکه'); }
      finally { if (btn) { btn.disabled = false; btn.innerHTML = btn.dataset.originalText || '<i class="material-icons">check_circle</i> فعال‌سازی 2FA'; } }
    });
  }

  const disableForm = document.getElementById('disable-2fa-form');
  if (disableForm) {
    disableForm.addEventListener('submit', async function (event) {
      event.preventDefault();
      let confirmed = true;
      if (window.Swal) {
        const result = await window.Swal.fire({ title: 'غیرفعال کردن 2FA؟', text: 'با غیرفعال کردن، امنیت ورود حساب کاهش می‌یابد.', icon: 'warning', showCancelButton: true, confirmButtonText: 'غیرفعال کن', cancelButtonText: 'انصراف', confirmButtonColor: '#F6465D' });
        confirmed = result.isConfirmed;
      } else confirmed = confirm('آیا مطمئن هستید؟ 2FA غیرفعال خواهد شد.');
      if (!confirmed) return;

      const btn = document.getElementById('disable-btn');
      if (btn) { btn.disabled = true; btn.dataset.originalText = btn.innerHTML; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> در حال غیرفعال‌سازی...'; }
      try {
        const response = await fetch(disableUrl, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' }, body: new URLSearchParams(new FormData(disableForm)) });
        const data = await response.json().catch(() => ({}));
        if (response.ok && data.success) { notify('success', data.message || '2FA غیرفعال شد'); setTimeout(() => location.reload(), 1000); }
        else notify('error', data.message || 'خطا در غیرفعال‌سازی');
      } catch (_) { notify('error', 'خطای شبکه'); }
      finally { if (btn) { btn.disabled = false; btn.innerHTML = btn.dataset.originalText || '<i class="material-icons">lock_open</i> غیرفعال کردن 2FA'; } }
    });
  }

  function showRecoveryCodes(codes) {
    const list = document.getElementById('recovery-codes-list');
    if (!list) return;
    list.innerHTML = codes.map(c => '<div class="col-6"><div class="p-2 bg-light border rounded text-center"><code class="text-dark fw-bold">' + c + '</code></div></div>').join('');
    if (window.recoveryModal) window.recoveryModal.show();
  }
})();
