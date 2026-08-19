/* admin/kyc.js — منطق مشترک ماژول KYC (index لیست + review جزئیات)
 * data-* روی #kycRoot:
 *   data-verify-base => /admin/kyc/verify
 *   data-reject-base => /admin/kyc/reject
 *   data-list-url    => /admin/kyc
 *   data-v-id        => شناسهٔ درخواست (صفحهٔ review)
 * index از data-url روی دکمه‌های .js-kyc-approve / .js-kyc-reject استفاده می‌کند.
 */
(function () {
    'use strict';
    function root() { return document.getElementById('kycRoot') || document.body; }
    function d(k, fb) { var v = root().dataset[k]; return v !== undefined ? v : (fb || ''); }
    function csrf() { return window.csrfToken || ''; }

    async function postJson(url, payload) {
        payload = payload || {};
        payload._token = csrf();
        var res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify(payload)
        });
        try { return await res.json(); } catch (e) { throw new Error('پاسخ نامعتبر'); }
    }

    /* ── index: دکمه‌های لیست (URL از data-url خود دکمه) ── */
    document.addEventListener('click', async function (e) {
        var approveBtn = e.target.closest('.js-kyc-approve');
        if (approveBtn) {
            e.preventDefault();
            var c = await Swal.fire({ title: 'تأیید KYC', text: 'مدارک تأیید شده و وضعیت KYC به «تأیید شده» تغییر می‌کند.', icon: 'question', showCancelButton: true, confirmButtonText: '✅ تأیید', cancelButtonText: 'انصراف', confirmButtonColor: '#10b981' });
            if (!c.isConfirmed) return;
            try { var d1 = await postJson(approveBtn.dataset.url); if (d1.success) { notyf.success(d1.message || 'تأیید شد'); setTimeout(function () { location.reload(); }, 900); } else notyf.error(d1.message || 'خطا'); } catch (err) { notyf.error(err.message); }
            return;
        }
        var rejectBtn = e.target.closest('.js-kyc-reject');
        if (rejectBtn) {
            e.preventDefault();
            var r = await Swal.fire({ title: 'رد KYC', input: 'textarea', inputLabel: 'دلیل رد:', inputPlaceholder: 'مثلاً: تصویر واضح نیست...', icon: 'warning', showCancelButton: true, confirmButtonText: '⛔ رد', cancelButtonText: 'انصراف', confirmButtonColor: '#ef4444', inputValidator: function (val) { return !val ? 'دلیل رد الزامی است' : null; } });
            if (!r.isConfirmed) return;
            try { var d2 = await postJson(rejectBtn.dataset.url, { reason: r.value }); if (d2.success) { notyf.success(d2.message || 'رد شد'); setTimeout(function () { location.reload(); }, 900); } else notyf.error(d2.message || 'خطا'); } catch (err) { notyf.error(err.message); }
            return;
        }
    });

    /* ── review: توابع تأیید/رد (data-v-id) ── */
    window.doApprove = function () {
        var vId = d('vId');
        Swal.fire({ title: 'تأیید احراز هویت', text: 'مدارک این کاربر تأیید و وضعیت KYC به «تأیید شده» تغییر می‌کند.', icon: 'question', showCancelButton: true, confirmButtonText: '✅ تأیید', cancelButtonText: 'انصراف', confirmButtonColor: '#10b981' })
            .then(function (r) {
                if (!r.isConfirmed) return;
                fetch(d('verifyBase') + '/' + vId, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() }, body: JSON.stringify({ _csrf_token: csrf() }) })
                    .then(function (rr) { return rr.json(); }).then(function (dd) { if (dd.success) { notyf.success(dd.message || 'تأیید شد'); setTimeout(function () { location.href = d('listUrl'); }, 900); } else notyf.error(dd.message || 'خطا'); });
            });
    };
    window.showRejectBox = function () { var b = document.getElementById('rejectBox'); if (b) b.style.display = 'block'; };
    window.confirmReject = function () {
        var vId = d('vId');
        var reason = document.getElementById('rejectReason').value.trim();
        if (!reason) { notyf.error('دلیل رد الزامی است'); return; }
        Swal.fire({ title: 'رد احراز هویت', text: 'این عملیات برگشت‌ناپذیر است.', icon: 'warning', showCancelButton: true, confirmButtonText: '⛔ رد', cancelButtonText: 'انصراف', confirmButtonColor: '#ef4444' })
            .then(function (r) {
                if (!r.isConfirmed) return;
                fetch(d('rejectBase') + '/' + vId, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() }, body: JSON.stringify({ reason: reason, _csrf_token: csrf() }) })
                    .then(function (rr) { return rr.json(); }).then(function (dd) { if (dd.success) { notyf.success(dd.message || 'رد شد'); setTimeout(function () { location.href = d('listUrl'); }, 900); } else notyf.error(dd.message || 'خطا'); });
            });
    };
})();
