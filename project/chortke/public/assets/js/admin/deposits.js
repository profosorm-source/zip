/* admin/deposits.js — منطق مشترک ماژول‌های واریز (crypto-deposits + manual-deposits)
 * هر دو ماژول الگوی یکسان دارند؛ فقط base فرق می‌کند.
 * data-* روی #depositsRoot:
 *   data-base       => /admin/crypto-deposits  یا  /admin/manual-deposits
 *   data-verify-url => {base}/verify   (صفحهٔ index)
 *   data-reject-url => {base}/reject   (صفحهٔ index)
 *   data-deposit-id => شناسهٔ واریز (صفحهٔ review)
 */
(function () {
    'use strict';
    function root() { return document.getElementById('depositsRoot') || document.body; }
    function d(k, fb) { var v = root().dataset[k]; return v !== undefined ? v : (fb || ''); }
    function csrf() { return window.csrfToken || ''; }

    window.copyToClipboard = function (text) {
        navigator.clipboard.writeText(text)
            .then(function () { notyf.success('کپی شد!'); })
            .catch(function () { notyf.error('خطا در کپی کردن'); });
    };

    /* ════ index: تأیید/رد با مودال ════ */
    window.verifyDeposit = function (depositId) {
        Swal.fire({
            title: 'تأیید واریز',
            html: '<p>آیا از تأیید این واریز اطمینان دارید؟</p><div style="margin-top:15px;padding:15px;background:#fff3e0;border-radius:8px;text-align:right;"><strong style="color:#f57c00;">توجه:</strong><p style="margin:5px 0 0 0;font-size:13px;color:#666;">لطفاً قبل از تأیید، تراکنش را بررسی کنید. پس از تأیید، مبلغ به کیف پول کاربر افزوده می‌شود.</p></div>',
            icon: 'question', showCancelButton: true, confirmButtonColor: '#4caf50', cancelButtonColor: '#999',
            confirmButtonText: 'بله، تأیید شود', cancelButtonText: 'انصراف'
        }).then(function (result) {
            if (!result.isConfirmed) return;
            var formData = new FormData();
            formData.append('deposit_id', depositId);
            formData.append('csrf_token', csrf());
            fetch(d('verifyUrl'), { method: 'POST', body: formData })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success) { notyf.success(data.message); setTimeout(function () { location.reload(); }, 1500); }
                    else notyf.error(data.message);
                })
                .catch(function () { notyf.error('خطا در ارتباط با سرور'); });
        });
    };
    window.showRejectModal = function (depositId) {
        var el = document.getElementById('reject_deposit_id'); if (el) el.value = depositId;
        var rr = document.getElementById('rejection_reason'); if (rr) rr.value = '';
        var m = document.getElementById('rejectModal'); if (m) m.classList.add('show');
    };
    window.closeRejectModal = function () {
        var m = document.getElementById('rejectModal'); if (m) m.classList.remove('show');
    };
    window.setReason = function (reason) {
        var rr = document.getElementById('rejection_reason'); if (rr) rr.value = reason;
    };

    /* ════ review: تأیید/رد تکی ════ */
    window.doApprove = function () {
        if (!confirm('آیا از تأیید این واریز مطمئنید؟')) return;
        var base = d('base'), dId = d('depositId');
        fetch(base + '/' + dId + '/approve', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() } })
            .then(function (r) { return r.json(); }).then(function (res) { if (res.success) location.href = base; else alert(res.message || 'خطا'); });
    };
    window.showReject = function () { var b = document.getElementById('rejectBox'); if (b) b.style.display = 'block'; };
    window.doReject = function () {
        var base = d('base'), dId = d('depositId');
        var reason = document.getElementById('rejectReason').value;
        fetch(base + '/' + dId + '/reject', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify({ reason: reason }) })
            .then(function (r) { return r.json(); }).then(function (res) { if (res.success) location.href = base; else alert(res.message || 'خطا'); });
    };

    document.addEventListener('DOMContentLoaded', function () {
        var rejectForm = document.getElementById('rejectForm');
        // فقط در صفحهٔ index که rejectForm + verifyUrl دارد
        if (rejectForm && d('rejectUrl')) {
            rejectForm.addEventListener('submit', function (e) {
                e.preventDefault();
                fetch(d('rejectUrl'), { method: 'POST', body: new FormData(this) })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data.success) { window.closeRejectModal(); notyf.success(data.message); setTimeout(function () { location.reload(); }, 1000); }
                        else notyf.error(data.message);
                    })
                    .catch(function () { notyf.error('خطا در ارتباط با سرور'); });
            });
        }
    });
})();
