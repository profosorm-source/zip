/* Prediction admin index actions — CSP-safe, base-path-safe */
(function () {
  'use strict';
  let settleUrl = '';

  function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content || window.csrfToken || '';
  }

  function notify(message, ok) {
    const text = message || (ok ? 'عملیات انجام شد.' : 'عملیات انجام نشد.');
    if (window.Notyf) {
      const n = new Notyf({ duration: 4200, dismissible: true, position: { x: 'left', y: 'top' } });
      if (ok && typeof n.success === 'function') n.success(text);
      else if (!ok && typeof n.error === 'function') n.error(text);
      else if (typeof n.open === 'function') n.open({ type: ok ? 'success' : 'error', message: text });
      else alert(text);
      return;
    }
    const el = document.createElement('div');
    el.className = 'pa-toast ' + (ok ? 'ok' : 'err');
    el.textContent = text;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 4200);
  }

  async function postJson(url, payload) {
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrf(),
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify(payload || {})
    });
    let data = null;
    try { data = await res.json(); } catch (_) { data = { success: false, message: 'پاسخ سرور قابل خواندن نیست.' }; }
    if (!res.ok && data && !data.message) data.message = 'درخواست با خطا مواجه شد.';
    return data;
  }

  function showSettleModal(btn) {
    settleUrl = btn.dataset.url || '';
    const title = document.getElementById('paSettleTitle');
    const home = document.getElementById('paHomeLabel');
    const away = document.getElementById('paAwayLabel');
    if (title) title.textContent = btn.dataset.title || 'ثبت نتیجه بازی';
    if (home) home.textContent = btn.dataset.home || 'میزبان';
    if (away) away.textContent = btn.dataset.away || 'مهمان';
    const modalEl = document.getElementById('predictionSettleModal');
    if (modalEl && window.bootstrap) new bootstrap.Modal(modalEl).show();
  }

  function hideSettleModal() {
    const modalEl = document.getElementById('predictionSettleModal');
    if (!modalEl || !window.bootstrap) return;
    const instance = bootstrap.Modal.getInstance(modalEl);
    if (instance) instance.hide();
  }

  document.addEventListener('click', function (event) {
    const actionBtn = event.target.closest('[data-admin-action]');
    if (actionBtn) {
      const action = actionBtn.dataset.adminAction;
      const url = actionBtn.dataset.url || '';
      if (action === 'settle') {
        showSettleModal(actionBtn);
        return;
      }
      if (action === 'cancel') {
        if (!confirm('لغو بازی باعث برگشت کامل مبالغ در انتظار نتیجه می‌شود. مطمئن هستید؟')) return;
        actionBtn.disabled = true;
        postJson(url, {}).then(data => {
          notify(data.message, !!data.success);
          if (data.success) setTimeout(() => location.reload(), 1200);
        }).catch(() => notify('خطا در ارتباط با سرور.', false)).finally(() => { actionBtn.disabled = false; });
        return;
      }
      if (action === 'close') {
        if (!confirm('ثبت پیش‌بینی جدید برای این بازی بسته شود؟')) return;
        actionBtn.disabled = true;
        postJson(url, {}).then(data => {
          notify(data.message, !!data.success);
          if (data.success) setTimeout(() => location.reload(), 1000);
        }).catch(() => notify('خطا در ارتباط با سرور.', false)).finally(() => { actionBtn.disabled = false; });
      }
      return;
    }

    const resultBtn = event.target.closest('#predictionSettleModal [data-result]');
    if (resultBtn) {
      if (!settleUrl) return;
      if (!confirm('نتیجه ثبت و تسویه مالی انجام شود؟ این عملیات قابل برگشت نیست.')) return;
      const old = resultBtn.innerHTML;
      resultBtn.disabled = true;
      resultBtn.innerHTML = '<span class="material-icons">hourglass_top</span> در حال تسویه...';
      postJson(settleUrl, { result: resultBtn.dataset.result }).then(data => {
        hideSettleModal();
        notify(data.message, !!data.success);
        if (data.success) setTimeout(() => location.reload(), 1500);
      }).catch(() => notify('خطا در ارتباط با سرور.', false)).finally(() => {
        resultBtn.disabled = false;
        resultBtn.innerHTML = old;
      });
    }
  });
})();
