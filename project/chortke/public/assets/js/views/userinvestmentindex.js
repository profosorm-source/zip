(function () {
    'use strict';

    let notifier = null;

    function userSafeMessage(message) {
        const text = String(message || '');
        const lower = text.toLowerCase();
        if (lower.includes('saga transaction failed') || lower.includes('insufficient balance') || lower.includes('wallet frozen')) {
            return 'موجودی کیف پول USDT شما برای این عملیات کافی نیست یا کیف پول شما مسدود است.';
        }
        return text || 'عملیات انجام نشد. لطفاً دوباره تلاش کنید.';
    }

    function fallbackToast(type, message) {
        const toast = document.createElement('div');
        toast.textContent = message;
        Object.assign(toast.style, {
            position: 'fixed',
            left: '24px',
            top: '78px',
            zIndex: '10050',
            maxWidth: '360px',
            padding: '12px 16px',
            borderRadius: '12px',
            background: type === 'error' ? 'rgba(246,70,93,.96)' : 'rgba(30,217,168,.96)',
            color: '#fff',
            fontFamily: 'Vazirmatn, Tahoma, sans-serif',
            fontSize: '13px',
            fontWeight: '700',
            lineHeight: '1.8',
            boxShadow: '0 14px 34px rgba(0,0,0,.28)',
            opacity: '0',
            transform: 'translateY(-10px)',
            transition: 'opacity .2s ease, transform .2s ease'
        });
        document.body.appendChild(toast);
        requestAnimationFrame(function () {
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';
        });
        window.setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-10px)';
            window.setTimeout(function () { toast.remove(); }, 220);
        }, 4500);
    }

    function notify(type, message) {
        message = userSafeMessage(message);
        if (!notifier && typeof window.Notyf !== 'undefined') {
            notifier = new window.Notyf({
                duration: 4500,
                position: { x: 'left', y: 'top' },
                dismissible: true
            });
        }
        if (notifier && typeof notifier[type] === 'function') {
            notifier[type](message);
            return;
        }
        fallbackToast(type, message);
    }

    function getCsrf(root) {
        return root?.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    async function confirmWithdrawal(isClose) {
        const title = isClose ? 'بستن و برداشت کامل' : 'برداشت سود';
        const html = isClose
            ? '<p>با بستن کامل، سرمایه‌گذاری شما خاتمه می‌یابد و دوره قفل سرمایه‌گذاری جدید فعال می‌شود.</p>'
            : '<p>پس از ثبت برداشت سود، درخواست شما برای بررسی مالی ارسال می‌شود.</p>';

        if (window.Swal && typeof window.Swal.fire === 'function') {
            const result = await window.Swal.fire({
                title,
                html,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'تأیید و ارسال درخواست',
                cancelButtonText: 'انصراف',
                confirmButtonColor: isClose ? '#F6465D' : '#1ED9A8',
                background: '#11161F',
                color: '#E8EAED'
            });
            return result.isConfirmed;
        }

        return window.confirm(isClose
            ? 'آیا از بستن کامل سرمایه‌گذاری و ثبت برداشت مطمئن هستید؟'
            : 'آیا از ثبت درخواست برداشت سود مطمئن هستید؟');
    }

    document.addEventListener('DOMContentLoaded', function () {
        const root = document.getElementById('investmentRoot');

        document.querySelectorAll('.inv-progress__bar[data-width]').forEach(function (bar) {
            const width = bar.getAttribute('data-width') || '0%';
            requestAnimationFrame(function () {
                bar.style.width = width;
            });
        });

        document.addEventListener('click', async function (event) {
            const target = event.target.closest('[data-action="request-withdrawal"]');
            if (!target) return;

            const type = target.dataset.type;
            if (!type) return;

            const isClose = type === 'full_close';
            const confirmed = await confirmWithdrawal(isClose);
            if (!confirmed) return;

            const withdrawUrl = root?.dataset.withdrawUrl;
            if (!withdrawUrl) {
                notify('error', 'آدرس ثبت برداشت یافت نشد.');
                return;
            }

            target.disabled = true;
            target.classList.add('loading');

            try {
                const response = await fetch(withdrawUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': getCsrf(root)
                    },
                    body: JSON.stringify({ withdrawal_type: type })
                });

                const data = await response.json().catch(function () { return {}; });
                if (response.ok && data.success) {
                    notify('success', data.message || 'درخواست برداشت با موفقیت ثبت شد.');
                    window.setTimeout(function () { window.location.reload(); }, 1200);
                } else {
                    notify('error', data.message || 'ثبت درخواست برداشت انجام نشد.');
                }
            } catch (error) {
                notify('error', 'خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.');
            } finally {
                target.disabled = false;
                target.classList.remove('loading');
            }
        });
    });
})();
