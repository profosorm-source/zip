/* admin/content.js — Admin Content Hub actions (PRIMARY / ADMIN_ONLY) */
(function () {
    'use strict';

    function root() { return document.getElementById('contentRoot') || document.body; }
    function d(key, fallback) {
        var el = root();
        if (!el || !el.dataset) return fallback || '';
        return el.dataset[key] !== undefined ? el.dataset[key] : (fallback || '');
    }
    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return window.csrfToken || (meta ? meta.getAttribute('content') : '') || '';
    }
    function notify(type, message) {
        message = message || (type === 'success' ? 'عملیات با موفقیت انجام شد.' : 'عملیات انجام نشد.');
        try {
            var n = window.notyf || (typeof Notyf !== 'undefined' ? new Notyf({ duration: 4500, position: { x: 'left', y: 'top' }, dismissible: true }) : null);
            if (n) {
                if (type === 'success') n.success(message); else n.error(message);
                return;
            }
        } catch (_) {}
        if (type === 'success') console.log(message); else alert(message);
    }
    function headers() {
        var h = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        var token = csrf();
        if (token) h['X-CSRF-TOKEN'] = token;
        return h;
    }
    async function postJSON(url, body) {
        if (!url) return { success: false, message: 'آدرس عملیات مشخص نیست.' };
        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timer = controller ? setTimeout(function () { controller.abort(); }, 30000) : null;
        try {
            var res = await fetch(url, {
                method: 'POST',
                headers: headers(),
                credentials: 'same-origin',
                signal: controller ? controller.signal : undefined,
                body: body === undefined ? '{}' : JSON.stringify(body)
            });
            var text = await res.text();
            var data;
            try { data = text ? JSON.parse(text) : {}; }
            catch (_) { data = { success: false, message: text ? text.slice(0, 180) : 'پاسخ سرور قابل خواندن نیست.' }; }
            if (!res.ok && data.success !== false) data.success = false;
            if (!data.message && !res.ok) data.message = 'خطای سرور (' + res.status + ')';
            return data;
        } catch (e) {
            return { success: false, message: e && e.name === 'AbortError' ? 'زمان پاسخ‌گویی سرور تمام شد.' : 'خطا در ارتباط با سرور.' };
        } finally {
            if (timer) clearTimeout(timer);
        }
    }
    function done(res, reloadDelay) {
        if (res && res.success) {
            notify('success', res.message);
            if (reloadDelay !== false) setTimeout(function () { window.location.reload(); }, reloadDelay || 900);
        } else {
            notify('error', (res && res.message) || 'عملیات انجام نشد.');
        }
    }
    function base() { return d('base'); }
    function revBase() { return d('revenueBase'); }

    function fire(options) {
        if (typeof Swal !== 'undefined' && Swal.fire) return Swal.fire(options);
        var ok = window.confirm((options.title || '') + '\n' + (options.text || ''));
        return Promise.resolve({ isConfirmed: ok, value: options.input ? window.prompt(options.inputLabel || options.title || '', '') : true });
    }

    function getSelectedIds() {
        return Array.from(document.querySelectorAll('.content-checkbox:checked')).map(function (cb) { return parseInt(cb.value, 10); }).filter(Boolean);
    }
    function updateBulkActionsBtn() {
        var btn = document.getElementById('bulkActionsBtn');
        if (!btn) return;
        if (getSelectedIds().length > 0) btn.classList.remove('ac-hidden');
        else btn.classList.add('ac-hidden');
    }

    window.toggleSelectAll = function (checkbox) {
        document.querySelectorAll('.content-checkbox').forEach(function (cb) { cb.checked = !!checkbox.checked; });
        updateBulkActionsBtn();
    };
    window.updateBulkActionsBtn = updateBulkActionsBtn;

    window.showBulkActions = function () {
        var ids = getSelectedIds();
        if (!ids.length) { notify('error', 'هیچ محتوایی انتخاب نشده است.'); return; }
        fire({
            title: 'عملیات گروهی',
            html: '<div dir="rtl" style="text-align:right"><p>' + ids.length + ' محتوا انتخاب شده است.</p><p>عملیات مورد نظر را انتخاب کنید.</p></div>',
            showDenyButton: true,
            showCancelButton: true,
            confirmButtonText: 'تأیید گروهی',
            denyButtonText: 'رد گروهی',
            cancelButtonText: 'انصراف',
            confirmButtonColor: '#0ECB81',
            denyButtonColor: '#F6465D'
        }).then(function (r) {
            if (r.isConfirmed) bulkApprove(ids);
            else if (r.isDenied) bulkReject(ids);
        });
    };
    function bulkApprove(ids) { postJSON(d('bulkApprove'), { ids: ids }).then(done); }
    function bulkReject(ids) {
        fire({
            title: 'رد گروهی محتواها',
            input: 'textarea',
            inputLabel: 'دلیل رد',
            inputPlaceholder: 'دلیل رد را ساده و قابل فهم بنویسید...',
            inputAttributes: { minlength: 10, required: true },
            showCancelButton: true,
            confirmButtonText: 'رد شود',
            cancelButtonText: 'انصراف',
            confirmButtonColor: '#F6465D',
            inputValidator: function (v) { if (!v || v.length < 10) return 'حداقل ۱۰ کاراکتر وارد کنید.'; }
        }).then(function (r) { if (r.isConfirmed) postJSON(d('bulkReject'), { ids: ids, reason: r.value }).then(done); });
    }

    window.approveContent = function (id) {
        fire({ title: 'تأیید محتوا', text: 'این محتوا وارد مرحله آماده انتشار می‌شود. ادامه می‌دهید؟', icon: 'question', showCancelButton: true, confirmButtonText: 'بله، تأیید کن', cancelButtonText: 'انصراف', confirmButtonColor: '#0ECB81' })
            .then(function (r) { if (r.isConfirmed) postJSON(base() + '/' + id + '/approve').then(done); });
    };
    window.rejectContent = function (id) {
        fire({ title: 'رد محتوا', input: 'textarea', inputLabel: 'دلیل رد', inputPlaceholder: 'مثلاً: لینک نامعتبر است یا محتوا با قوانین سازگار نیست...', inputAttributes: { minlength: 10, required: true }, showCancelButton: true, confirmButtonText: 'رد شود', cancelButtonText: 'انصراف', confirmButtonColor: '#F6465D', inputValidator: function (v) { if (!v || v.length < 10) return 'حداقل ۱۰ کاراکتر وارد کنید.'; } })
            .then(function (r) { if (r.isConfirmed) postJSON(base() + '/' + id + '/reject', { reason: r.value }).then(done); });
    };
    window.publishContent = function (id) {
        fire({
            title: 'ثبت انتشار محتوا',
            html: '<div dir="rtl" style="text-align:right"><label style="display:block;margin-bottom:6px">لینک منتشرشده</label><input id="swal-url" class="swal2-input" dir="ltr" placeholder="https://..."><label style="display:block;margin:12px 0 6px">نام کانال یا صفحه</label><input id="swal-channel" class="swal2-input" placeholder="مثلاً کانال آپارات چرتکه"></div>',
            showCancelButton: true,
            confirmButtonText: 'ثبت انتشار',
            cancelButtonText: 'انصراف',
            confirmButtonColor: '#F0B90B',
            preConfirm: function () {
                var url = (document.getElementById('swal-url') || {}).value || '';
                var channel = (document.getElementById('swal-channel') || {}).value || '';
                if (!/^https?:\/\//i.test(url.trim())) {
                    if (typeof Swal !== 'undefined') Swal.showValidationMessage('لینک انتشار معتبر وارد کنید.');
                    return false;
                }
                return { published_url: url.trim(), channel_name: channel.trim() };
            }
        }).then(function (r) { if (r.isConfirmed && r.value) postJSON(base() + '/' + id + '/publish', r.value).then(done); });
    };
    window.suspendContent = function (id) {
        fire({ title: 'تعلیق محتوا', input: 'textarea', inputLabel: 'دلیل تعلیق', inputPlaceholder: 'دلیل تعلیق را بنویسید...', inputAttributes: { minlength: 10, required: true }, showCancelButton: true, confirmButtonText: 'تعلیق شود', cancelButtonText: 'انصراف', confirmButtonColor: '#848E9C', inputValidator: function (v) { if (!v || v.length < 10) return 'حداقل ۱۰ کاراکتر وارد کنید.'; } })
            .then(function (r) { if (r.isConfirmed) postJSON(base() + '/' + id + '/suspend', { reason: r.value }).then(done); });
    };

    window.approveRevenue = function (id) {
        fire({ title: 'تأیید درآمد', text: 'بعد از تأیید، این درآمد آماده پرداخت به کیف پول کاربر می‌شود.', icon: 'question', showCancelButton: true, confirmButtonText: 'تأیید درآمد', cancelButtonText: 'انصراف', confirmButtonColor: '#0ECB81' })
            .then(function (r) { if (r.isConfirmed) postJSON(revBase() + '/' + id + '/approve').then(done); });
    };
    window.payRevenue = function (id) {
        fire({ title: 'پرداخت درآمد', text: 'مبلغ خالص به کیف پول کاربر واریز می‌شود. پرداخت تکراری توسط سیستم کنترل می‌شود.', icon: 'warning', showCancelButton: true, confirmButtonText: 'واریز شود', cancelButtonText: 'انصراف', confirmButtonColor: '#F0B90B' })
            .then(function (r) { if (r.isConfirmed) postJSON(revBase() + '/' + id + '/pay').then(done); });
    };

    window.exportContent = function () {
        var params = new URLSearchParams();
        if (d('filterStatus')) params.set('status', d('filterStatus'));
        if (d('filterPlatform')) params.set('platform', d('filterPlatform'));
        if (d('filterSearch')) params.set('search', d('filterSearch'));
        var q = params.toString();
        window.location.href = d('exportUrl') + (q ? '?' + q : '');
    };

    function setupRevenueForm() {
        var form = document.getElementById('revenueForm');
        if (!form) return;
        var totalInput = document.getElementById('total_revenue');
        var preview = document.getElementById('calcPreview');
        var sitePercent = parseFloat(d('sitePercent', '40')) || 40;
        var taxPercent = parseFloat(d('taxPercent', '9')) || 9;
        var userPercent = Math.max(0, 100 - sitePercent);
        var submitBtn = document.getElementById('submitBtn');
        var originalBtn = submitBtn ? submitBtn.innerHTML : '';
        var storeUrl = d('revenueStore');
        var redirectUrl = d('revenueRedirect') || base();

        function setText(id, value) { var el = document.getElementById(id); if (el) el.textContent = value; }
        setText('prevSitePercent', sitePercent.toString());
        setText('prevUserPercent', userPercent.toString());
        setText('prevTaxPercent', taxPercent.toString());

        function calc() {
            var total = parseFloat(totalInput && totalInput.value ? totalInput.value : '0') || 0;
            if (!preview) return;
            if (total <= 0) { preview.style.display = 'none'; return; }
            preview.style.display = 'block';
            var siteAmount = Math.round(total * sitePercent / 100);
            var userAmount = Math.round(total * userPercent / 100);
            var taxAmount = Math.round(userAmount * taxPercent / 100);
            var netAmount = userAmount - taxAmount;
            setText('prevSiteAmount', siteAmount.toLocaleString('fa-IR'));
            setText('prevUserAmount', userAmount.toLocaleString('fa-IR'));
            setText('prevTaxAmount', taxAmount.toLocaleString('fa-IR'));
            setText('prevNetAmount', netAmount.toLocaleString('fa-IR'));
        }
        if (totalInput) totalInput.addEventListener('input', calc);
        calc();

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (submitBtn) { submitBtn.disabled = true; submitBtn.setAttribute('aria-busy', 'true'); submitBtn.innerHTML = 'در حال ثبت...'; }
            var fd = new FormData(form);
            var data = {};
            fd.forEach(function (v, k) { data[k] = v; });
            postJSON(storeUrl, data).then(function (res) {
                if (res.success) {
                    notify('success', res.message || 'درآمد ثبت شد.');
                    setTimeout(function () { window.location.href = redirectUrl; }, 900);
                } else {
                    notify('error', res.message || 'ثبت درآمد انجام نشد.');
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.removeAttribute('aria-busy'); submitBtn.innerHTML = originalBtn; }
                }
            });
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { updateBulkActionsBtn(); setupRevenueForm(); });
    else { updateBulkActionsBtn(); setupRevenueForm(); }
})();
