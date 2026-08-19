(function () {
  'use strict';
  function csrf() { return document.querySelector('meta[name="csrf-token"]')?.content || window.csrfToken || ''; }
  function basePath() { return location.pathname.startsWith('/chortke/') ? '/chortke' : ''; }
  function orderId() { const m = location.pathname.match(/\/influencer\/orders\/(\d+)\/dispute/); return m ? m[1] : '0'; }
  function post(url, body, isForm) {
    const opts = { method: 'POST', headers: {'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest'} };
    if (isForm) opts.body = body;
    else { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body || {}); }
    return fetch(basePath() + url, opts).then(r => r.json());
  }
  document.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'msgAttachment') {
      const name = document.getElementById('attachName');
      if (name) name.textContent = e.target.files[0]?.name || '';
    }
  });
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-action="send-dispute-msg"]')) {
      const text = (document.getElementById('msgText')?.value || '').trim();
      if (!text) return;
      const fd = new FormData();
      fd.append('_csrf_token', csrf());
      fd.append('message', text);
      const file = document.getElementById('msgAttachment')?.files[0];
      if (file) fd.append('attachment', file);
      post('/influencer/orders/' + orderId() + '/dispute/message', fd, true).then(d => { if (d.success) location.reload(); else alert(d.message || 'خطا'); });
      return;
    }
    if (e.target.closest('[data-action="escalate-dispute"]')) {
      if (!confirm('آیا می‌خواهید پرونده را به مدیر ارجاع دهید؟')) return;
      post('/influencer/orders/' + orderId() + '/dispute/escalate', {}).then(d => { if (d.success) location.reload(); else alert(d.message || 'خطا'); });
      return;
    }
    if (e.target.closest('[data-action="open-agreement-modal"]')) {
      if (window.bootstrap && document.getElementById('agreementModal')) new bootstrap.Modal(document.getElementById('agreementModal')).show();
      return;
    }
    if (e.target.closest('[data-action="submit-agreement"]')) {
      const verdict = document.getElementById('verdictSelect')?.value || 'favor_influencer';
      const resolution = (document.getElementById('agreementNote')?.value || '').trim();
      post('/influencer/orders/' + orderId() + '/dispute/resolve', {verdict, resolution}).then(d => { if (d.success) location.reload(); else alert(d.message || 'خطا'); });
    }
  });
  document.addEventListener('DOMContentLoaded', function () { const box = document.getElementById('messagesBox'); if (box) box.scrollTop = box.scrollHeight; });
})();
