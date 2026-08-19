/* admin/withdrawals.js — تأیید/رد برداشت (index لیست + review جزئیات)
 * data-* روی #withdrawalsRoot: data-base => /admin/withdrawals ، data-w-id (review)
 * index: .js-w-approve / .js-w-reject با data-id
 * review: توابع doApprove/doReject/confirmReject
 */
(function () {
    'use strict';
    function root() { return document.getElementById('withdrawalsRoot') || document.body; }
    function d(k, fb) { var v = root().dataset[k]; return v !== undefined ? v : (fb || ''); }
    function csrf() { return window.csrfToken || ''; }
    function post(url, body) {
        return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify(body || {}) }).then(function (r) { return r.json(); });
    }

    /* ── review: توابع تکی ── */
    window.doApprove = function () {
        var tracking = document.getElementById('trackingCode').value.trim();
        if (!tracking) { notyf.error('لطفاً شماره پیگیری را وارد کنید'); return; }
        Swal.fire({ title: 'تأیید برداشت', text: 'پرداخت با کد پیگیری «' + tracking + '» ثبت می‌شود.', icon: 'question', showCancelButton: true, confirmButtonText: '✅ تأیید', cancelButtonText: 'انصراف', confirmButtonColor: '#0ECB81' })
            .then(function (r) {
                if (!r.isConfirmed) return;
                post(d('base') + '/' + d('wId') + '/approve', { tracking_code: tracking })
                    .then(function (res) { if (res.success) location.href = d('base'); else notyf.error(res.message || 'خطا'); });
            });
    };
    window.doReject = function () { var b = document.getElementById('rejectBox'); if (b) b.style.display = 'block'; };
    window.confirmReject = function () {
        var reason = document.getElementById('rejectReason').value.trim();
        Swal.fire({ title: 'رد درخواست', text: 'این عملیات غیرقابل بازگشت است.', icon: 'warning', showCancelButton: true, confirmButtonText: '⛔ رد شود', cancelButtonText: 'انصراف', confirmButtonColor: '#F6465D' })
            .then(function (r) {
                if (!r.isConfirmed) return;
                post(d('base') + '/' + d('wId') + '/reject', { reason: reason })
                    .then(function (res) { if (res.success) location.href = d('base'); else notyf.error(res.message || 'خطا'); });
            });
    };

    /* ── index: دکمه‌های لیست ── */
    document.addEventListener('DOMContentLoaded', function () {
        var base = d('base');
        document.querySelectorAll('.js-w-approve[data-id]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = this.dataset.id;
                Swal.fire({ title: 'تأیید برداشت', html: '<input type="text" id="swal-track" class="swal2-input" placeholder="شماره پیگیری...">', icon: 'question', showCancelButton: true, confirmButtonText: '✅ تأیید', cancelButtonText: 'انصراف', confirmButtonColor: '#0ECB81', preConfirm: function () { var v = document.getElementById('swal-track').value.trim(); if (!v) Swal.showValidationMessage('شماره پیگیری الزامی است'); return v; } })
                    .then(function (r) { if (!r.isConfirmed) return; post(base + '/' + id + '/approve', { tracking_code: r.value }).then(function (dd) { if (dd.success) { notyf.success(dd.message); setTimeout(function () { location.reload(); }, 900); } else notyf.error(dd.message); }); });
            });
        });
        document.querySelectorAll('.js-w-reject[data-id]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = this.dataset.id;
                Swal.fire({ title: 'رد درخواست برداشت', input: 'textarea', inputPlaceholder: 'دلیل رد...', icon: 'warning', showCancelButton: true, confirmButtonText: '⛔ رد', cancelButtonText: 'انصراف', confirmButtonColor: '#F6465D' })
                    .then(function (r) { if (!r.isConfirmed) return; post(base + '/' + id + '/reject', { reason: r.value }).then(function (dd) { if (dd.success) { notyf.success(dd.message); setTimeout(function () { location.reload(); }, 900); } else notyf.error(dd.message); }); });
            });
        });
    });
})();
