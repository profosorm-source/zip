(function () {
  'use strict';

  const root = document.querySelector('.task-market-wrap');
  if (!root) return;

  const FAVORITES_KEY = 'chortke_task_market_favorites';
  const HIDDEN_KEY = 'chortke_task_market_hidden';

  function readSet(key) {
    try { return new Set(JSON.parse(localStorage.getItem(key) || '[]').map(String)); }
    catch (_) { return new Set(); }
  }
  function writeSet(key, set) { localStorage.setItem(key, JSON.stringify(Array.from(set))); }

  const favorites = readSet(FAVORITES_KEY);
  const hidden = readSet(HIDDEN_KEY);

  const panel = document.getElementById('taskDetailPanel');
  const detailLogo = document.getElementById('detailLogo');
  const detailTitle = document.getElementById('detailTitle');
  const detailDesc = document.getElementById('detailDesc');
  const detailType = document.getElementById('detailType');
  const detailReward = document.getElementById('detailReward');
  const detailPlatform = document.getElementById('detailPlatform');
  const detailStart = document.getElementById('detailStart');
  const detailFavorite = document.getElementById('detailFavorite');
  const detailStep1 = document.getElementById('detailStep1');
  const detailStep2 = document.getElementById('detailStep2');
  const detailStep3 = document.getElementById('detailStep3');
  const detailStep4 = document.getElementById('detailStep4');
  const detailFlowNote = document.getElementById('detailFlowNote');
  let selectedId = null;
  let selectedCard = null;
  let busy = false;
  let notifier = null;

  function taskCards() { return Array.from(document.querySelectorAll('[data-task-card]')); }

  function notify(type, message) {
    if (!notifier && typeof window.Notyf !== 'undefined') {
      notifier = new window.Notyf({ duration: 4500, position: { x: 'left', y: 'top' }, dismissible: true });
    }
    if (notifier && typeof notifier[type] === 'function') return notifier[type](message);
    if (type === 'error') return alert(message);
    console.log(message);
  }

  function csrfToken() {
    return window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  }

  function applyCardState(card) {
    const id = String(card.dataset.taskId || '0');
    const favBtn = card.querySelector('[data-action="toggle-favorite"]');
    if (favorites.has(id)) favBtn?.classList.add('active');
    else favBtn?.classList.remove('active');
    card.classList.toggle('hidden', hidden.has(id));
  }

  function refreshAllCards() {
    taskCards().forEach(applyCardState);
  }

  function setSelected(card) {
    if (!card) return;
    taskCards().forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    selectedCard = card;
    selectedId = String(card.dataset.taskId || '0');

    if (detailLogo) {
      detailLogo.className = 'tm-brand-logo ' + (card.dataset.platformKey || 'web');
      detailLogo.innerHTML = '<svg><use href="#' + (card.dataset.platformSymbol || 'tm-logo-web') + '"></use></svg>';
    }
    if (detailTitle) detailTitle.textContent = card.dataset.title || 'جزئیات تسک';
    if (detailDesc) detailDesc.textContent = card.dataset.description || '';
    if (detailType) {
      detailType.textContent = card.dataset.flowTitle || card.dataset.typeLabel || 'نوع تسک';
      detailType.className = 'tm-badge ' + (card.dataset.flowBadgeClass || 'tm-badge-green');
    }
    if (detailReward) detailReward.textContent = card.dataset.reward || 'پاداش';
    if (detailPlatform) detailPlatform.textContent = (card.dataset.platformLabel || 'پلتفرم') + (card.dataset.flowBadge ? ' · ' + card.dataset.flowBadge : '');
    if (detailStep1) detailStep1.textContent = card.dataset['step-1'] || card.dataset.step1 || 'دستورالعمل تسک را مطالعه کنید.';
    if (detailStep2) detailStep2.textContent = card.dataset['step-2'] || card.dataset.step2 || 'تسک را از مسیر تعیین‌شده انجام دهید.';
    if (detailStep3) detailStep3.textContent = card.dataset['step-3'] || card.dataset.step3 || 'سیگنال/مدرک موردنیاز ثبت می‌شود.';
    if (detailStep4) detailStep4.textContent = card.dataset['step-4'] || card.dataset.step4 || 'سیستم یا کارفرما نتیجه را تأیید می‌کند.';
    if (detailFlowNote) detailFlowNote.innerHTML = '<strong>منطق اجرا:</strong> ' + (card.dataset.flowNote || 'رفتار غیرطبیعی یا مدرک تکراری باعث رد شدن اجرا می‌شود.');
    if (detailStart) {
      detailStart.disabled = card.dataset.startMode === 'disabled';
      detailStart.dataset.directUrl = card.dataset.directUrl || '#';
      detailStart.dataset.startUrl = card.dataset.startUrl || '';
      detailStart.dataset.executeTemplate = card.dataset.executeTemplate || '';
      const label = card.dataset.startLabel || 'شروع';
      detailStart.innerHTML = '<svg class="tm-svg"><use href="#tm-i-rocket"></use></svg> ' + label;
    }
    if (detailFavorite) detailFavorite.classList.toggle('earn-btn-primary', favorites.has(selectedId));
  }

  function redirectFromResponse(card, data) {
    if (data && typeof data.redirect_url === 'string' && data.redirect_url !== '') return data.redirect_url;
    const executionId = data?.execution_id || data?.submission_id || data?.execution?.id || data?.submission?.id || data?.id;
    const template = card.dataset.executeTemplate || '';
    if (executionId && template) return template.replace('__EXECUTION_ID__', encodeURIComponent(String(executionId)));
    return card.dataset.directUrl || '#';
  }

  async function startTask(card, trigger) {
    if (!card || busy) return;
    setSelected(card);

    const mode = card.dataset.startMode || 'direct';
    const id = String(card.dataset.taskId || '0');
    if (mode === 'direct') {
      const url = card.dataset.directUrl || '#';
      if (url && url !== '#') window.location.href = url;
      return;
    }
    if (mode !== 'start') {
      notify('error', 'مسیر شروع برای این تسک تعریف نشده است.');
      return;
    }

    const startUrl = card.dataset.startUrl || '';
    if (!startUrl) {
      notify('error', 'مسیر شروع تسک در دسترس نیست.');
      return;
    }

    const originalHtml = trigger ? trigger.innerHTML : '';
    busy = true;
    if (trigger) {
      trigger.disabled = true;
      trigger.innerHTML = '<span class="tm-loading-dot"></span> شروع...';
    }
    if (detailStart) detailStart.disabled = true;

    try {
      const response = await fetch(startUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken()
        },
        body: JSON.stringify({ ad_id: id, task_id: id, _csrf_token: csrfToken() })
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.success) {
        notify('error', data.message || 'شروع تسک انجام نشد.');
        return;
      }
      notify('success', data.message || 'تسک شروع شد.');
      const redirectUrl = redirectFromResponse(card, data);
      if (redirectUrl && redirectUrl !== '#') {
        window.location.href = redirectUrl;
      }
    } catch (_) {
      notify('error', 'خطا در ارتباط با سرور.');
    } finally {
      busy = false;
      if (trigger) {
        trigger.disabled = false;
        trigger.innerHTML = originalHtml;
      }
      if (detailStart) detailStart.disabled = false;
    }
  }

  document.addEventListener('click', function (event) {
    const actionEl = event.target.closest('[data-action]');
    const card = event.target.closest('[data-task-card]');

    if (event.target.closest('#detailStart')) {
      event.preventDefault();
      startTask(selectedCard, detailStart);
      return;
    }

    if (actionEl && card) {
      const id = String(card.dataset.taskId || '0');
      const action = actionEl.dataset.action;
      if (action === 'toggle-favorite') {
        event.preventDefault();
        event.stopPropagation();
        if (favorites.has(id)) favorites.delete(id); else favorites.add(id);
        writeSet(FAVORITES_KEY, favorites);
        applyCardState(card);
        if (selectedId === id && detailFavorite) detailFavorite.classList.toggle('earn-btn-primary', favorites.has(id));
        return;
      }
      if (action === 'hide-task') {
        event.preventDefault();
        event.stopPropagation();
        hidden.add(id);
        writeSet(HIDDEN_KEY, hidden);
        applyCardState(card);
        return;
      }
      if (action === 'show-details') {
        event.preventDefault();
        setSelected(card);
        if (window.innerWidth < 1200 && panel) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
      }
      if (action === 'start-task') {
        event.preventDefault();
        event.stopPropagation();
        startTask(card, actionEl);
        return;
      }
    }

    if (card && !event.target.closest('a,button')) {
      setSelected(card);
    }

    if (event.target.closest('#detailFavorite') && selectedId) {
      event.preventDefault();
      if (favorites.has(selectedId)) favorites.delete(selectedId); else favorites.add(selectedId);
      writeSet(FAVORITES_KEY, favorites);
      refreshAllCards();
      if (detailFavorite) detailFavorite.classList.toggle('earn-btn-primary', favorites.has(selectedId));
    }
  });

  document.querySelector('[data-action="change-sort"]')?.addEventListener('change', function () {
    const url = new URL(window.location.href);
    url.searchParams.set('sort', this.value);
    url.searchParams.delete('page');
    window.location.href = url.toString();
  });

  refreshAllCards();
  setSelected(taskCards()[0]);
})();
