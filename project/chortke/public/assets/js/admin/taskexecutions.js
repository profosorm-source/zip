/* admin/task-executions.js — تأیید/رد اجرای تسک (index لیست + show جزئیات)
 * data-* روی #taskExecRoot:  data-base => /admin/task-executions ، data-exec-id (show)
 * index: دکمه‌های .btn-approve-ex / .btn-reject-ex با data-id
 * show:  دکمه‌های #btnAdminApprove / #btnAdminReject
 */
(function () {
    'use strict';
    function root() { return document.getElementById('taskExecRoot') || document.body; }
    function d(k, fb) { var v = root().dataset[k]; return v !== undefined ? v : (fb || ''); }
    function csrf() { return window.csrfToken || ''; }
    function post(url, body) {
        return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify(Object.assign({ _csrf_token: csrf() }, body || {})) }).then(function (r) { return r.json(); });
    }
    function approveFlow(url) {
        return Swal.fire({ title: 'تایید تسک', text: 'تسک تایید و پاداش پرداخت شود؟', icon: 'question', showCancelButton: true, confirmButtonText: 'تایید', cancelButtonText: 'انصراف', confirmButtonColor: '#4caf50' })
            .then(function (r) { if (r.isConfirmed) post(url).then(function (d2) { if (d2.success) { notyf.success(d2.message); setTimeout(function () { location.reload(); }, 1000); } else notyf.error(d2.message); }); });
    }
    function rejectFlow(url) {
        return Swal.fire({ title: 'رد تسک', input: 'textarea', inputLabel: 'دلیل رد:', showCancelButton: true, confirmButtonText: 'رد', cancelButtonText: 'انصراف', confirmButtonColor: '#f44336', inputValidator: function (v) { if (!v) return 'دلیل الزامی'; } })
            .then(function (r) { if (r.isConfirmed) post(url, { reason: r.value }).then(function (d2) { if (d2.success) { notyf.success(d2.message); setTimeout(function () { location.reload(); }, 1000); } else notyf.error(d2.message); }); });
    }
    document.addEventListener('DOMContentLoaded', function () {
        var base = d('base');
        document.querySelectorAll('.btn-approve-ex[data-id]').forEach(function (btn) {
            btn.addEventListener('click', function () { approveFlow(base + '/' + this.dataset.id + '/approve'); });
        });
        document.querySelectorAll('.btn-reject-ex[data-id]').forEach(function (btn) {
            btn.addEventListener('click', function () { rejectFlow(base + '/' + this.dataset.id + '/reject'); });
        });
        var execId = d('execId');
        if (execId) {
            var a = document.getElementById('btnAdminApprove');
            if (a) a.addEventListener('click', function () { approveFlow(base + '/' + execId + '/approve'); });
            var rj = document.getElementById('btnAdminReject');
            if (rj) rj.addEventListener('click', function () { rejectFlow(base + '/' + execId + '/reject'); });
        }
    });
})();
