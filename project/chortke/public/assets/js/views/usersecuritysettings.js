(function () {
  'use strict';

  const root = document.getElementById('accountSecurityRoot');
  const form = document.getElementById('securitySettingsForm');
  if (!root || !form) return;

  const updateUrl = root.dataset.updateUrl || form.action;
  const csrf = root.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  let notifier = null;

  function notify(type, message) {
    if (!notifier && typeof window.Notyf !== 'undefined') {
      notifier = new window.Notyf({ duration: 4500, position: { x: 'left', y: 'top' }, dismissible: true });
    }
    if (notifier && typeof notifier[type] === 'function') return notifier[type](message);
    const toast = document.createElement('div');
    toast.textContent = message;
    Object.assign(toast.style, {
      position: 'fixed', left: '24px', top: '78px', zIndex: '10050', maxWidth: '380px',
      padding: '12px 16px', borderRadius: '12px', background: type === 'error' ? 'rgba(246,70,93,.96)' : 'rgba(30,217,168,.96)',
      color: '#fff', fontFamily: 'Vazirmatn,Tahoma,sans-serif', fontSize: '13px', fontWeight: '800', lineHeight: '1.8', boxShadow: '0 14px 34px rgba(0,0,0,.28)'
    });
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4500);
  }

  form.addEventListener('submit', async function (event) {
    event.preventDefault();

    const sessionTimeout = parseInt(document.getElementById('session_timeout')?.value || '0', 10);
    if (!Number.isFinite(sessionTimeout) || sessionTimeout < 5 || sessionTimeout > 480) {
      notify('error', 'زمان خروج خودکار باید بین ۵ تا ۴۸۰ دقیقه باشد.');
      return;
    }

    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.dataset.originalText = submitBtn.innerHTML;
      submitBtn.innerHTML = '<i class="material-icons" style="animation:spin .8s linear infinite">refresh</i> در حال ذخیره...';
    }

    try {
      const formData = new FormData(form);
      const response = await fetch(updateUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: formData
      });
      const data = await response.json().catch(() => ({}));
      if (response.ok && data.success) {
        notify('success', data.message || 'تنظیمات امنیتی با موفقیت ذخیره شد.');
      } else {
        notify('error', data.message || 'تنظیمات امنیتی ذخیره نشد. لطفاً دوباره تلاش کنید.');
      }
    } catch (_) {
      notify('error', 'خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.');
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = submitBtn.dataset.originalText || '<i class="material-icons">save</i> ذخیره تنظیمات امنیتی';
      }
    }
  });

  const style = document.createElement('style');
  style.textContent = '@keyframes spin{to{transform:rotate(360deg)}}';
  document.head.appendChild(style);
})();
