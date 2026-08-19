(function () {
  'use strict';
  const root = document.getElementById('supportNotificationsRoot');
  if (!root) return;
  const list = document.getElementById('notificationsList');
  const badge = document.getElementById('unreadCountBadge');
  const csrf = root.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  let notifier = null;
  function notify(type, message) {
    if (!notifier && typeof window.Notyf !== 'undefined') notifier = new window.Notyf({ duration: 4500, position:{x:'left', y:'top'}, dismissible:true });
    if (notifier && typeof notifier[type] === 'function') return notifier[type](message);
    console[type === 'error' ? 'error' : 'log'](message);
  }
  function updateBadge(count) { if (badge) badge.textContent = String(count); }
  async function post(url, body) {
    return fetch(url, { method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':csrf }, body:JSON.stringify(body) });
  }
  function removeItem(id) {
    const item = list?.querySelector('.sup-notif-item[data-id="' + id + '"]');
    if (!item) return;
    item.style.opacity = '0'; item.style.transform = 'translateY(-6px)'; item.style.transition = '.2s';
    setTimeout(() => item.remove(), 220);
  }
  document.addEventListener('click', async function (event) {
    const filter = event.target.closest('.filter-btn');
    if (filter) {
      document.querySelectorAll('.filter-btn').forEach(b => { b.classList.remove('sup-btn-primary'); b.classList.add('sup-btn-secondary'); });
      filter.classList.add('sup-btn-primary'); filter.classList.remove('sup-btn-secondary');
      const mode = filter.dataset.filter;
      document.querySelectorAll('.sup-notif-item').forEach(item => { item.style.display = (mode === 'unread' && item.classList.contains('read')) ? 'none' : ''; });
      return;
    }
    const btn = event.target.closest('button[data-id]');
    if (!btn) return;
    const id = btn.dataset.id;
    try {
      if (btn.classList.contains('mark-read-btn')) {
        const res = await post(root.dataset.markReadUrl, { notification_id:id });
        const data = await res.json().catch(() => ({}));
        if (data.success) {
          const item = list?.querySelector('.sup-notif-item[data-id="' + id + '"]');
          item?.classList.remove('unread'); item?.classList.add('read'); btn.remove(); updateBadge(data.unread_count || 0);
        }
      } else if (btn.classList.contains('archive-btn')) {
        const res = await post(root.dataset.archiveUrl, { notification_id:id });
        const data = await res.json().catch(() => ({}));
        if (data.success) { removeItem(id); notify('success', data.message || 'آرشیو شد'); }
      } else if (btn.classList.contains('delete-btn')) {
        const res = await post(root.dataset.deleteUrl, { notification_id:id });
        const data = await res.json().catch(() => ({}));
        if (data.success) { removeItem(id); notify('success', data.message || 'حذف شد'); }
      }
    } catch (_) { notify('error', 'خطا در ارتباط با سرور'); }
  });
  document.getElementById('markAllReadBtn')?.addEventListener('click', async function () {
    let confirmed = true;
    if (window.Swal) {
      const result = await window.Swal.fire({ title:'خواندن همه اعلان‌ها', text:'همه اعلان‌ها خوانده‌شده شوند؟', icon:'question', showCancelButton:true, confirmButtonText:'بله', cancelButtonText:'انصراف', confirmButtonColor:'#F0B90B' });
      confirmed = result.isConfirmed;
    }
    if (!confirmed) return;
    try {
      const res = await post(root.dataset.markAllUrl, {});
      const data = await res.json().catch(() => ({}));
      if (data.success) {
        document.querySelectorAll('.sup-notif-item.unread').forEach(item => { item.classList.remove('unread'); item.classList.add('read'); item.querySelector('.mark-read-btn')?.remove(); });
        updateBadge(0); notify('success', data.message || 'همه اعلان‌ها خوانده شد');
      }
    } catch (_) { notify('error', 'خطا در ارتباط با سرور'); }
  });
})();
