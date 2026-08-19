/* admin/custom-tasks.js — منطق ماژول وظایف سفارشی (index تأیید/رد + disputes داوری)
 * data-* روی #customTasksRoot:
 *   data-approve-url => /admin/custom-tasks/approve
 *   data-resolve-url => /admin/custom-tasks/disputes/resolve
 */
(function () {
    'use strict';
    function root() { return document.getElementById('customTasksRoot') || document.body; }
    function d(k, fb) { var v = root().dataset[k]; return v !== undefined ? v : (fb || ''); }
    function csrf() { return window.csrfToken || ''; }
    function notyfInst() { return new Notyf({ duration: 3000, position: { x: 'left', y: 'top' } }); }

    document.addEventListener('DOMContentLoaded', function () {
        /* ── index: تأیید/رد وظیفه ── */
        var approveUrl = d('approveUrl');
        document.querySelectorAll('.btn-approve-task').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var taskId = this.dataset.id;
                var decision = this.dataset.decision;
                if (decision === 'reject') {
                    Swal.fire({ title: 'رد وظیفه', input: 'text', inputLabel: 'دلیل رد:', showCancelButton: true, confirmButtonColor: '#f44336', confirmButtonText: 'رد و بازگشت بودجه', cancelButtonText: 'انصراف', inputValidator: function (v) { if (!v) return 'دلیل را بنویسید'; } })
                        .then(function (result) { if (result.isConfirmed) sendApprove(taskId, 'reject', result.value); });
                } else {
                    Swal.fire({ title: 'تأیید وظیفه', text: 'این وظیفه فعال خواهد شد.', icon: 'question', showCancelButton: true, confirmButtonText: 'تأیید', cancelButtonText: 'انصراف' })
                        .then(function (result) { if (result.isConfirmed) sendApprove(taskId, 'approve', null); });
                }
            });
        });
        function sendApprove(taskId, decision, reason) {
            fetch(approveUrl, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify({ csrf_token: csrf(), task_id: taskId, decision: decision, reason: reason })
            }).then(function (r) { return r.json(); }).then(function (data) {
                var n = notyfInst();
                if (data.success || data.ok) { n.success(data.message); setTimeout(function () { location.reload(); }, 1500); } else n.error(data.message);
            });
        }

        /* ── disputes: داوری اختلاف ── */
        var resolveUrl = d('resolveUrl');
        document.querySelectorAll('.btn-resolve').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var disputeId = this.dataset.id;
                var reason = this.dataset.reason || '';
                var refType = this.dataset.refType || '';
                var isVitrine = (refType === 'vitrine_listing');
                var decisionHtml, penaltyVisible;
                if (isVitrine) {
                    decisionHtml = '<select id="swal-decision" class="form-select form-select-sm"><option value="buyer">حق با خریدار (استرداد وجه)</option><option value="seller">حق با فروشنده (آزادسازی وجه)</option></select>';
                    penaltyVisible = false;
                } else {
                    decisionHtml = '<select id="swal-decision" class="form-select form-select-sm"><option value="executor">حق با مجری/کارمند (تأیید و پرداخت کامل)</option><option value="advertiser">حق با تبلیغ‌دهنده (رد ارسال)</option><option value="split">حل میانه (پرداخت درصدی به مجری)</option></select>';
                    penaltyVisible = true;
                }
                var penaltyDiv = penaltyVisible
                    ? '<div class="mb-2"><label class="form-label">درصد پرداخت به مجری در حالت حل میانه:</label><input type="number" id="swal-penalty" class="form-control form-control-sm" value="50" min="1" max="99"></div>'
                    : '<input type="hidden" id="swal-penalty" value="0">';
                Swal.fire({
                    title: 'داوری اختلاف',
                    html: '<div style="text-align:right;direction:rtl;font-size:13px;"><p><strong>دلیل اختلاف:</strong> ' + reason + '</p><hr><div class="mb-2"><label class="form-label">تصمیم:</label>' + decisionHtml + '</div>' + penaltyDiv + '<div class="mb-2"><label class="form-label">توضیحات ادمین:</label><textarea id="swal-note" class="form-control form-control-sm" rows="2"></textarea></div></div>',
                    showCancelButton: true, confirmButtonText: 'ثبت تصمیم', cancelButtonText: 'انصراف', width: 500,
                    preConfirm: function () { return { decision: document.getElementById('swal-decision').value, executor_percent: parseFloat(document.getElementById('swal-penalty').value) || 0, note: document.getElementById('swal-note').value }; }
                }).then(function (result) {
                    if (!result.isConfirmed) return;
                    fetch(resolveUrl, {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                        body: JSON.stringify({ csrf_token: csrf(), dispute_id: disputeId, decision: result.value.decision, admin_note: result.value.note, executor_percent: result.value.executor_percent })
                    }).then(function (r) { return r.json(); }).then(function (data) {
                        var n = notyfInst();
                        if (data.success || data.ok) { n.success(data.message); setTimeout(function () { location.reload(); }, 1500); } else n.error(data.message);
                    });
                });
            });
        });
    });
})();
