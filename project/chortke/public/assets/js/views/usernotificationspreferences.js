(function () {
  'use strict';
  const root = document.getElementById('supportNotificationPrefsRoot');
  if (!root) return;
  const csrf = root.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  let notifier = null;

  function notify(type, message) {
    if (!notifier && typeof window.Notyf !== 'undefined') {
      notifier = new window.Notyf({ duration: 4500, position:{x:'left',y:'top'}, dismissible:true });
    }
    if (notifier && typeof notifier[type] === 'function') return notifier[type](message);
    console[type === 'error' ? 'error' : 'log'](message);
  }

  function applyChannelState(channel) {
    const master = document.querySelector('.notif-master-toggle[data-channel="' + channel + '"]');
    const enabled = !!master?.checked;
    document.querySelectorAll('.notif-type-toggle[data-channel="' + channel + '"]').forEach(input => {
      input.disabled = !enabled;
      const badge = input.closest('.sup-badge');
      if (badge) {
        badge.style.opacity = enabled ? '1' : '.45';
      }
    });
  }

  function updateTypeBadge(input) {
    const badge = input.closest('.sup-badge');
    if (!badge) return;
    badge.classList.toggle('sup-badge--success', input.checked);
    badge.classList.toggle('sup-badge--muted', !input.checked);
    const text = Array.from(badge.childNodes).find(n => n.nodeType === Node.TEXT_NODE);
    if (text) text.nodeValue = input.checked ? ' فعال' : ' خاموش';
  }

  document.querySelectorAll('.notif-master-toggle').forEach(master => {
    master.addEventListener('change', () => applyChannelState(master.dataset.channel));
    applyChannelState(master.dataset.channel);
  });

  document.querySelectorAll('.notif-type-toggle').forEach(input => {
    input.addEventListener('change', () => updateTypeBadge(input));
    updateTypeBadge(input);
  });

  document.getElementById('prefsForm')?.addEventListener('submit', async function (event) {
    event.preventDefault();
    const payload = {};

    this.querySelectorAll('input[type="checkbox"]').forEach(cb => {
      if (cb.name) payload[cb.name] = cb.checked ? 1 : 0;
    });

    const dndStart = document.getElementById('dnd_start')?.value;
    const dndEnd = document.getElementById('dnd_end')?.value;
    if (dndStart) payload.dnd_start = dndStart + ':00';
    if (dndEnd) payload.dnd_end = dndEnd + ':00';

    try {
      const response = await fetch(root.dataset.updateUrl, {
        method:'POST',
        credentials:'same-origin',
        headers:{ 'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':csrf },
        body:JSON.stringify(payload)
      });
      const data = await response.json().catch(() => ({}));
      if (response.ok && data.success) notify('success', data.message || 'تنظیمات ذخیره شد');
      else notify('error', data.message || 'ذخیره تنظیمات انجام نشد');
    } catch (_) {
      notify('error', 'خطا در ارتباط با سرور');
    }
  });
})();
