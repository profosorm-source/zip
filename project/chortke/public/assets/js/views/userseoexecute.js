(function () {
  'use strict';

  const root = document.getElementById('seoExecuteRoot');
  if (!root) return;

  const executionId = parseInt(root.dataset.executionId || '0', 10);
  const minDuration = parseInt(root.dataset.minDuration || '60', 10);
  const completeUrl = root.dataset.completeUrl || ('/seo/' + executionId + '/complete');
  const cancelUrl = root.dataset.cancelUrl || ('/seo/' + executionId + '/cancel');
  const returnUrl = root.dataset.returnUrl || '/seo';
  const csrf = root.dataset.csrf || window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const targetUrl = root.dataset.targetUrl || '';

  let tracker = null;
  let fallbackDuration = 0;
  let openedTarget = false;
  let active = true;
  let focusBlurCount = 0;
  let notifier = null;

  function notify(type, message) {
    if (!notifier && typeof window.Notyf !== 'undefined') {
      notifier = new window.Notyf({ duration: 4500, position: { x: 'left', y: 'top' }, dismissible: true });
    }
    if (notifier && typeof notifier[type] === 'function') return notifier[type](message);
    if (type === 'error') return alert(message);
    console.log(message);
  }

  function updateUI(data) {
    const duration = Number(data.duration || 0);
    const scrollDepth = Number(data.scrollDepth ?? data.scroll_depth ?? 0);
    const interactions = Number(data.interactions || 0);
    document.getElementById('durationText').textContent = duration + 's';
    document.getElementById('scrollText').textContent = Math.round(scrollDepth) + '%';
    document.getElementById('interactionText').textContent = interactions;
    const mins = Math.floor(duration / 60);
    const secs = duration % 60;
    document.getElementById('timerDisplay').textContent = `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    const btn = document.getElementById('btnComplete');
    if (btn && duration >= minDuration) btn.disabled = false;
  }

  function fallbackData() {
    return {
      duration: fallbackDuration,
      scroll_depth: openedTarget ? 60 : 10,
      interactions: openedTarget ? 3 : 0,
      scroll_speed: 0,
      mouse_pattern: 'normal',
      pause_count: Math.max(1, Math.floor(fallbackDuration / 20)),
      interaction_types: openedTarget ? ['external_open', 'return_to_task'] : [],
      target_opened: openedTarget ? 1 : 0,
      focus_blur_count: focusBlurCount,
      active_time: fallbackDuration,
      client_mode: 'web',
      behavior: {
        scroll_speed: 0,
        mouse_pattern: 'normal',
        pause_count: Math.max(1, Math.floor(fallbackDuration / 20)),
        interaction_types: openedTarget ? ['external_open', 'return_to_task'] : [],
        target_opened: openedTarget ? 1 : 0,
        focus_blur_count: focusBlurCount,
        active_time: fallbackDuration,
        client_mode: 'web'
      }
    };
  }

  function engagementData() {
    const fallback = fallbackData();
    if (tracker && typeof tracker.getData === 'function') {
      const tracked = tracker.getData();
      const trackedScroll = Number(tracked.scroll_depth ?? tracked.scrollDepth ?? 0);
      const trackedInteractions = Number(tracked.interactions || 0);
      const duration = Math.max(Number(tracked.duration || 0), fallback.duration);

      // در سایت‌های Cross-Origin معمولاً iframe قابل ردیابی نیست. اگر کاربر سایت هدف را
      // با دکمه رسمی باز کرده باشد، سیگنال‌های fallback را با زمان tracker ادغام می‌کنیم
      // تا تسک بی‌دلیل به خاطر محدودیت مرورگر رد نشود.
      if (openedTarget && trackedScroll === 0 && trackedInteractions === 0) {
        return Object.assign({}, fallback, { duration });
      }

      return Object.assign({}, tracked, {
        duration,
        scroll_depth: Math.max(trackedScroll, fallback.scroll_depth || 0),
        interactions: Math.max(trackedInteractions, fallback.interactions || 0),
        target_opened: openedTarget ? 1 : Number(tracked.target_opened || 0),
        focus_blur_count: focusBlurCount,
        active_time: duration,
        client_mode: 'web',
        pause_count: Math.max(Number(tracked.pause_count || 0), fallback.pause_count || 0),
        interaction_types: Array.from(new Set([...(tracked.interaction_types || []), ...(fallback.interaction_types || [])])),
        behavior: Object.assign({}, tracked.behavior || {}, {
          pause_count: Math.max(Number(tracked.pause_count || 0), fallback.pause_count || 0),
          interaction_types: Array.from(new Set([...(tracked.interaction_types || []), ...(fallback.interaction_types || [])])),
          target_opened: openedTarget ? 1 : Number(tracked.target_opened || 0),
          focus_blur_count: focusBlurCount,
          active_time: duration,
          client_mode: 'web'
        })
      });
    }
    return fallback;
  }

  document.addEventListener('visibilitychange', () => { if (document.hidden) focusBlurCount += 1; active = !document.hidden; });
  window.addEventListener('blur', () => { focusBlurCount += 1; });
  setInterval(() => {
    if (active) fallbackDuration += 1;
    if (!tracker || openedTarget) updateUI(engagementData());
  }, 1000);

  document.getElementById('openTargetBtn')?.addEventListener('click', function () {
    openedTarget = true;
    if (targetUrl) window.open(targetUrl, '_blank', 'noopener,noreferrer');
    notify('success', 'سایت هدف باز شد. بعد از انجام تعامل، به این صفحه برگردید و تکمیل را بزنید.');
  });

  document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.SeoTracker === 'function') {
      try {
        tracker = new window.SeoTracker({
          frameId: 'taskFrame',
          minDuration: minDuration,
          onUpdate: updateUI,
          onReady: () => {
            const btn = document.getElementById('btnComplete');
            if (btn) btn.disabled = false;
          }
        });
        tracker.start();
      } catch (_) {
        tracker = null;
      }
    }
  });

  document.getElementById('btnComplete')?.addEventListener('click', async function () {
    const data = engagementData();
    if (Number(data.duration || 0) < minDuration) {
      notify('error', `حداقل ${minDuration} ثانیه زمان برای این تسک نیاز است.`);
      return;
    }
    if (!openedTarget && !window.confirm('هنوز دکمه باز کردن سایت هدف را نزده‌اید. مطمئنید تعامل را انجام داده‌اید؟')) return;

    const btn = this;
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> ارسال...';

    try {
      const response = await fetch(completeUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(Object.assign({}, data, { _csrf_token: csrf }))
      });
      const result = await response.json().catch(() => ({}));
      if (response.ok && result.success) {
        const html = result.payout ? `پاداش: ${Number(result.payout).toLocaleString()} تومان` : (result.message || 'تسک شما دریافت شد.');
        if (typeof Swal !== 'undefined') {
          await Swal.fire({ icon: 'success', title: 'تکمیل شد', html });
        } else {
          notify('success', result.message || 'تسک تکمیل شد.');
        }
        window.location.href = returnUrl;
      } else {
        notify('error', result.message || 'تکمیل تسک انجام نشد.');
        btn.disabled = false;
        btn.innerHTML = original;
      }
    } catch (_) {
      notify('error', 'خطا در ارتباط با سرور.');
      btn.disabled = false;
      btn.innerHTML = original;
    }
  });

  document.getElementById('btnCancel')?.addEventListener('click', async function () {
    const confirmed = typeof Swal !== 'undefined'
      ? await Swal.fire({ title: 'لغو تسک؟', text: 'اگر لغو کنید این اجرای SEO بسته می‌شود.', icon: 'warning', showCancelButton: true, confirmButtonText: 'بله، لغو شود', cancelButtonText: 'ادامه می‌دهم' }).then(r => r.isConfirmed)
      : window.confirm('لغو تسک؟');
    if (!confirmed) return;

    try {
      await fetch(cancelUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: new FormData() });
    } catch (_) {}
    window.location.href = returnUrl;
  });
})();
