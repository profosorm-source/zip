(function () {
  'use strict';
  const root = document.getElementById('adsIndexRoot');
  if (!root) return;

  const csrf = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  let currentFilter = 'all';
  const searchInput = document.getElementById('adsSearchInput');
  const noResults = document.getElementById('adsNoResults');


  function switchSection(section, updateUrl) {
    section = section === 'create' ? 'create' : 'manage';
    root.dataset.currentSection = section;
    document.querySelectorAll('[data-ads-panel]').forEach(panel => {
      panel.classList.toggle('active', panel.dataset.adsPanel === section);
    });
    document.querySelectorAll('[data-ads-section]').forEach(btn => {
      btn.classList.toggle('active', (btn.dataset.adsSection || 'manage') === section);
    });
    if (updateUrl && window.history && window.history.replaceState) {
      const url = new URL(window.location.href);
      if (section === 'create') url.searchParams.set('section', 'create');
      else url.searchParams.delete('section');
      window.history.replaceState({}, '', url.toString());
    }
  }

  document.addEventListener('click', function (event) {
    const btn = event.target.closest('[data-ads-section]');
    if (!btn) return;
    event.preventDefault();
    switchSection(btn.dataset.adsSection || 'manage', true);
  });

  function notify(type, message) {
    if (typeof Notyf !== 'undefined') {
      const n = new Notyf({ duration: 3500, position: { x: 'left', y: 'top' }, dismissible: true });
      return n[type === 'success' ? 'success' : 'error'](message);
    }
    alert(message);
  }

  async function postForm(url, data) {
    const fd = new FormData();
    Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v));
    const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: fd });
    return res.json().catch(() => ({}));
  }

  function applyFilters() {
    const q = (searchInput?.value || '').trim().toLowerCase();
    let shown = 0;
    document.querySelectorAll('[data-ad-card]').forEach(card => {
      const status = card.dataset.statusGroup || '';
      const title = (card.dataset.title || '').toLowerCase();
      const filterOk = currentFilter === 'all' || status === currentFilter;
      const searchOk = !q || title.includes(q);
      const visible = filterOk && searchOk;
      card.classList.toggle('d-none', !visible);
      if (visible) shown += 1;
    });
    if (noResults) noResults.classList.toggle('d-none', shown !== 0);
  }

  document.querySelectorAll('[data-filter-type]').forEach(btn => {
    btn.addEventListener('click', function () {
      currentFilter = this.dataset.filterType || 'all';
      document.querySelectorAll('[data-filter-type]').forEach(b => b.classList.toggle('active', b === this));
      applyFilters();
    });
  });
  searchInput?.addEventListener('input', applyFilters);

  document.addEventListener('change', async function (event) {
    const input = event.target.closest('[data-action="toggle-ad-status"]');
    if (!input) return;
    const id = input.dataset.id;
    input.disabled = true;
    try {
      const data = await postForm(root.dataset.toggleUrl || '/ads/toggle-status', { ad_id: id });
      if (data.success) notify('success', data.message || 'وضعیت کمپین تغییر کرد.');
      else { notify('error', data.message || 'تغییر وضعیت انجام نشد.'); input.checked = !input.checked; }
    } catch (_) {
      notify('error', 'خطا در ارتباط با سرور.');
      input.checked = !input.checked;
    } finally { input.disabled = false; }
  });

  document.addEventListener('click', async function (event) {
    const btn = event.target.closest('[data-action="cancel-ad"]');
    if (!btn) return;
    const id = btn.dataset.id;
    const ok = typeof Swal !== 'undefined'
      ? await Swal.fire({ title: 'لغو کمپین؟', text: 'بودجه باقی‌مانده آزاد می‌شود. اگر اجرای در انتظار وجود داشته باشد لغو انجام نمی‌شود.', icon: 'warning', showCancelButton: true, confirmButtonText: 'لغو کمپین', cancelButtonText: 'انصراف' }).then(r => r.isConfirmed)
      : confirm('لغو کمپین؟');
    if (!ok) return;
    btn.disabled = true;
    try {
      const url = (root.dataset.cancelTemplate || '/ads/__ID__/cancel').replace('__ID__', encodeURIComponent(id));
      const data = await postForm(url, { reason: 'لغو توسط تبلیغ‌دهنده' });
      if (data.success) { notify('success', data.message || 'کمپین لغو شد.'); setTimeout(() => location.reload(), 800); }
      else notify('error', data.message || 'لغو کمپین انجام نشد.');
    } catch (_) { notify('error', 'خطا در ارتباط با سرور.'); }
    finally { btn.disabled = false; }
  });

  switchSection(root.dataset.initialSection || 'manage', false);
  applyFilters();
})();
