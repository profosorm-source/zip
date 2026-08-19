(function () {
  'use strict';
  function csrf() { return document.querySelector('meta[name="csrf-token"]')?.content || window.csrfToken || ''; }
  function basePath() { return location.pathname.startsWith('/chortke/') ? '/chortke' : ''; }
  let disputeOrderId = null;
  function post(url, body) {
    return fetch(basePath() + url, {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest'},
      body: JSON.stringify(body || {})
    }).then(r => r.json());
  }
  document.addEventListener('click', function (e) {
    const confirmBtn = e.target.closest('[data-action="confirm-order"]');
    if (confirmBtn) {
      if (!confirm('آیا از انجام صحیح سفارش اطمینان دارید؟ مبلغ به اینفلوئنسر پرداخت می‌شود.')) return;
      post('/influencer/ads/orders/' + confirmBtn.getAttribute('data-order-id') + '/confirm', {}).then(d => {
        if (d.success) location.reload(); else alert(d.message || 'خطا');
      });
      return;
    }
    const disputeBtn = e.target.closest('[data-action="open-dispute-modal"]');
    if (disputeBtn) {
      disputeOrderId = disputeBtn.getAttribute('data-order-id');
      const reason = document.getElementById('disputeReason');
      if (reason) reason.value = '';
      if (window.bootstrap && document.getElementById('disputeModal')) new bootstrap.Modal(document.getElementById('disputeModal')).show();
    }
  });
  document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('disputeSubmitBtn');
    if (btn) btn.addEventListener('click', function () {
      const reason = (document.getElementById('disputeReason')?.value || '').trim();
      if (!reason) { alert('دلیل اعتراض الزامی است.'); return; }
      btn.disabled = true; btn.textContent = 'در حال ارسال...';
      post('/influencer/ads/orders/' + disputeOrderId + '/dispute', {reason}).then(d => {
        if (d.success) location.reload(); else { alert(d.message || 'خطا'); btn.disabled = false; btn.textContent = 'ثبت اعتراض'; }
      }).catch(() => { btn.disabled = false; btn.textContent = 'ثبت اعتراض'; alert('خطا در ارتباط'); });
    });
  });
})();
