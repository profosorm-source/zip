(function () {
  'use strict';
  function csrf() { return document.querySelector('meta[name="csrf-token"]')?.content || window.csrfToken || ''; }
  function basePath() { return location.pathname.startsWith('/chortke/') ? '/chortke' : ''; }
  function postJson(url, data) {
    return fetch(basePath() + url, {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest'},
      body: JSON.stringify(data || {})
    }).then(r => r.json());
  }
  let rejectOrderId = null;
  function respond(id, action, reason) {
    postJson('/influencer/orders/' + id + '/respond', {action, reason: reason || ''}).then(d => {
      if (d.success) location.reload(); else alert(d.message || 'خطا');
    }).catch(() => alert('خطا در ارتباط با سرور'));
  }
  document.addEventListener('click', function (e) {
    const respondBtn = e.target.closest('[data-action="respond-order"]');
    if (respondBtn) {
      respond(respondBtn.getAttribute('data-order-id'), respondBtn.getAttribute('data-action-type') || 'accept');
      return;
    }
    const rejectBtn = e.target.closest('[data-action="prompt-reject"]');
    if (rejectBtn) {
      rejectOrderId = rejectBtn.getAttribute('data-order-id');
      const input = document.getElementById('rejectReason');
      if (input) input.value = '';
      if (window.bootstrap && document.getElementById('rejectModal')) new bootstrap.Modal(document.getElementById('rejectModal')).show();
      else respond(rejectOrderId, 'reject', prompt('دلیل رد سفارش:') || '');
      return;
    }
    const proofBtn = e.target.closest('[data-action="open-proof-modal"]');
    if (proofBtn) {
      const id = proofBtn.getAttribute('data-order-id');
      const orderInput = document.getElementById('proofOrderId');
      const form = document.getElementById('proofForm');
      if (form) form.reset();
      if (orderInput) orderInput.value = id;
      if (window.bootstrap && document.getElementById('proofModal')) new bootstrap.Modal(document.getElementById('proofModal')).show();
    }
  });
  document.addEventListener('DOMContentLoaded', function () {
    const rejectConfirm = document.getElementById('rejectConfirmBtn');
    if (rejectConfirm) rejectConfirm.addEventListener('click', function () {
      const reason = document.getElementById('rejectReason')?.value || '';
      respond(rejectOrderId, 'reject', reason);
    });
    const proofForm = document.getElementById('proofForm');
    if (proofForm) proofForm.addEventListener('submit', function (e) {
      e.preventDefault();
      const id = document.getElementById('proofOrderId')?.value;
      const btn = document.getElementById('proofSubmitBtn');
      if (btn) { btn.disabled = true; btn.textContent = 'در حال ارسال...'; }
      fetch(basePath() + '/influencer/orders/' + id + '/proof', {method: 'POST', headers: {'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest'}, body: new FormData(this)})
        .then(r => r.json()).then(d => { if (d.success) location.reload(); else { alert(d.message || 'خطا'); if (btn) { btn.disabled = false; btn.textContent = 'ثبت مدرک'; } } })
        .catch(() => { if (btn) { btn.disabled = false; btn.textContent = 'ثبت مدرک'; } alert('خطا در ارتباط'); });
    });
  });
})();
