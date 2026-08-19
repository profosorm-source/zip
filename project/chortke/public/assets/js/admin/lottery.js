/* admin/lottery.js — منطق ماژول لاتاری (create + show)
 * data-* روی #lotteryRoot:  data-base => /admin/lottery ، data-store => /admin/lottery/store
 * show: توابع generateNumbers/finalizeDaily/selectWinner/cancelRound (از data-click)
 */
(function () {
    'use strict';
    function root() { return document.getElementById('lotteryRoot') || document.body; }
    function d(k, fb) { var v = root().dataset[k]; return v !== undefined ? v : (fb || ''); }
    function csrf() { return window.csrfToken || ''; }

    function ajaxPost(url) {
        fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                res.success ? notyf.success(res.message) : notyf.error(res.message);
                if (res.success) setTimeout(function () { location.reload(); }, 1200);
            });
    }
    function confirmAction(title, text, cb) {
        Swal.fire({ title: title, text: text, icon: 'question', showCancelButton: true, confirmButtonText: 'بله', cancelButtonText: 'انصراف' })
            .then(function (r) { if (r.isConfirmed) cb(); });
    }

    window.generateNumbers = function (id) { confirmAction('تولید اعداد امروز', 'آیا مطمئنید؟', function () { ajaxPost(d('base') + '/' + id + '/generate-numbers'); }); };
    window.finalizeDaily = function (dailyId) { confirmAction('نهایی‌سازی', 'عدد منتخب انتخاب و وزن‌ها اعمال می‌شود.', function () { ajaxPost(d('base') + '/daily/' + dailyId + '/finalize'); }); };
    window.selectWinner = function (id) { confirmAction('انتخاب برنده', '⚠️ این عملیات برگشت‌ناپذیر است!', function () { ajaxPost(d('base') + '/' + id + '/select-winner'); }); };
    window.cancelRound = function (id) { confirmAction('لغو دوره', 'آیا مطمئنید؟', function () { ajaxPost(d('base') + '/' + id + '/cancel'); }); };

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('roundForm');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var btn = document.getElementById('submitBtn');
                if (btn) btn.disabled = true;
                var fd = new FormData(this); var data = {};
                fd.forEach(function (v, k) { if (v) data[k] = v; });
                fetch(d('store'), { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify(data) })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.success) { notyf.success(res.message); setTimeout(function () { window.location.href = d('base'); }, 1500); }
                        else { notyf.error(res.message || 'خطا'); if (btn) btn.disabled = false; }
                    }).catch(function () { notyf.error('خطا'); if (btn) btn.disabled = false; });
            });
        }
    });
})();
