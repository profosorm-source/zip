(function () {
  'use strict';
  const root = document.getElementById('predictionHub');
  if (!root) return;

  function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content || window.csrfToken || '';
  }

  function notify(message, ok) {
    const text = message || (ok ? 'عملیات انجام شد.' : 'عملیات انجام نشد.');
    if (window.Notyf) {
      const n = new Notyf({ duration: 3800, dismissible: true, position: { x: 'left', y: 'top' } });
      if (ok && typeof n.success === 'function') n.success(text);
      else if (!ok && typeof n.error === 'function') n.error(text);
      else if (typeof n.open === 'function') n.open({ type: ok ? 'success' : 'error', message: text });
      else alert(text);
      return;
    }
    const el = document.createElement('div');
    el.className = 'pred-toast ' + (ok ? 'ok' : 'err');
    el.textContent = text;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 4200);
  }

  function switchTab(name, replace) {
    const section = ['open', 'my-bets', 'results', 'rules'].includes(name) ? name : 'open';
    document.querySelectorAll('[data-pred-panel]').forEach(panel => {
      panel.classList.toggle('active', panel.dataset.predPanel === section);
    });
    document.querySelectorAll('[data-pred-tab]').forEach(tab => {
      tab.classList.toggle('active', tab.dataset.predTab === section);
    });
    if (history.replaceState) {
      const url = root.dataset.base + (section === 'open' ? '' : '?section=' + encodeURIComponent(section));
      (replace === false ? history.pushState : history.replaceState).call(history, null, '', url);
    }
  }

  function selectedChoice(form) {
    const checked = form.querySelector('.pred-choice input[type="radio"]:checked');
    return checked ? checked.closest('.pred-choice') : null;
  }

  function updatePreview(form) {
    if (!form) return;
    const amountInput = form.querySelector('.pred-amount');
    const preview = form.querySelector('.pred-preview');
    const amountEl = form.querySelector('[data-preview-amount]');
    const choice = selectedChoice(form);
    const amount = parseFloat(amountInput?.value || '0') || 0;
    if (!preview || !amountEl || !choice || amount <= 0) {
      if (preview) preview.hidden = true;
      return;
    }
    const selectedPool = Math.max(0, parseFloat(choice.dataset.pool || '0') || 0);
    const totalPool = Math.max(0, parseFloat(form.dataset.totalPool || '0') || 0);
    const bonusPool = Math.max(0, parseFloat(form.dataset.bonusPool || '0') || 0);
    const commission = Math.max(0, parseFloat(form.dataset.commission || '0') || 0);
    const winnerPool = selectedPool + amount;
    if (winnerPool <= 0) {
      preview.hidden = true;
      return;
    }
    const loserPool = Math.max(0, totalPool - selectedPool);
    const siteFee = loserPool * commission;
    const distributableProfit = Math.max(0, loserPool - siteFee) + bonusPool;
    const estimated = amount + ((amount / winnerPool) * distributableProfit);
    amountEl.textContent = estimated.toFixed(4);
    preview.hidden = false;
  }

  function setLoading(btn, loading) {
    if (!btn) return;
    if (loading) {
      btn.dataset.oldHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="material-icons">hourglass_top</span> در حال ثبت...';
    } else {
      btn.disabled = false;
      if (btn.dataset.oldHtml) btn.innerHTML = btn.dataset.oldHtml;
    }
  }

  async function postPrediction(form) {
    const btn = form.querySelector('button[type="submit"]');
    const checked = form.querySelector('input[name="prediction"]:checked');
    const amount = form.querySelector('input[name="amount_usdt"]')?.value || '';
    const idempotency = form.querySelector('input[name="idempotency_key"]')?.value || '';
    if (!checked) {
      notify('ابتدا نتیجه موردنظر خود را انتخاب کنید.', false);
      return;
    }
    if (!amount || Number(amount) <= 0) {
      notify('مبلغ پیش‌بینی را درست وارد کنید.', false);
      return;
    }

    setLoading(btn, true);
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 20000);
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf(),
          'X-Requested-With': 'XMLHttpRequest'
        },
        signal: controller.signal,
        body: JSON.stringify({
          prediction: checked.value,
          amount_usdt: amount,
          idempotency_key: idempotency
        })
      });
      let data = null;
      try { data = await response.json(); } catch (_) { data = { success: false, message: 'پاسخ سرور قابل خواندن نیست.' }; }
      if (response.ok && data && data.success) {
        notify(data.message || 'پیش‌بینی با موفقیت ثبت شد.', true);
        form.querySelectorAll('input,button').forEach(el => { el.disabled = true; });
        setTimeout(() => { location.href = root.dataset.base + '?section=my-bets'; }, 900);
      } else {
        notify((data && data.message) || 'ثبت پیش‌بینی انجام نشد.', false);
        setLoading(btn, false);
      }
    } catch (error) {
      notify(error && error.name === 'AbortError' ? 'زمان ارتباط با سرور تمام شد. دوباره تلاش کنید.' : 'خطا در ارتباط با سرور.', false);
      setLoading(btn, false);
    } finally {
      clearTimeout(timer);
    }
  }

  document.addEventListener('click', function (event) {
    const tab = event.target.closest('[data-pred-tab]');
    if (tab) {
      switchTab(tab.dataset.predTab, false);
      return;
    }
    const choice = event.target.closest('.pred-choice');
    if (choice) {
      const form = choice.closest('.pred-bet-form');
      if (!form) return;
      form.querySelectorAll('.pred-choice').forEach(item => item.classList.remove('active'));
      choice.classList.add('active');
      const radio = choice.querySelector('input[type="radio"]');
      if (radio) radio.checked = true;
      updatePreview(form);
    }
  });

  document.addEventListener('input', function (event) {
    if (event.target && event.target.classList.contains('pred-amount')) {
      updatePreview(event.target.closest('.pred-bet-form'));
    }
  });

  document.addEventListener('submit', function (event) {
    const form = event.target.closest('.pred-bet-form');
    if (!form) return;
    event.preventDefault();
    postPrediction(form);
  });

  switchTab(root.dataset.initialSection || 'open');
  const focusGame = root.dataset.focusGame || '0';
  if (focusGame !== '0') {
    const card = document.querySelector('[data-game-card="' + focusGame + '"]');
    if (card) setTimeout(() => card.scrollIntoView({ behavior: 'smooth', block: 'center' }), 150);
  }
})();
