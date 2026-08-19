/* public/assets/js/admin/seoadindex.js — CSP-safe SEO ad admin actions */
(function () {
  'use strict';

  const root = document.getElementById('adminSeoAdsRoot');
  const base = root?.dataset.base || '/admin/seo-ad';
  function csrf() { return document.querySelector('meta[name="csrf-token"]')?.content || window.csrfToken || ''; }
  function actionUrl(id, action) { return base.replace(/\/$/, '') + '/' + encodeURIComponent(id) + '/' + action; }
  let rejectId = null;

  async function post(url, body) {
    const options = { method: 'POST', headers: { 'X-CSRF-Token': csrf(), 'Accept': 'application/json' } };
    if (body instanceof FormData) options.body = body;
    const res = await fetch(url, options);
    return res.json().catch(() => ({ success: false, message: 'پاسخ نامعتبر سرور' }));
  }

  document.querySelectorAll('.btn-approve').forEach(btn => {
    btn.addEventListener('click', async function() {
      if(!confirm('تایید این آگهی؟')) return;
      const data = await post(actionUrl(this.dataset.id, 'approve'));
      if(data.success) location.reload(); else alert(data.message || 'خطا');
    });
  });

  document.querySelectorAll('.btn-reject').forEach(btn => {
    btn.addEventListener('click', function() {
      rejectId = this.dataset.id;
      const textarea = document.getElementById('rejectReason');
      if (textarea) textarea.value = '';
      if (window.bootstrap && document.getElementById('rejectModal')) {
        new bootstrap.Modal(document.getElementById('rejectModal')).show();
      } else {
        const reason = prompt('دلیل رد را بنویسید:');
        if (reason) submitReject(reason);
      }
    });
  });

  async function submitReject(reason) {
    const fd = new FormData();
    fd.append('reason', reason);
    const data = await post(actionUrl(rejectId, 'reject'), fd);
    if(data.success) location.reload(); else alert(data.message || 'خطا');
  }

  document.getElementById('btnConfirmReject')?.addEventListener('click', function() {
    const reason = document.getElementById('rejectReason').value.trim();
    if(!reason) { alert('دلیل رد الزامی است.'); return; }
    submitReject(reason);
  });

  document.querySelectorAll('.btn-pause-ad').forEach(btn => {
    btn.addEventListener('click', async function() {
      if(!confirm('توقف این آگهی؟')) return;
      const data = await post(actionUrl(this.dataset.id, 'pause'));
      if(data.success) location.reload(); else alert(data.message || 'خطا');
    });
  });
})();
