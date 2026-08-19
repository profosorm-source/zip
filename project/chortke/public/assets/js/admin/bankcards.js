/* admin/bank-cards.js — منطق مشترک ماژول کارت‌های بانکی (index لیست + review جزئیات)
 * data-* روی #bankCardsRoot:
 *   data-base     => /admin/bank-cards
 *   data-verify-url => /admin/bank-cards/verify
 *   data-reject-url => /admin/bank-cards/reject
 *   data-card-id  => شناسهٔ کارت (صفحهٔ review)
 */
(function () {
    'use strict';
    function root() { return document.getElementById('bankCardsRoot') || document.body; }
    function d(k, fb) { var v = root().dataset[k]; return v !== undefined ? v : (fb || ''); }
    function csrf() { return window.csrfToken || ''; }

    function bcToast(msg, type) {
        type = type || 'success';
        var wrap = document.getElementById('bcToastWrap');
        if (!wrap) { (type === 'success' ? (window.notyf && notyf.success) : (window.notyf && notyf.error)) && notyf[type === 'success' ? 'success' : 'error'](msg); return; }
        var t = document.createElement('div');
        t.className = 'bc-toast ' + type;
        var icon = type === 'success' ? 'check_circle' : 'error';
        t.innerHTML = '<div class="bc-toast-icon"><span class="material-icons">' + icon + '</span></div><div class="bc-toast-msg"></div>';
        t.querySelector('.bc-toast-msg').textContent = msg;
        wrap.appendChild(t);
        setTimeout(function () { t.classList.add('hide'); setTimeout(function () { t.remove(); }, 300); }, 3500);
    }

    /* ════ مشترک: مودال‌ها ════ */
    var _confirmCardId = null, _confirmBtn = null;

    /* index: تأیید کارت (از data-click="verifyCard" با data-pass-el) */
    window.verifyCard = function (cardId, btn) {
        _confirmCardId = cardId;
        _confirmBtn = btn;
        var ov = document.getElementById('confirmOverlay');
        if (ov) ov.classList.add('show');
    };
    /* review: تأیید (بدون آرگومان؛ از CARD_ID صفحه) */
    window.doVerify = function () {
        var ov = document.getElementById('confirmOverlay');
        if (ov) ov.classList.add('show');
    };
    window.closeConfirmModal = function () {
        var ov = document.getElementById('confirmOverlay');
        if (ov) ov.classList.remove('show');
        _confirmCardId = null; _confirmBtn = null;
    };
    window.openRejectModal = function (cardId) {
        var rc = document.getElementById('reject_card_id'); if (rc) rc.value = cardId;
        var rr = document.getElementById('rejection_reason'); if (rr) rr.value = '';
        var ov = document.getElementById('rejectOverlay'); if (ov) ov.classList.add('show');
    };
    window.closeRejectModal = function () {
        var ov = document.getElementById('rejectOverlay'); if (ov) ov.classList.remove('show');
    };
    window.setReason = function (txt) {
        var rr = document.getElementById('rejection_reason'); if (rr) { rr.value = txt; rr.focus(); }
    };
    window.setR = function (txt) {
        var rr = document.getElementById('rejectReason'); if (rr) rr.value = txt;
    };

    function doVerifyConfirmed() {
        var verifyUrl = d('verifyUrl');
        var ov = document.getElementById('confirmOverlay');
        if (ov) ov.classList.remove('show');

        var isReview = !!d('cardId');
        var cardId = isReview ? d('cardId') : _confirmCardId;
        var btn = _confirmBtn;
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="material-icons" style="animation:spin 1s linear infinite">refresh</span>'; }

        var fd = new FormData();
        fd.append('card_id', cardId);
        fd.append('csrf_token', csrf());

        fetch(verifyUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    bcToast(data.message || 'کارت با موفقیت تأیید شد');
                    if (isReview) {
                        setTimeout(function () { location.href = d('base'); }, 1400);
                    } else if (btn) {
                        var tr = btn.closest('tr');
                        if (tr) { tr.style.transition = 'opacity .4s'; tr.style.opacity = '0'; setTimeout(function () { tr.remove(); }, 400); }
                    }
                } else {
                    bcToast(data.message || 'خطا در تأیید کارت', 'error');
                    if (btn) { btn.disabled = false; btn.innerHTML = '<span class="material-icons">check</span>تأیید'; }
                }
            })
            .catch(function () { bcToast('خطا در ارتباط با سرور', 'error'); if (btn) btn.disabled = false; });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var rejectUrl = d('rejectUrl');
        var isReview = !!d('cardId');

        var yesBtn = document.getElementById('confirmYesBtn');
        if (yesBtn) yesBtn.addEventListener('click', doVerifyConfirmed);

        var confirmOv = document.getElementById('confirmOverlay');
        if (confirmOv) confirmOv.addEventListener('click', function (e) { if (e.target === this) closeConfirmModal(); });

        var rejectOv = document.getElementById('rejectOverlay');
        if (rejectOv) rejectOv.addEventListener('click', function (e) { if (e.target === this) closeRejectModal(); });

        var rejectForm = document.getElementById('rejectForm');
        if (rejectForm) rejectForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(this);
            var btn = this.querySelector('[type=submit]');
            if (btn) btn.disabled = true;
            fetch(rejectUrl, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        closeRejectModal();
                        bcToast(data.message || 'کارت رد شد');
                        if (isReview) {
                            setTimeout(function () { location.href = d('base'); }, 1400);
                        } else {
                            var cardId = document.getElementById('reject_card_id').value;
                            document.querySelectorAll('tbody tr').forEach(function (row) {
                                if (row.querySelector('[data-args*="' + cardId + '"]')) {
                                    row.style.transition = 'opacity .4s'; row.style.opacity = '0';
                                    setTimeout(function () { row.remove(); }, 400);
                                }
                            });
                        }
                    } else {
                        bcToast(data.message || 'خطا در رد کارت', 'error');
                    }
                })
                .catch(function () { bcToast('خطا در ارتباط با سرور', 'error'); })
                .finally(function () { if (btn) btn.disabled = false; });
        });
    });
})();
