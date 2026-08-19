(function () {
  'use strict';

  const root = document.getElementById('customTaskShowRoot');
  const form = document.getElementById('startCustomTaskForm');
  if (!root || !form) return;

  let notifier = null;
  function notify(type, message) {
    if (!notifier && typeof window.Notyf !== 'undefined') {
      notifier = new window.Notyf({ duration: 4500, position: { x: 'left', y: 'top' }, dismissible: true });
    }
    if (notifier && typeof notifier[type] === 'function') return notifier[type](message);
    if (type === 'error') return alert(message);
    console.log(message);
  }

  function csrfToken() {
    return root.dataset.csrf || window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  }

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    const btn = document.getElementById('customStartBtn');
    const original = btn ? btn.innerHTML : '';
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<span class="tm-loading-dot"></span> در حال شروع...';
    }

    try {
      const response = await fetch(root.dataset.startUrl || form.getAttribute('action') || form.action, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        body: new FormData(form)
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.success) {
        notify('error', data.message || 'شروع تسک انجام نشد.');
        return;
      }
      notify('success', data.message || 'تسک شروع شد.');
      const redirectUrl = data.redirect_url || (data.submission_id ? '/custom-tasks/submissions/' + encodeURIComponent(String(data.submission_id)) + '/proof' : '/custom-tasks/my-submissions');
      setTimeout(() => { window.location.href = redirectUrl; }, 500);
    } catch (_) {
      notify('error', 'خطا در ارتباط با سرور.');
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = original;
      }
    }
  });
})();
