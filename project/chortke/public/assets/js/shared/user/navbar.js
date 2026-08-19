/**
 * Shared User Navbar Behaviors
 * Quick search inside the topbar.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initTopbarSearch();
    });

    function initTopbarSearch() {
        var links = [
            { label: 'داشبورد', icon: 'dashboard', url: '/dashboard' },
            { label: 'کیف پول', icon: 'account_balance_wallet', url: '/wallet' },
            { label: 'واریز', icon: 'add_circle', url: '/wallet/deposit' },
            { label: 'برداشت', icon: 'remove_circle', url: '/wallet/withdraw' },
            { label: 'تسک‌ها', icon: 'task_alt', url: '/tasks' },
            { label: 'تاریخچه تسک‌ها', icon: 'history', url: '/tasks/history' },
            { label: 'تبلیغات', icon: 'campaign', url: '/advertiser' },
            { label: 'لاتاری', icon: 'casino', url: '/lottery' },
            { label: 'سرمایه‌گذاری', icon: 'trending_up', url: '/investment' },
            { label: 'پیج‌های من', icon: 'share', url: '/social-accounts' },
            { label: 'تراکنش‌ها', icon: 'receipt_long', url: '/wallet/history' },
            { label: 'پروفایل', icon: 'person', url: '/profile' },
            { label: 'احراز هویت KYC', icon: 'verified_user', url: '/kyc' },
            { label: 'کارت بانکی', icon: 'credit_card', url: '/bank-cards' },
            { label: 'امنیت', icon: 'lock', url: '/security' },
            { label: 'تیکت‌ها', icon: 'support_agent', url: '/tickets' },
            { label: 'اعلان‌ها', icon: 'notifications', url: '/notifications' },
            { label: 'زیرمجموعه‌ها', icon: 'people', url: '/referrals' }
        ];

        var inp = document.getElementById('tsInput');
        var res = document.getElementById('tsResults');
        if (!inp || !res) return;

        inp.addEventListener('input', function () {
            var q = this.value.trim();
            if (!q) {
                res.classList.remove('open');
                return;
            }
            var filtered = links.filter(function (l) {
                return l.label.indexOf(q) !== -1;
            });
            if (!filtered.length) {
                res.innerHTML = '<div class="ts-result-empty">نتیجه‌ای یافت نشد</div>';
            } else {
                res.innerHTML = filtered.slice(0, 8).map(function (l) {
                    return '<a class="ts-result-item" href="' + l.url + '">' +
                        '<span class="material-icons">' + l.icon + '</span>' +
                        '<span>' + l.label + '</span>' +
                    '</a>';
                }).join('');
            }
            res.classList.add('open');
        });

        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                res.classList.remove('open');
                inp.blur();
            }
            if (e.key === 'Enter') {
                var first = res.querySelector('.ts-result-item');
                if (first) window.location = first.getAttribute('href');
            }
        });

        document.addEventListener('click', function (e) {
            var wrap = document.getElementById('topbarSearch');
            if (wrap && !wrap.contains(e.target)) {
                res.classList.remove('open');
            }
        });
    }
})();
