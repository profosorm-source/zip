/* admin/social-tasks.js — منطق مشترک صفحات ماژول social-tasks
 * data-* روی #socialTasksRoot:
 *   data-tasks-base       => /admin/social-tasks
 *   data-executions-base  => /admin/social-executions
 *   data-trust-base       => /admin/social-trust/user
 *   data-exec-id, data-ad-id, data-user-id (در صفحات جزئیات)
 */
(function () {
    'use strict';
    function root() { return document.getElementById('socialTasksRoot') || document.body; }
    function d(k, fb) { var v = root().dataset[k]; return v !== undefined ? v : (fb || ''); }
    function csrf() { return window.csrfToken || ''; }

    function adminPost(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify(body || {})
        }).then(function (r) { return r.json(); });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var tasksBase = d('tasksBase');
        var execBase = d('executionsBase');
        var trustBase = d('trustBase');

        /* ── index: دکمه‌های لیست (data-id) ── */
        document.querySelectorAll('.btn-approve[data-id]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!confirm('تأیید و فعال‌سازی آگهی؟')) return;
                adminPost(tasksBase + '/' + this.dataset.id + '/approve').then(function (r) { r.success ? location.reload() : alert(r.message); });
            });
        });
        document.querySelectorAll('.btn-reject[data-id]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var reason = prompt('دلیل رد:'); if (!reason) return;
                adminPost(tasksBase + '/' + this.dataset.id + '/reject', { reason: reason }).then(function (r) { r.success ? location.reload() : alert(r.message); });
            });
        });
        document.querySelectorAll('.btn-pause[data-id]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                adminPost(tasksBase + '/' + this.dataset.id + '/pause').then(function (r) { r.success ? location.reload() : alert(r.message); });
            });
        });
        document.querySelectorAll('.btn-resume[data-id]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                adminPost(tasksBase + '/' + this.dataset.id + '/resume').then(function (r) { r.success ? location.reload() : alert(r.message); });
            });
        });

        /* ── show: دکمه‌های تکی (data-ad-id) ── */
        var adId = d('adId');
        if (adId) {
            var bind = function (id, fn) { var el = document.getElementById(id); if (el) el.addEventListener('click', fn); };
            bind('btn-approve', function () { if (!confirm('تأیید شود؟')) return; adminPost(tasksBase + '/' + adId + '/approve').then(function (r) { r.success ? location.reload() : alert(r.message); }); });
            bind('btn-reject', function () { var reason = prompt('دلیل رد:'); if (!reason) return; adminPost(tasksBase + '/' + adId + '/reject', { reason: reason }).then(function (r) { r.success ? location.reload() : alert(r.message); }); });
            bind('btn-pause', function () { adminPost(tasksBase + '/' + adId + '/pause').then(function (r) { r.success ? location.reload() : alert(r.message); }); });
            bind('btn-resume', function () { adminPost(tasksBase + '/' + adId + '/resume').then(function (r) { r.success ? location.reload() : alert(r.message); }); });
            bind('btn-cancel', function () { if (!confirm('لغو شود و بودجه باقیمانده برگشت داده شود؟')) return; adminPost(tasksBase + '/' + adId + '/cancel').then(function (r) { alert(r.message); if (r.success) location.reload(); }); });
        }

        /* ── execution-show: override / flag (data-exec-id) ── */
        var execId = d('execId');
        if (execId) {
            var btnOverride = document.getElementById('btn-override');
            if (btnOverride) btnOverride.addEventListener('click', async function () {
                var decision = document.getElementById('override-decision').value;
                var reason = document.getElementById('override-reason').value.trim();
                if (!reason) { alert('دلیل الزامی است'); return; }
                this.disabled = true;
                var d2 = await adminPost(execBase + '/' + execId + '/override', { decision: decision, reason: reason });
                if (d2.success) { alert(d2.message); location.reload(); } else { alert(d2.message); this.disabled = false; }
            });
            var btnFlag = document.getElementById('btn-flag');
            if (btnFlag) btnFlag.addEventListener('click', async function () {
                var note = document.getElementById('flag-note').value.trim();
                this.disabled = true;
                var d2 = await adminPost(execBase + '/' + execId + '/flag', { note: note });
                if (d2.success) { alert('فلگ شد'); location.reload(); } else { alert(d2.message); this.disabled = false; }
            });
        }

        /* ── user-trust: تنظیم اعتماد (data-user-id) ── */
        var userId = d('userId');
        if (userId) {
            var btnAdjust = document.getElementById('btn-adjust');
            if (btnAdjust) btnAdjust.addEventListener('click', async function () {
                var delta = parseFloat(document.getElementById('trust-delta').value);
                var reason = document.getElementById('trust-reason').value.trim();
                if (!reason) { alert('دلیل الزامی است'); return; }
                if (isNaN(delta) || delta === 0) { alert('مقدار معتبر وارد کنید'); return; }
                this.disabled = true;
                var fd = new FormData();
                fd.append('delta', delta); fd.append('reason', reason);
                var res = await fetch(trustBase + '/' + userId + '/adjust', { method: 'POST', headers: { 'X-CSRF-Token': csrf() }, body: fd });
                var d2 = await res.json();
                var el = document.getElementById('adjust-result');
                el.className = d2.success ? 'mt-2 small text-success' : 'mt-2 small text-danger';
                el.textContent = d2.success ? ('Trust جدید: ' + d2.new_trust) : d2.message;
                if (d2.success) setTimeout(function () { location.reload(); }, 1500);
                this.disabled = false;
            });
        }
    });
})();
