/* public/assets/js/admin/sentry-failed-jobs.js — استخراج‌شده از views/admin/sentry/failed-jobs.php برای سازگاری با CSP و کش‌پذیری */
(function () {
  'use strict';
        async function retryJob(jobId) {
            if (!confirm('آیا می‌خواهید این job را دوباره ارسال کنید؟')) return;
            try {
                const res = await fetch(`/admin/sentry/failed-jobs/${jobId}/retry`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                });
                const data = await res.json();
                if (data.success) {
                    alert('Job با موفقیت دوباره ارسال شد.');
                    window.location.reload();
                } else {
                    alert('خطا: ' + (data.error || 'عملیات ناموفق بود'));
                }
            } catch (e) {
                alert('خطا در ارتباط: ' + e.message);
            }
        }

        async function deleteJob(jobId) {
            if (!confirm('آیا می‌خواهید این job را حذف کنید؟')) return;
            try {
                const res = await fetch(`/admin/sentry/failed-jobs/${jobId}/forget`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                });
                const data = await res.json();
                if (data.success) {
                    alert('Job با موفقیت حذف شد.');
                    window.location.reload();
                } else {
                    alert('خطا: ' + (data.error || 'عملیات ناموفق بود'));
                }
            } catch (e) {
                alert('خطا در ارتباط: ' + e.message);
            }
        }
    
})();
