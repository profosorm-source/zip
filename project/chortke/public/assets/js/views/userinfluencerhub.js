(function () {
  'use strict';
  const root = document.getElementById('influencerHub');
  if (!root) return;

  let rejectOrderId = null;
  let disputeOrderId = null;

  function csrf() { return document.querySelector('meta[name="csrf-token"]')?.content || window.csrfToken || ''; }
  function basePath() { return location.pathname.startsWith('/chortke/') ? '/chortke' : ''; }
  function jsonHeaders() { return { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' }; }
  function notify(msg, ok) {
    if (window.Notyf) {
      const n = new Notyf({ duration: 3500, position: { x: 'left', y: 'top' }, dismissible: true });
      if (ok && typeof n.success === 'function') n.success(msg);
      else if (!ok && typeof n.error === 'function') n.error(msg);
      else if (typeof n.open === 'function') n.open({ type: ok ? 'success' : 'error', message: msg });
      else alert(msg);
    } else { alert(msg); }
  }
  function postJson(url, body) {
    return fetch(basePath() + url, {
      method: 'POST',
      headers: jsonHeaders(),
      body: JSON.stringify(body || {})
    }).then(r => r.json().catch(() => ({ success: false, message: 'پاسخ سرور قابل خواندن نیست.' })));
  }
  function reloadSoon(section) {
    setTimeout(() => { location.href = root.dataset.base + (section ? '?section=' + section : ''); }, 550);
  }
  function switchTab(name) {
    document.querySelectorAll('[data-inf-panel]').forEach(p => p.classList.toggle('active', p.dataset.infPanel === name));
    document.querySelectorAll('[data-inf-tab]').forEach(t => t.classList.toggle('active', t.dataset.infTab === name));
    if (history.replaceState) history.replaceState(null, '', root.dataset.base + (name === 'overview' ? '' : '?section=' + encodeURIComponent(name)));
  }
  function showModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    if (window.bootstrap) new bootstrap.Modal(el).show();
  }
  function hideModal(id) {
    const el = document.getElementById(id);
    if (!el || !window.bootstrap) return;
    const instance = bootstrap.Modal.getInstance(el);
    if (instance) instance.hide();
  }
  function respondOrder(id, action, reason) {
    if (!id) return;
    postJson('/influencer/orders/' + id + '/respond', { action: action, reason: reason || '' }).then(d => {
      if (d.success) { notify(d.message || 'عملیات انجام شد.', true); reloadSoon('incoming'); }
      else notify(d.message || 'عملیات انجام نشد.', false);
    }).catch(() => notify('خطا در ارتباط با سرور.', false));
  }

  document.addEventListener('click', function (e) {
    const tab = e.target.closest('[data-inf-tab]');
    if (tab) { switchTab(tab.dataset.infTab); return; }

    const select = e.target.closest('[data-select-influencer]');
    if (select) {
      document.getElementById('hubInfluencerId').value = select.dataset.id;
      document.getElementById('selectedInfluencerText').textContent = 'سفارش برای @' + (select.dataset.username || 'اینفلوئنسر');
      root.dataset.story = select.dataset.story || '0';
      root.dataset.post24 = select.dataset.post24 || '0';
      root.dataset.post48 = select.dataset.post48 || '0';
      root.dataset.post72 = select.dataset.post72 || '0';
      updatePrice();
      document.querySelector('.inf-order-box')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }

    const respondBtn = e.target.closest('[data-action="respond-order"]');
    if (respondBtn) {
      respondOrder(respondBtn.dataset.orderId, respondBtn.dataset.actionType || 'accept');
      return;
    }

    const rejectBtn = e.target.closest('[data-action="prompt-reject"]');
    if (rejectBtn) {
      rejectOrderId = rejectBtn.dataset.orderId;
      const input = document.getElementById('rejectReason');
      if (input) input.value = '';
      showModal('rejectModal');
      return;
    }

    const proofBtn = e.target.closest('[data-action="open-proof-modal"]');
    if (proofBtn) {
      const form = document.getElementById('proofForm');
      const input = document.getElementById('proofOrderId');
      if (form) form.reset();
      if (input) input.value = proofBtn.dataset.orderId || '';
      showModal('proofModal');
      return;
    }

    const confirmBtn = e.target.closest('[data-action="confirm-order"]');
    if (confirmBtn) {
      if (!confirm('آیا از انجام صحیح سفارش اطمینان دارید؟ مبلغ از صندوق امانی به اینفلوئنسر پرداخت می‌شود.')) return;
      postJson('/influencer/ads/orders/' + confirmBtn.dataset.orderId + '/confirm', {}).then(d => {
        if (d.success) { notify(d.message || 'سفارش تأیید شد.', true); reloadSoon('placed'); }
        else notify(d.message || 'تأیید سفارش انجام نشد.', false);
      }).catch(() => notify('خطا در ارتباط با سرور.', false));
      return;
    }

    const disputeBtn = e.target.closest('[data-action="open-dispute-modal"]');
    if (disputeBtn) {
      disputeOrderId = disputeBtn.dataset.orderId;
      const reason = document.getElementById('disputeReason');
      if (reason) reason.value = '';
      showModal('disputeModal');
    }
  });

  const init = root.dataset.initialSection || 'overview';
  switchTab(init);
  const requestedInfluencer = new URLSearchParams(location.search).get('influencer_id');
  if (requestedInfluencer) {
    const target = document.querySelector('[data-select-influencer][data-id="' + requestedInfluencer + '"]');
    if (target) setTimeout(() => target.click(), 150);
  }

  function updatePrice() {
    const type = document.getElementById('hubOrderType')?.value || 'story';
    const dur = document.getElementById('hubDuration')?.value || '24';
    let price = '0';
    if (type === 'story') price = root.dataset.story || '0';
    else price = dur === '48' ? (root.dataset.post48 || '0') : (dur === '72' ? (root.dataset.post72 || '0') : (root.dataset.post24 || '0'));
    document.getElementById('hubPricePreview').textContent = Number(price || 0).toLocaleString('fa-IR') + ' تومان';
  }
  document.getElementById('hubOrderType')?.addEventListener('change', updatePrice);
  document.getElementById('hubDuration')?.addEventListener('change', updatePrice);

  document.getElementById('hubOrderForm')?.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!document.getElementById('hubInfluencerId').value) { notify('ابتدا یک اینفلوئنسر انتخاب کنید.', false); return; }
    const btn = this.querySelector('button[type="submit"]');
    const old = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = 'در حال ثبت...';
    fetch(root.dataset.storeUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(this) })
      .then(r => r.json()).then(d => {
        if (d.success) { notify(d.message || 'سفارش ثبت شد.', true); setTimeout(() => location.href = d.redirect || (root.dataset.base + '?section=placed'), 700); }
        else { notify(d.message || 'ثبت سفارش انجام نشد.', false); btn.disabled = false; btn.innerHTML = old; }
      }).catch(() => { notify('خطا در ارتباط با سرور.', false); btn.disabled = false; btn.innerHTML = old; });
  });

  document.getElementById('rejectConfirmBtn')?.addEventListener('click', function () {
    const reason = document.getElementById('rejectReason')?.value || '';
    hideModal('rejectModal');
    respondOrder(rejectOrderId, 'reject', reason);
  });

  document.getElementById('proofForm')?.addEventListener('submit', function (e) {
    e.preventDefault();
    const id = document.getElementById('proofOrderId')?.value;
    if (!id) return;
    const btn = document.getElementById('proofSubmitBtn');
    const old = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = 'در حال ارسال...'; }
    fetch(basePath() + '/influencer/orders/' + id + '/proof', {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
      body: new FormData(this)
    }).then(r => r.json()).then(d => {
      if (d.success) { notify(d.message || 'مدرک ثبت شد.', true); reloadSoon('incoming'); }
      else { notify(d.message || 'ثبت مدرک انجام نشد.', false); if (btn) { btn.disabled = false; btn.innerHTML = old; } }
    }).catch(() => { notify('خطا در ارتباط با سرور.', false); if (btn) { btn.disabled = false; btn.innerHTML = old; } });
  });

  document.getElementById('disputeSubmitBtn')?.addEventListener('click', function () {
    const reason = (document.getElementById('disputeReason')?.value || '').trim();
    if (!reason) { notify('دلیل اعتراض الزامی است.', false); return; }
    const btn = this;
    const old = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = 'در حال ثبت...';
    postJson('/influencer/ads/orders/' + disputeOrderId + '/dispute', { reason: reason }).then(d => {
      if (d.success) { notify(d.message || 'اعتراض ثبت شد.', true); reloadSoon('disputes'); }
      else { notify(d.message || 'ثبت اعتراض انجام نشد.', false); btn.disabled = false; btn.innerHTML = old; }
    }).catch(() => { notify('خطا در ارتباط با سرور.', false); btn.disabled = false; btn.innerHTML = old; });
  });
})();
