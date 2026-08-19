(function () {
  'use strict';
  let selectedPool = 0;
  function csrf() { return document.querySelector('meta[name="csrf-token"]')?.content || window.csrfToken || ''; }
  function notify(msg, ok) { if (ok) alert(msg); else alert(msg); }
  document.querySelectorAll('.prediction-card').forEach(card => {
    card.addEventListener('click', function () {
      document.querySelectorAll('.prediction-card').forEach(c => c.classList.remove('border-primary', 'bg-primary', 'bg-opacity-10'));
      this.classList.add('border-primary', 'bg-primary', 'bg-opacity-10');
      const input = this.querySelector('input[type="radio"]');
      if (input) input.checked = true;
      selectedPool = parseFloat(this.dataset.pool || '0') || 0;
      updatePreview();
    });
  });
  document.getElementById('betAmount')?.addEventListener('input', updatePreview);
  function updatePreview() {
    const form = document.getElementById('betForm');
    const amount = parseFloat(document.getElementById('betAmount')?.value || '0') || 0;
    const preview = document.getElementById('returnPreview');
    const previewAmt = document.getElementById('previewAmount');
    if (!form || !preview || !previewAmt || amount <= 0 || selectedPool < 0) { if (preview) preview.classList.add('d-none'); return; }
    const totalPool = parseFloat(form.dataset.totalPool || '0') || 0;
    const commission = parseFloat(form.dataset.commission || '0') || 0;
    const winnerPoolIfWins = selectedPool + amount;
    const loserPoolIfWins = Math.max(0, totalPool - selectedPool);
    if (winnerPoolIfWins <= 0) { preview.classList.add('d-none'); return; }
    const siteFee = loserPoolIfWins * commission;
    const profitPool = Math.max(0, loserPoolIfWins - siteFee);
    const estimatedPayout = amount + ((amount / winnerPoolIfWins) * profitPool);
    preview.classList.remove('d-none');
    preview.style.display = 'block';
    previewAmt.textContent = estimatedPayout.toFixed(4);
  }
  document.getElementById('betForm')?.addEventListener('submit', function (e) {
    e.preventDefault();
    const btn = document.getElementById('submitBet');
    const old = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.textContent = 'در حال ثبت...'; }
    const fd = new FormData(this);
    const data = Object.fromEntries(fd.entries());
    fetch(this.action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ prediction: data.prediction, amount_usdt: data.amount_usdt, idempotency_key: data.idempotency_key })
    }).then(r => r.json()).then(d => {
      if (d.success) { notify(d.message || 'پیش‌بینی ثبت شد.', true); location.reload(); }
      else { notify(d.message || 'خطا در ثبت پیش‌بینی.', false); if (btn) { btn.disabled = false; btn.innerHTML = old; } }
    }).catch(() => { notify('خطای شبکه. لطفاً دوباره تلاش کنید.', false); if (btn) { btn.disabled = false; btn.innerHTML = old; } });
  });
})();
