/* admin/sentry-failed-job-details.js — عملیات retry/forget روی job */
(function () {
    'use strict';
    var statusMessage = document.getElementById('statusMessage');
    // base-url و آدرس لیست از data-* روی همان عنصر خوانده می‌شوند
    var actionBase = statusMessage ? statusMessage.dataset.actionBase : '';
    var listUrl = statusMessage ? statusMessage.dataset.listUrl : '';

    window.handleAction = async function (action) {
        if (!statusMessage) return;
        var url = actionBase + '/' + action;
        try {
            var res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.csrfToken }
            });
            var data = await res.json();
            statusMessage.style.display = 'block';
            if (data.success) {
                statusMessage.innerText = action === 'retry' ? 'Job با موفقیت دوباره ارسال شد.' : 'Job با موفقیت حذف شد.';
                statusMessage.style.background = '#c6f6d5';
                statusMessage.style.color = '#22543d';
                if (action === 'forget') {
                    window.location.href = listUrl;
                }
            } else {
                statusMessage.innerText = data.error || 'عملیات ناموفق بود.';
                statusMessage.style.background = '#fed7d7';
                statusMessage.style.color = '#c53030';
            }
        } catch (error) {
            statusMessage.style.display = 'block';
            statusMessage.innerText = 'خطا در ارتباط با سرور.';
            statusMessage.style.background = '#fed7d7';
            statusMessage.style.color = '#c53030';
        }
    };
})();
