/* admin/investment.js — منطق مشترک همهٔ صفحات ماژول سرمایه‌گذاری
 * URLها از data-* روی #investmentRoot خوانده می‌شوند:
 *   data-base               => /admin/investment
 *   data-withdrawals-base   => /admin/investment/withdrawals
 *   data-apply-url          => /admin/investment/apply-profit
 *   data-trades-base        => /admin/investment/trades
 *   data-trades-store       => /admin/investment/trades/store
 *   data-trades-list        => /admin/investment/trades
 */
(function () {
    'use strict';
    function root() { return document.getElementById('investmentRoot') || document.body; }
    function d(key, fb) { var v = root().dataset[key]; return v !== undefined ? v : (fb || ''); }
    function csrf() { return window.csrfToken || ''; }

    /* ── index: تعلیق سرمایه‌گذاری ── */
    window.suspendInvestment = function (id) {
        var base = d('base');
        Swal.fire({
            title: 'تعلیق سرمایه‌گذاری', input: 'textarea',
            inputLabel: 'دلیل', inputPlaceholder: 'دلیل تعلیق...',
            showCancelButton: true, confirmButtonText: 'تعلیق', cancelButtonText: 'انصراف',
            confirmButtonColor: '#f44336'
        }).then(function (result) {
            if (result.isConfirmed) {
                fetch(base + '/' + id + '/suspend', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                    body: JSON.stringify({ reason: result.value })
                }).then(function (r) { return r.json(); }).then(function (res) {
                    res.success ? notyf.success(res.message) : notyf.error(res.message);
                    if (res.success) setTimeout(function () { location.reload(); }, 1000);
                });
            }
        });
    };

    /* ── withdrawals: تأیید/رد ── */
    window.doApprove = function (id) {
        if (!confirm('تأیید این برداشت؟')) return;
        fetch(d('withdrawalsBase') + '/' + id + '/approve', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (res.success) location.reload(); else alert(res.message || 'خطا');
        });
    };
    window.doReject = function (id) {
        var reason = prompt('دلیل رد (اختیاری):');
        if (reason === null) return;
        fetch(d('withdrawalsBase') + '/' + id + '/reject', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ reason: reason })
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (res.success) location.reload(); else alert(res.message || 'خطا');
        });
    };

    /* ── trades: مودال بستن ترید ── */
    var _closeId = null;
    window.openCloseModal = function (id, pair) {
        _closeId = id;
        document.getElementById('closeModalDesc').textContent = 'بستن ترید #' + id + ' — ' + pair;
        document.getElementById('closePrice').value = '';
        document.getElementById('closePnl').value = '';
        document.getElementById('closeNote').value = '';
        document.getElementById('closeOverlay').classList.add('show');
    };
    window.closeModal = function () {
        var ov = document.getElementById('closeOverlay');
        if (ov) ov.classList.remove('show');
        _closeId = null;
    };
    function toast(msg, type) {
        type = type || 'ok';
        var w = document.getElementById('trToasts');
        if (!w) return;
        var t = document.createElement('div');
        t.className = 'tr-toast ' + type;
        t.innerHTML = '<div class="tr-toast-ico"><span class="material-icons">' + (type === 'ok' ? 'check_circle' : 'error') + '</span></div><div class="tr-toast-msg"></div>';
        t.querySelector('.tr-toast-msg').textContent = msg;
        w.appendChild(t);
        setTimeout(function () { t.classList.add('hide'); setTimeout(function () { t.remove(); }, 280); }, 3200);
    }
    window.doClose = function () {
        var btn = document.getElementById('closeConfirmBtn');
        if (btn) btn.disabled = true;
        var fd = new FormData();
        fd.append('csrf_token', csrf());
        fd.append('close_price', document.getElementById('closePrice').value);
        fd.append('profit_loss_amount', document.getElementById('closePnl').value);
        fd.append('note', document.getElementById('closeNote').value);
        fetch(d('tradesBase') + '/' + _closeId + '/close', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    closeModal();
                    toast(data.message || 'ترید با موفقیت بسته شد');
                    setTimeout(function () { location.reload(); }, 1200);
                } else {
                    toast(data.message || 'خطا در بستن ترید', 'err');
                    if (btn) { btn.disabled = false; btn.innerHTML = '<span class="material-icons">check</span> بستن ترید'; }
                }
            })
            .catch(function () { toast('خطا در ارتباط با سرور', 'err'); if (btn) btn.disabled = false; });
    };

    document.addEventListener('DOMContentLoaded', function () {
        // trades: کلیک خارج مودال
        var ov = document.getElementById('closeOverlay');
        if (ov) ov.addEventListener('click', function (e) { if (e.target === this) closeModal(); });

        // apply-profit form
        var applyForm = document.getElementById('applyForm');
        if (applyForm) {
            applyForm.addEventListener('submit', function (e) {
                e.preventDefault();
                Swal.fire({
                    title: 'تأیید نهایی',
                    text: 'آیا مطمئنید؟ این عملیات بر تمام سرمایه‌گذاری‌های فعال اعمال خواهد شد.',
                    icon: 'warning', showCancelButton: true,
                    confirmButtonText: 'بله، اعمال کن', cancelButtonText: 'انصراف', confirmButtonColor: '#f44336'
                }).then(function (result) {
                    if (result.isConfirmed) {
                        var btn = document.getElementById('submitBtn');
                        if (btn) btn.disabled = true;
                        var fd = new FormData(applyForm); var data = {};
                        fd.forEach(function (v, k) { data[k] = v; });
                        fetch(d('applyUrl'), {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                            body: JSON.stringify(data)
                        }).then(function (r) { return r.json(); }).then(function (res) {
                            if (res.success) Swal.fire('موفق!', res.message, 'success');
                            else notyf.error(res.message);
                            if (btn) btn.disabled = false;
                        }).catch(function () { notyf.error('خطا'); if (btn) btn.disabled = false; });
                    }
                });
            });
        }

        // trade-create form
        var tradeForm = document.getElementById('tradeForm');
        if (tradeForm) {
            tradeForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var btn = document.getElementById('submitBtn');
                if (btn) btn.disabled = true;
                var fd = new FormData(this); var data = {};
                fd.forEach(function (v, k) { if (v) data[k] = v; });
                fetch(d('tradesStore'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                    body: JSON.stringify(data)
                }).then(function (r) { return r.json(); }).then(function (res) {
                    if (res.success) {
                        notyf.success(res.message);
                        setTimeout(function () { window.location.href = d('tradesList'); }, 1500);
                    } else { notyf.error(res.message); if (btn) btn.disabled = false; }
                }).catch(function () { notyf.error('خطا'); if (btn) btn.disabled = false; });
            });
        }
    });
})();
