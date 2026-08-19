/* admin/social-accounts.js — تأیید/رد حساب اجتماعی (index + show)
 * data-* روی #socialAccountsRoot: data-base => /admin/social-accounts ، data-account-id (show)
 * index: .btn-verify / .btn-reject با data-id ؛ show: #btnVerifyAcc / #btnRejectAcc
 */
(function () {
    'use strict';
    function root() { return document.getElementById('socialAccountsRoot') || document.body; }
    function d(k, fb) { var v = root().dataset[k]; return v !== undefined ? v : (fb || ''); }
    function csrf() { return window.csrfToken || ''; }
    function post(url, body) {
        return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify(Object.assign({ _csrf_token: csrf() }, body || {})) }).then(function (r) { return r.json(); });
    }
    function verifyFlow(url) {
        return Swal.fire({ title: 'تایید حساب', text: 'حساب تایید شود؟', icon: 'question', showCancelButton: true, confirmButtonText: 'تایید', cancelButtonText: 'انصراف', confirmButtonColor: '#4caf50' })
            .then(function (r) { if (r.isConfirmed) post(url).then(function (d2) { if (d2.success) { notyf.success(d2.message); setTimeout(function () { location.reload(); }, 800); } else notyf.error(d2.message); }); });
    }
    function rejectFlow(url) {
        return Swal.fire({ title: 'رد حساب', input: 'textarea', inputLabel: 'دلیل رد:', showCancelButton: true, confirmButtonText: 'رد', cancelButtonText: 'انصراف', confirmButtonColor: '#f44336', inputValidator: function (v) { if (!v) return 'دلیل الزامی است'; } })
            .then(function (r) { if (r.isConfirmed) post(url, { reason: r.value }).then(function (d2) { if (d2.success) { notyf.success(d2.message); setTimeout(function () { location.reload(); }, 800); } else notyf.error(d2.message); }); });
    }
    document.addEventListener('DOMContentLoaded', function () {
        var base = d('base');
        document.querySelectorAll('.btn-verify[data-id]').forEach(function (btn) {
            btn.addEventListener('click', function () { verifyFlow(base + '/' + this.dataset.id + '/verify'); });
        });
        document.querySelectorAll('.btn-reject[data-id]').forEach(function (btn) {
            btn.addEventListener('click', function () { rejectFlow(base + '/' + this.dataset.id + '/reject'); });
        });
        var accId = d('accountId');
        if (accId) {
            var v = document.getElementById('btnVerifyAcc');
            if (v) v.addEventListener('click', function () { verifyFlow(base + '/' + accId + '/verify'); });
            var rj = document.getElementById('btnRejectAcc');
            if (rj) rj.addEventListener('click', function () { rejectFlow(base + '/' + accId + '/reject'); });
        }
    });
})();
