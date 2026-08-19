/* admin/vitrine-dispute.js — استخراج‌شده از views/admin/vitrine/dispute.php (CSP-safe) */
(function(){
'use strict';
var __D=(function(){var el=document.getElementById('vitrine-dispute-data');try{return JSON.parse(el.textContent);}catch(e){return [];}})();

const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const ID   = __D[0];
window.resolveDispute = function(winner) {
  const msg = winner === 'seller'
    ? `وجه ${__D[1]} USDT به فروشنده پرداخت شود؟`
    : `وجه ${__D[2]} USDT به خریدار استرداد شود؟`;

  if (!confirm(msg + '\n\nاین عمل غیرقابل بازگشت است.')) return;

  fetch(`/admin/vitrine/${ID}/resolve`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify({ winner })
  })
  .then(r => r.json())
  .then(d => {
    alert(d.success ? '✅ ' + (d.message || 'انجام شد') : '❌ ' + (d.message || 'خطا'));
    if (d.success) window.location.href = '/admin/vitrine?status=disputed';
  });
}

})();
