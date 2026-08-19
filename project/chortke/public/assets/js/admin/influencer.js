/* admin/influencer.js — admin actions for independent influencer module */
(function () {
  'use strict';
  function root() { return document.getElementById('influencerRoot') || document.body; }
  function d(k, fb) { const v = root().dataset[k]; return v !== undefined ? v : (fb || ''); }
  function csrf() { return window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || ''; }
  function notify(msg, ok) {
    if (window.Notyf) {
      const n = new Notyf({ duration: 3500, position: { x: 'left', y: 'top' }, dismissible: true });
      if (ok && typeof n.success === 'function') n.success(msg); else if (!ok && typeof n.error === 'function') n.error(msg); else alert(msg);
    } else alert(msg);
  }
  async function postJSON(url, body) {
    const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(body || {}) });
    const text = await res.text();
    let data; try { data = text ? JSON.parse(text) : {}; } catch (_) { data = { success: false, message: 'پاسخ سرور قابل خواندن نیست.' }; }
    if (!res.ok && data.success !== false) data.success = false;
    return data;
  }
  function done(r) { if (r.success) { notify(r.message || 'عملیات انجام شد.', true); setTimeout(() => location.reload(), 700); } else notify(r.message || 'عملیات انجام نشد.', false); }

  window.doAction = function (id, decision) {
    let reason = '';
    if (decision === 'reject' || decision === 'suspend') {
      reason = prompt(decision === 'reject' ? 'دلیل رد پیج را وارد کنید:' : 'دلیل تعلیق را وارد کنید:') || '';
      if (reason.trim().length < 5) { notify('دلیل باید حداقل ۵ کاراکتر باشد.', false); return; }
    } else if (!confirm('آیا این پیج را تأیید می‌کنید؟')) return;
    postJSON(d('profilesApprove'), { profile_id: id, decision, reason }).then(done).catch(() => notify('خطا در ارتباط با سرور.', false));
  };

  window.handleVerification = function (id, decision) {
    let reason = '';
    if (decision === 'reject') {
      reason = prompt('لطفاً دلیل رد درخواست را وارد کنید:') || '';
      if (reason.trim().length < 5) { notify('دلیل باید حداقل ۵ کاراکتر باشد.', false); return; }
    } else if (!confirm('این مدرک تأیید شود؟')) return;
    postJSON(d('verificationsBase') + decision, { verification_id: id, reason }).then(done).catch(() => notify('خطا در ارتباط با سرور.', false));
  };

  window.submitVerdict = function () {
    const checked = document.querySelector('input[name="verdict"]:checked');
    const verdict = checked ? checked.value : '';
    const note = (document.getElementById('verdictNote')?.value || '').trim();
    let refundPercent = verdict === 'partial' ? parseFloat(document.getElementById('refundPercent')?.value || '0') : (verdict === 'favor_customer' ? 100 : 0);
    if (!verdict) { notify('رأی را انتخاب کنید.', false); return; }
    if (note.length < 10) { notify('توضیح رأی باید حداقل ۱۰ کاراکتر باشد.', false); return; }
    if (refundPercent < 0 || refundPercent > 100 || Number.isNaN(refundPercent)) { notify('درصد بازگشت نامعتبر است.', false); return; }
    if (!confirm('آیا از صدور این رأی اطمینان دارید؟ این عملیات مالی است.')) return;
    postJSON(d('resolveUrl'), { dispute_id: parseInt(d('disputeId') || '0', 10), verdict, note, refund_percent: refundPercent }).then(done).catch(() => notify('خطا در ارتباط با سرور.', false));
  };

  document.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'verdict') {
      const g = document.getElementById('partialGroup');
      if (g) g.style.display = e.target.value === 'partial' ? 'block' : 'none';
    }
  });
  document.addEventListener('DOMContentLoaded', function () { const box = document.getElementById('msgBox'); if (box) box.scrollTop = box.scrollHeight; });
})();
