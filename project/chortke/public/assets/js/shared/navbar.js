/**
 * Shared Admin Navbar Behaviors
 * Notifications, global search, live clock, theme toggle, sidebar toggle.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initSidebarToggle();
        initThemeToggle();
        initNotifications();
        initGlobalSearch();
        initClock();
    });

    /* ── Sidebar Toggle ── */
    function initSidebarToggle() {
        const btn = document.getElementById('adminSidebarToggle');
        const sidebar = document.getElementById('adminSidebar');
        if (!btn || !sidebar) return;

        btn.addEventListener('click', function () {
            sidebar.classList.toggle('open');
        });
    }

    /* ── Theme Toggle ── */
    function initThemeToggle() {
        const btn = document.getElementById('themeToggleBtn');
        if (btn) {
            btn.addEventListener('click', function () {
                adminToggleTheme();
            });
        }
    }

    /* ── Live Clock ── */
    function initClock() {
        const el = document.getElementById('adminClock');
        if (!el) return;

        function pad(n) {
            return String(n).padStart(2, '0');
        }

        function tick() {
            const now = new Date();
            el.textContent = pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
        }

        tick();
        setInterval(tick, 1000);
    }

    /* ── Notifications ── */
    function initNotifications() {
        const fetchUrl = window.adminNavbarUrls?.notificationsFetch;
        const countUrl = window.adminNavbarUrls?.notificationsCount;
        const markUrl = window.adminNavbarUrls?.notificationsMark;
        const markAllUrl = window.adminNavbarUrls?.notificationsMarkAll;
        const csrf = window.csrfToken || '';

        if (!fetchUrl || !countUrl) return;

        const typeMap = {
            deposit: 'account_balance_wallet',
            withdrawal: 'payments',
            task: 'task_alt',
            kyc: 'verified_user',
            lottery: 'casino',
            referral: 'people',
            security: 'security',
            investment: 'trending_up',
            info: 'info',
            system: 'settings',
            kyc_submitted: 'verified_user',
            bank_card_submitted: 'credit_card',
            withdrawal_request: 'payments',
            deposit_manual: 'account_balance_wallet',
            new_user: 'person_add',
            new_ticket: 'confirmation_number',
            task_submitted: 'task_alt',
            story_order: 'auto_stories',
            content_submitted: 'article',
            system_alert: 'warning'
        };

        let dropdownOpen = false;
        let pollTimer = null;

        function escHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        async function updateBadge() {
            try {
                const res = await fetch(countUrl);
                const data = await res.json();
                if (!data.success) return;

                const count = parseInt(data.count || 0);
                const badge = document.getElementById('notifBadge');
                if (!badge) return;

                if (count > 0) {
                    badge.textContent = count > 99 ? '99+' : count;
                    badge.style.display = 'flex';
                } else {
                    badge.style.display = 'none';
                }
            } catch (e) {}
        }

        async function loadDropdown() {
            const list = document.getElementById('notifDropdownList');
            if (!list) return;
            list.innerHTML = '<div class="notif-empty">در حال بارگذاری...</div>';

            try {
                const res = await fetch(fetchUrl);
                const data = await res.json();
                if (!data.success || !data.notifications || !data.notifications.length) {
                    list.innerHTML = '<div class="notif-empty">اعلانی وجود ندارد</div>';
                    return;
                }

                list.innerHTML = data.notifications.map(function (n) {
                    const icon = typeMap[n.type] || 'notifications';
                    const unread = !n.is_read ? 'notif-item--unread' : '';
                    return '<div class="notif-item ' + unread + '" data-id="' + n.id + '">' +
                        '<div class="notif-item-icon"><span class="material-icons">' + icon + '</span></div>' +
                        '<div class="notif-item-body">' +
                            '<div class="notif-item-title">' + escHtml(n.title || '') + '</div>' +
                            '<div class="notif-item-msg">' + escHtml(n.message || '') + '</div>' +
                            '<div class="notif-item-time">' + escHtml(n.created_at || '') + '</div>' +
                        '</div>' +
                        (!n.is_read ? '<div class="notif-item-dot"></div>' : '') +
                    '</div>';
                }).join('');

                const badge = document.getElementById('notifBadge');
                const uc = parseInt(data.unread_count || 0);
                if (badge) {
                    if (uc > 0) {
                        badge.textContent = uc > 99 ? '99+' : uc;
                        badge.style.display = 'flex';
                    } else {
                        badge.style.display = 'none';
                    }
                }
            } catch (e) {
                list.innerHTML = '<div class="notif-empty">خطا در بارگذاری</div>';
            }
        }

        const bellBtn = document.getElementById('notifBellBtn');
        if (bellBtn) {
            bellBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                const dd = document.getElementById('notifDropdown');
                if (!dd) return;
                dropdownOpen = !dropdownOpen;
                dd.style.display = dropdownOpen ? 'block' : 'none';
                if (dropdownOpen) loadDropdown();
            });
        }

        document.addEventListener('click', function (e) {
            const wrap = document.getElementById('notifBellWrap');
            if (wrap && !wrap.contains(e.target)) {
                const dd = document.getElementById('notifDropdown');
                if (dd) dd.style.display = 'none';
                dropdownOpen = false;
            }
        });

        const notifWrap = document.getElementById('notifBellWrap');
        if (notifWrap) {
            notifWrap.addEventListener('click', function (e) {
                const item = e.target.closest('.notif-item');
                if (!item) return;
                const id = item.getAttribute('data-id');
                if (id) adminNotifRead(id, item);
            });
        }

        window.adminNotifRead = async function (id, el) {
            if (markUrl) {
                try {
                    await fetch(markUrl + id, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrf,
                            'Content-Type': 'application/json'
                        }
                    });
                } catch (e) {}
            }

            if (el) {
                el.classList.remove('notif-item--unread');
                const dot = el.querySelector('.notif-item-dot');
                if (dot) dot.remove();
            }
            updateBadge();
        };

        const markAllBtn = document.getElementById('notifMarkAllBtn');
        if (markAllBtn) {
            markAllBtn.addEventListener('click', async function (e) {
                e.stopPropagation();
                if (!markAllUrl) return;

                try {
                    await fetch(markAllUrl, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrf,
                            'Content-Type': 'application/json'
                        }
                    });
                } catch (e) {}

                document.querySelectorAll('.notif-item--unread').forEach(function (el) {
                    el.classList.remove('notif-item--unread');
                    const dot = el.querySelector('.notif-item-dot');
                    if (dot) dot.remove();
                });

                const badge = document.getElementById('notifBadge');
                if (badge) badge.style.display = 'none';
            });
        }

        updateBadge();
        pollTimer = setInterval(updateBadge, 30000);
    }

    /* ── Global Search ── */
    function initGlobalSearch() {
        const input = document.getElementById('adminSearchInput');
        const results = document.getElementById('adminSearchResults');
        const baseUrl = window.adminNavbarUrls?.search;

        if (!input || !results || !baseUrl) return;

        let timer = null;

        input.addEventListener('input', function () {
            clearTimeout(timer);
            const q = input.value.trim();
            if (q.length < 2) {
                results.style.display = 'none';
                results.innerHTML = '';
                return;
            }
            timer = setTimeout(function () {
                doSearch(q);
            }, 300);
        });

        input.addEventListener('focus', function () {
            if (results.innerHTML) results.style.display = '';
        });

        document.addEventListener('click', function (e) {
            if (!input.contains(e.target) && !results.contains(e.target)) {
                results.style.display = 'none';
            }
        });

        async function doSearch(q) {
            results.style.display = '';
            results.innerHTML = '<div class="search-loading">در حال جستجو...</div>';

            try {
                const r = await fetch(baseUrl + '?q=' + encodeURIComponent(q));
                const data = await r.json();

                if (!data || !data.results) {
                    results.innerHTML = '<div class="search-empty">نتیجه‌ای یافت نشد</div>';
                    return;
                }

                const sections = [
                    { key: 'users', label: 'کاربران', icon: 'person', url: '/admin/users/' },
                    { key: 'transactions', label: 'تراکنش‌ها', icon: 'receipt', url: '/admin/transactions' },
                    { key: 'tickets', label: 'تیکت‌ها', icon: 'confirmation_number', url: '/admin/tickets/' },
                    { key: 'withdrawals', label: 'برداشت‌ها', icon: 'payments', url: '/admin/withdrawals' }
                ];

                let html = '';
                sections.forEach(function (sec) {
                    const items = data.results[sec.key] || [];
                    if (!items.length) return;

                    html += '<div class="search-section-header">' +
                        '<span class="material-icons">' + sec.icon + '</span>' + sec.label +
                    '</div>';

                    items.forEach(function (item) {
                        const label = item.full_name || item.subject || item.title || '#' + item.id;
                        const sub = item.email || item.description || item.status || '';
                        html += '<a href="' + sec.url + item.id + '" class="search-result-item">' +
                            '<span class="material-icons">' + sec.icon + '</span>' +
                            '<div>' +
                                '<div class="search-result-title">' + escHtml(label) + '</div>' +
                                (sub ? '<div class="search-result-sub">' + escHtml(sub) + '</div>' : '') +
                            '</div>' +
                        '</a>';
                    });
                });

                if (!html) {
                    html = '<div class="search-empty">نتیجه‌ای یافت نشد</div>';
                }

                results.innerHTML = html;
            } catch (e) {
                results.innerHTML = '<div class="search-empty">خطا در جستجو</div>';
            }
        }

        function escHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }
    }
})();
