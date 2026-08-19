/* public/assets/js/admin/vitrine-index.js — استخراج‌شده از views/admin/vitrine/index.php برای سازگاری با CSP و کش‌پذیری */
(function () {
  'use strict';
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const H    = { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF };

let pendingRejectId = null;

function approveItem(id) {
  if (!confirm('آیا این آگهی را تایید و منتشر می‌کنید؟')) return;
  fetch(`/admin/vitrine/${id}/approve`, { method: 'POST', headers: H })
    .then(r => r.json())
    .then(d => { alert(d.message); if (d.success) location.reload(); });
}

function openReject(id) {
  pendingRejectId = id;
  document.getElementById('rejectReason').value = '';
  new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

document.getElementById('confirmRejectBtn')?.addEventListener('click', function () {
  if (!pendingRejectId) return;
  const reason = document.getElementById('rejectReason').value;
  fetch(`/admin/vitrine/${pendingRejectId}/reject`, {
    method: 'POST', headers: H, body: JSON.stringify({ reason })
  }).then(r => r.json()).then(d => {
    bootstrap.Modal.getInstance(document.getElementById('rejectModal'))?.hide();
    if (d.success) location.reload(); else alert(d.message);
  });
});

function releaseFunds(id) {
  if (!confirm('آزادسازی وجه به فروشنده؟ این عمل غیرقابل بازگشت است.')) return;
  fetch(`/admin/vitrine/${id}/release`, { method: 'POST', headers: H })
    .then(r => r.json())
    .then(d => { alert(d.message); if (d.success) location.reload(); });
}

function refundBuyer(id) {
  if (!confirm('استرداد وجه به خریدار؟ این عمل غیرقابل بازگشت است.')) return;
  fetch(`/admin/vitrine/${id}/refund`, { method: 'POST', headers: H })
    .then(r => r.json())
    .then(d => { alert(d.message); if (d.success) location.reload(); });
}
})();
