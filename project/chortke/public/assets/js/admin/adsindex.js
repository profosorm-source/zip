/* admin/adsindex.js — CSP-safe unified admin ads actions */
(function () {
  'use strict';

  const dataEl = document.getElementById('ads-index-data');
  if (!dataEl) return;
  let cfg = {};
  try { cfg = JSON.parse(dataEl.textContent || '{}'); } catch (_) { cfg = {}; }

  const checkAll = document.getElementById('checkAll');
  if (checkAll) {
    checkAll.addEventListener('change', function () {
      document.querySelectorAll('.ad-check').forEach(cb => { cb.checked = checkAll.checked; });
    });
  }

  function toast(type, message) {
    if (window.Swal) {
      return Swal.fire({ icon: type === 'success' ? 'success' : 'error', title: message || '', timer: type === 'success' ? 1400 : undefined, showConfirmButton: type !== 'success' });
    }
    alert(message || (type === 'success' ? 'انجام شد' : 'خطا'));
    return Promise.resolve();
  }

  async function askReason(action, count) {
    const needsReason = ['reject', 'cancel', 'delete'].includes(action);
    if (!window.Swal) {
      if (!confirm((count || 1) + ' مورد انتخاب شده. ادامه می‌دهید؟')) return null;
      return needsReason ? (prompt('دلیل عملیات را وارد کنید:') || '') : '';
    }
    const labels = { approve: 'تأیید', reject: 'رد', pause: 'توقف', resume: 'ازسرگیری', cancel: 'لغو', delete: 'حذف نرم' };
    const result = await Swal.fire({
      title: (labels[action] || action) + ' تبلیغ',
      text: (count || 1) + ' مورد انتخاب شده',
      input: needsReason ? 'text' : undefined,
      inputLabel: needsReason ? 'دلیل/یادداشت مدیریتی' : undefined,
      inputPlaceholder: needsReason ? 'مثلاً: عدم تطابق محتوا با قوانین' : undefined,
      icon: ['reject', 'cancel', 'delete'].includes(action) ? 'warning' : 'question',
      showCancelButton: true,
      confirmButtonText: 'اجرا',
      cancelButtonText: 'انصراف',
      inputValidator: value => needsReason && (!value || value.trim().length < 3) ? 'دلیل باید حداقل ۳ کاراکتر باشد.' : undefined
    });
    if (!result.isConfirmed) return null;
    return needsReason ? (result.value || '').trim() : '';
  }

  async function postJson(url, payload) {
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': cfg.csrf || ''
      },
      body: JSON.stringify(Object.assign({ csrf_token: cfg.csrf || '' }, payload || {}))
    });
    return res.json().catch(() => ({ success: false, message: 'پاسخ سرور نامعتبر است.' }));
  }

  const bulkBtn = document.getElementById('btnBulkApply');
  if (bulkBtn) {
    bulkBtn.addEventListener('click', async function () {
      const action = document.getElementById('bulkAction')?.value || '';
      if (!action) { await toast('error', 'عملیات را انتخاب کنید.'); return; }
      const ids = Array.from(document.querySelectorAll('.ad-check:checked')).map(cb => cb.value);
      if (!ids.length) { await toast('error', 'حداقل یک مورد انتخاب کنید.'); return; }
      const reason = await askReason(action, ids.length);
      if (reason === null) return;
      bulkBtn.disabled = true;
      try {
        const data = await postJson(cfg.bulkUrl || '/admin/ads/bulk', { ids, action, reason });
        if (data.success || data.partial) {
          await toast('success', data.message || 'عملیات انجام شد.');
          window.location.reload();
        } else {
          await toast('error', data.message || 'عملیات انجام نشد.');
        }
      } catch (_) {
        await toast('error', 'خطا در ارتباط با سرور.');
      } finally {
        bulkBtn.disabled = false;
      }
    });
  }

  document.addEventListener('click', async function (event) {
    const btn = event.target.closest('[data-admin-ad-action]');
    if (!btn) return;
    const action = btn.dataset.adminAdAction;
    const id = btn.dataset.id;
    const reason = await askReason(action, 1);
    if (reason === null) return;
    btn.disabled = true;
    try {
      const url = (cfg.actionUrlTemplate || '/admin/ads/__ID__/action').replace('__ID__', encodeURIComponent(id));
      const data = await postJson(url, { action, reason });
      if (data.success) {
        await toast('success', data.message || 'عملیات انجام شد.');
        window.location.reload();
      } else {
        await toast('error', data.message || 'عملیات انجام نشد.');
      }
    } catch (_) {
      await toast('error', 'خطا در ارتباط با سرور.');
    } finally {
      btn.disabled = false;
    }
  });
})();
