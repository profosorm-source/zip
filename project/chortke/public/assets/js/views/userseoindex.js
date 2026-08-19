(function () {
  'use strict';

  const root = document.getElementById('seoTaskListRoot');
  if (!root) return;

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

  function executeUrl(data) {
    if (data && typeof data.redirect_url === 'string' && data.redirect_url !== '') return data.redirect_url;
    const executionId = data?.execution_id || data?.execution?.id || data?.id;
    if (!executionId) return '';
    return (root.dataset.executeTemplate || '/seo/__EXECUTION_ID__/execute').replace('__EXECUTION_ID__', encodeURIComponent(String(executionId)));
  }

  async function startSeoTask(button) {
    const adId = button.dataset.id;
    const title = button.dataset.title || 'تسک SEO';

    const confirmed = typeof Swal !== 'undefined'
      ? await Swal.fire({
          title: 'شروع تسک',
          html: `<p>تسک: <strong>${title}</strong></p><p style="font-size:12px;color:#666;line-height:1.8">پس از شروع، صفحه اجرای تسک باز می‌شود. سایت هدف را باز کنید، زمان لازم را سپری کنید و سپس تکمیل را بزنید.</p>`,
          icon: 'question',
          showCancelButton: true,
          confirmButtonText: 'شروع',
          cancelButtonText: 'انصراف',
          confirmButtonColor: '#f0b90b'
        }).then(r => r.isConfirmed)
      : window.confirm('شروع تسک «' + title + '»؟');

    if (!confirmed) return;

    const originalHtml = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> شروع...';

    try {
      const response = await fetch(root.dataset.startUrl || '/seo/start', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken()
        },
        body: JSON.stringify({ ad_id: adId, _csrf_token: csrfToken() })
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.success) {
        notify('error', data.message || 'شروع تسک انجام نشد.');
        return;
      }
      notify('success', data.message || 'تسک SEO شروع شد.');
      const url = executeUrl(data);
      if (url) window.location.href = url;
      else notify('error', 'شناسه اجرای تسک از سرور دریافت نشد.');
    } catch (_) {
      notify('error', 'خطا در ارتباط با سرور.');
    } finally {
      button.disabled = false;
      button.innerHTML = originalHtml;
    }
  }

  document.querySelectorAll('.btn-start-task').forEach(btn => {
    btn.addEventListener('click', () => startSeoTask(btn));
  });
})();
