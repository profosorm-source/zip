(function () {
  'use strict';

  const root = document.getElementById('financeWithdrawRoot');
  if (!root) return;

  const config = {
    currency: (root.dataset.currency || 'IRT').toUpperCase(),
    min: parseFloat(root.dataset.min || '0'),
    max: parseFloat(root.dataset.max || '0'),
    fee: parseFloat(root.dataset.fee || '0'),
    action: root.dataset.action || '',
    redirect: root.dataset.redirect || '/withdrawals',
    limitsUrl: root.dataset.limitsUrl || '',
    csrf: root.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
  };

  const form = document.getElementById('withdrawalForm');
  const amountInput = document.getElementById('amount');
  const receiveAmount = document.getElementById('receive_amount');
  const confirmInput = document.getElementById('confirm_withdrawal');
  const submitBtn = document.getElementById('submit_btn');
  let notifier = null;

  function notify(type, message) {
    if (!notifier && typeof window.Notyf !== 'undefined') {
      notifier = new window.Notyf({ duration: 4500, position: { x: 'left', y: 'top' }, dismissible: true });
    }
    if (notifier && typeof notifier[type] === 'function') {
      notifier[type](message);
      return;
    }
    const toast = document.createElement('div');
    toast.textContent = message;
    Object.assign(toast.style, {
      position: 'fixed', left: '24px', top: '78px', zIndex: '10050', maxWidth: '360px',
      padding: '12px 16px', borderRadius: '12px', background: type === 'error' ? 'rgba(246,70,93,.96)' : 'rgba(30,217,168,.96)',
      color: '#fff', fontFamily: 'Vazirmatn, Tahoma, sans-serif', fontSize: '13px', fontWeight: '800', lineHeight: '1.8',
      boxShadow: '0 14px 34px rgba(0,0,0,.28)'
    });
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4500);
  }

  function formatAmount(value) {
    return config.currency === 'USDT'
      ? (value || 0).toLocaleString('en-US', { maximumFractionDigits: 4 })
      : Math.round(value || 0).toLocaleString('en-US');
  }

  function updateReceiveAmount() {
    const amount = parseFloat(amountInput?.value || '0') || 0;
    const fee = amount * (config.fee / 100);
    const net = Math.max(amount - fee, 0);
    if (receiveAmount) receiveAmount.textContent = formatAmount(net);
  }

  function setAmount(value) {
    if (!amountInput) return;
    amountInput.value = String(value || 0);
    amountInput.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function generateIdempotencyKey() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return 'WTH_' + window.crypto.randomUUID();
    return 'WTH_' + Date.now() + '_' + Math.random().toString(16).slice(2);
  }

  function generateDeviceFingerprint() {
    const parts = [navigator.userAgent, navigator.language, screen.width + 'x' + screen.height + 'x' + screen.colorDepth, new Date().getTimezoneOffset()];
    let hash = 0;
    const str = parts.join('|');
    for (let i = 0; i < str.length; i++) hash = ((hash << 5) - hash) + str.charCodeAt(i) | 0;
    return Math.abs(hash).toString(16).padStart(16, '0').slice(0, 16);
  }

  async function loadWithdrawalLimits() {
    if (!config.limitsUrl) return;
    try {
      const r = await fetch(config.limitsUrl + '?currency=' + encodeURIComponent(config.currency), { headers: { Accept: 'application/json' } });
      const data = await r.json();
      const limits = data.limits;
      if (!limits) return;
      const box = document.getElementById('withdrawalLimitsBox');
      const label = document.getElementById('limitsProfileLabel');
      const detail = document.getElementById('limitsDetail');
      if (!box || !label || !detail) return;
      label.textContent = 'سطح برداشت شما: ' + (limits.profile_label || 'استاندارد');
      detail.innerHTML = '<span class="fin-badge fin-badge--info">برداشت امروز: ' + (limits.used_today || 0) + '/' + (limits.daily_count === 999 ? '∞' : (limits.daily_count || 0)) + '</span> ' +
        '<span class="fin-badge fin-badge--warning">حداقل: ' + Number(limits.min_amount || config.min).toLocaleString('en-US') + '</span> ' +
        '<span class="fin-badge fin-badge--success">حداکثر: ' + Number(limits.max_amount || config.max).toLocaleString('en-US') + '</span>';
      box.style.display = 'flex';
    } catch (_) {}
  }

  document.getElementById('card_id')?.addEventListener('change', function () {
    const selectedOption = this.options[this.selectedIndex];
    const cardInfo = document.getElementById('card_info');
    const cardDetails = document.getElementById('card_details');
    if (this.value && cardInfo && cardDetails) {
      cardDetails.innerHTML = selectedOption.textContent.trim();
      cardInfo.style.display = 'flex';
    } else if (cardInfo) {
      cardInfo.style.display = 'none';
    }
  });

  amountInput?.addEventListener('input', updateReceiveAmount);
  confirmInput?.addEventListener('change', function () { if (submitBtn) submitBtn.disabled = !this.checked; });

  document.addEventListener('click', function (event) {
    const quick = event.target.closest('[data-action="set-quick-amount"]');
    if (quick) setAmount(parseFloat(quick.dataset.value || '0'));
    if (event.target.closest('[data-action="set-max-amount"]')) setAmount(config.max);
  });

  form?.addEventListener('submit', async function (event) {
    event.preventDefault();
    const amount = parseFloat(amountInput?.value || '0') || 0;

    if (amount < config.min) return notify('error', 'مبلغ برداشت کمتر از حداقل مجاز است.');
    if (amount > config.max) return notify('error', 'موجودی کافی نیست.');
    if (!confirmInput?.checked) return notify('error', 'برای ادامه باید تأیید نهایی را فعال کنید.');

    const network = document.getElementById('network')?.value || '';
    const walletAddress = document.getElementById('wallet_address')?.value || '';
    if (config.currency === 'USDT') {
      if (!network) return notify('error', 'شبکه انتقال را انتخاب کنید.');
      if (!walletAddress) return notify('error', 'آدرس کیف پول را وارد کنید.');
      if (network === 'BNB20' && !walletAddress.startsWith('0x')) return notify('error', 'آدرس BNB20 باید با 0x شروع شود.');
      if (network === 'TRC20' && !walletAddress.startsWith('T')) return notify('error', 'آدرس TRC20 باید با T شروع شود.');
    }

    document.getElementById('idempotencyKey').value = generateIdempotencyKey();
    document.getElementById('deviceFingerprint').value = generateDeviceFingerprint();
    document.getElementById('requestTimestamp').value = String(Date.now());

    if (window.Swal) {
      const result = await window.Swal.fire({ title: 'ثبت درخواست برداشت؟', text: 'پس از ثبت، مبلغ تا زمان بررسی قفل می‌شود.', icon: 'warning', showCancelButton: true, confirmButtonText: 'تأیید و ثبت', cancelButtonText: 'انصراف', confirmButtonColor: '#F0B90B' });
      if (!result.isConfirmed) return;
    }

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.classList.add('loading');
    }

    try {
      const formData = new FormData(form);
      const response = await fetch(config.action, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf }, body: formData });
      const data = await response.json().catch(() => ({}));
      if (response.ok && data.success) {
        notify('success', data.message || 'درخواست برداشت با موفقیت ثبت شد.');
        setTimeout(() => { window.location.href = config.redirect; }, 900);
      } else {
        notify('error', data.message || 'ثبت درخواست برداشت انجام نشد.');
      }
    } catch (_) {
      notify('error', 'خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.');
    } finally {
      if (submitBtn) {
        submitBtn.disabled = !confirmInput?.checked;
        submitBtn.classList.remove('loading');
      }
    }
  });

  updateReceiveAmount();
  loadWithdrawalLimits();
})();
