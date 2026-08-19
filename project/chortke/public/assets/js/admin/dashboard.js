/**
 * Admin Dashboard Script
 * ─────────────────────────────────────────────────────────────
 * منطق داشبورد مدیریت (نمودار، فعالیت‌های اخیر، وضعیت سیستم).
 *
 * این فایل قبلاً به‌صورت inline داخل views/admin/dashboard.php بود و
 * برای سبک‌تر شدن صفحه، کش‌پذیری و سازگاری با CSP به اینجا
 * (public/assets/js/admin/dashboard.js) منتقل و تکمیل شد.
 *
 * داده‌ها و آدرس‌های موردنیاز از یک تگ JSON با شناسهٔ
 * #dashboard-bootstrap خوانده می‌شوند (تزریق‌شده از سمت PHP).
 * بنابراین هیچ آدرس یا داده‌ای در این فایل hard-code نشده و روی
 * هر سرور/زیرشاخه‌ای بدون تغییر کار می‌کند.
 * ─────────────────────────────────────────────────────────────
 */
(function () {
    'use strict';

    /* ── خواندن تنظیمات تزریق‌شده از PHP ───────────────────────── */
    function readBootstrap() {
        var el = document.getElementById('dashboard-bootstrap');
        if (!el) return {};
        try {
            return JSON.parse(el.textContent || '{}');
        } catch (e) {
            console.error('Dashboard bootstrap JSON parse error:', e);
            return {};
        }
    }

    var CFG = readBootstrap();
    // ساختار مورد انتظار CFG:
    // {
    //   chartData: number[], chartLabels: string[],
    //   urls: { recentActivity, systemStatus },
    //   assets: { defaultAvatar }
    // }
    var URLS = CFG.urls || {};
    var ASSETS = CFG.assets || {};

    /* ── کمک‌تابع امن برای جلوگیری از XSS هنگام تزریق در innerHTML ── */
    function esc(v) {
        if (v === null || v === undefined) return '';
        return String(v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /* ── لودینگ اختصاصی چرتکه (در صورت موجود بودن) ───────────────── */
    function loaderShow(text) {
        if (window.ChortkeLoader && typeof window.ChortkeLoader.show === 'function') {
            window.ChortkeLoader.show(text);
        }
    }
    function loaderHide() {
        if (window.ChortkeLoader && typeof window.ChortkeLoader.hide === 'function') {
            window.ChortkeLoader.hide();
        }
    }

    /* ═══════════════════════════════════════════════════════════
       ساعت زنده‌ی بنر خوش‌آمدگویی
       ═══════════════════════════════════════════════════════════ */
    (function tick() {
        var n = new Date();
        var el = document.getElementById('dash-clock');
        if (el) {
            el.textContent =
                String(n.getHours()).padStart(2, '0') + ':' +
                String(n.getMinutes()).padStart(2, '0');
        }
        setTimeout(tick, 1000);
    })();

    document.addEventListener('DOMContentLoaded', function () {

        /* ═══════════════════════════════════════════════════════
           نمودار ثبت‌نام‌ها
           ═══════════════════════════════════════════════════════ */
        var ctx = document.getElementById('usersChart');
        if (ctx && typeof Chart !== 'undefined') {
            var dark = !document.documentElement.classList.contains('light');
            var gc = dark ? 'rgba(255,255,255,0.04)' : 'rgba(0,0,0,0.05)';
            var tc = dark ? '#475569' : '#94a3b8';
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: CFG.chartLabels || [],
                    datasets: [{
                        label: 'کاربران جدید',
                        data: CFG.chartData || [],
                        borderColor: '#5b8af5',
                        backgroundColor: 'rgba(91,138,245,0.08)',
                        tension: 0.4, fill: true,
                        pointRadius: 3, pointBackgroundColor: '#5b8af5', pointHoverRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { color: gc }, ticks: { color: tc, font: { size: 10, family: 'Vazirmatn' } } },
                        y: { grid: { color: gc }, ticks: { color: tc, font: { size: 10, family: 'Vazirmatn' } }, beginAtZero: true }
                    }
                }
            });
        }

        /* ═══════════════════════════════════════════════════════
           بارگذاری فعالیت‌های اخیر کاربران
           ═══════════════════════════════════════════════════════ */
        var currentPage = 1;
        var currentType = 'all';
        var isLoading = false;

        var activityTypeMap = {
            register: { icon: 'person_add',     color: '#10b981', label: 'ثبت‌نام کرد' },
            login:    { icon: 'login',          color: '#06b6d4', label: 'وارد شد' },
            kyc:      { icon: 'verified_user',  color: '#8b5cf6', label: 'درخواست احراز هویت' },
            task:     { icon: 'task_alt',       color: '#f59e0b', label: 'تسک انجام داد' },
            withdraw: { icon: 'payments',       color: '#ef4444', label: 'درخواست برداشت' },
            deposit:  { icon: 'account_balance',color: '#10b981', label: 'واریز کرد' },
            card:     { icon: 'credit_card',    color: '#3b82f6', label: 'کارت بانکی اضافه کرد' },
            ad:       { icon: 'campaign',       color: '#ec4899', label: 'تبلیغ ثبت کرد' }
        };

        var activitiesContainer = document.getElementById('userActivitiesContainer');
        var loadMoreContainer   = document.getElementById('loadMoreContainer');

        function skeletonActivities(count) {
            var s = '';
            for (var i = 0; i < (count || 5); i++) {
                s += '' +
                '<div class="user-activity-item skeleton-row" style="display:flex;gap:16px;padding:16px 20px;border-bottom:1px solid var(--border)">' +
                    '<div class="sk sk-circle" style="width:48px;height:48px;border-radius:50%;flex-shrink:0"></div>' +
                    '<div style="flex:1;min-width:0">' +
                        '<div class="sk sk-line" style="width:40%;height:14px;margin-bottom:8px"></div>' +
                        '<div class="sk sk-line" style="width:65%;height:12px;margin-bottom:8px"></div>' +
                        '<div class="sk sk-line" style="width:30%;height:11px"></div>' +
                    '</div>' +
                '</div>';
            }
            return s;
        }

        function loadActivities(append) {
            if (isLoading) return;
            isLoading = true;

            if (!append) {
                currentPage = 1;
                if (activitiesContainer) {
                    activitiesContainer.innerHTML = skeletonActivities(5);
                }
            }

            var url = (URLS.recentActivity || '') +
                '?type=' + encodeURIComponent(currentType) +
                '&limit=20&page=' + currentPage;

            fetch(url)
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.success || !data.data || data.data.length === 0) {
                        if (!append && activitiesContainer) {
                            activitiesContainer.innerHTML =
                                '<div class="empty-state" style="padding:48px 20px;text-align:center;color:var(--text-muted)">' +
                                    '<span class="material-icons" aria-hidden="true" style="font-size:48px;opacity:0.25">history_toggle_off</span>' +
                                    '<p style="margin-top:14px;font-size:15px;font-weight:600;color:var(--text-secondary)">فعالیتی یافت نشد</p>' +
                                    '<p style="margin-top:4px;font-size:12px">با تغییر فیلتر یا پس از فعالیت کاربران اینجا نمایش داده می‌شود</p>' +
                                '</div>';
                        }
                        if (loadMoreContainer) loadMoreContainer.style.display = 'none';
                        isLoading = false;
                        return;
                    }

                    var html = '';
                    data.data.forEach(function (activity) {
                        var typeInfo = activityTypeMap[activity.type] ||
                            { icon: 'info', color: '#64748b', label: activity.description };
                        var avatar = activity.avatar_url || ASSETS.defaultAvatar || '';
                        var fullName = activity.full_name || 'کاربر ناشناس';
                        var email = activity.email || '';

                        var badges = '';
                        if (activity.summary && activity.summary.length > 0) {
                            badges = activity.summary.map(function (s) {
                                return '<span class="badge badge-' + esc(s.color || 'default') +
                                    '" style="font-size:10px;padding:2px 6px">' + esc(s.label) + '</span>';
                            }).join('');
                        }

                        html += '' +
                        '<div class="user-activity-item" style="display:flex;gap:16px;padding:16px 20px;border-bottom:1px solid var(--border);transition:background 0.2s">' +
                            '<div style="flex-shrink:0">' +
                                '<img src="' + esc(avatar) + '" alt="' + esc(fullName) + '" style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid var(--border)">' +
                            '</div>' +
                            '<div style="flex:1;min-width:0">' +
                                '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">' +
                                    '<span style="font-weight:600;font-size:14px;color:var(--text-primary)">' + esc(fullName) + '</span>' +
                                    badges +
                                '</div>' +
                                '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">' +
                                    '<span class="material-icons" aria-hidden="true" style="font-size:16px;color:' + esc(typeInfo.color) + '">' + esc(typeInfo.icon) + '</span>' +
                                    '<span style="font-size:13px;color:var(--text-secondary)">' + esc(activity.description || typeInfo.label) + '</span>' +
                                '</div>' +
                                '<div style="display:flex;align-items:center;gap:12px;font-size:12px;color:var(--text-muted)">' +
                                    '<div style="display:flex;align-items:center;gap:4px">' +
                                        '<span class="material-icons" aria-hidden="true" style="font-size:14px">schedule</span>' +
                                        '<span>' + esc(activity.time_ago || '') + '</span>' +
                                    '</div>' +
                                    (email ? '<div style="display:flex;align-items:center;gap:4px">' +
                                        '<span class="material-icons" aria-hidden="true" style="font-size:14px">email</span>' +
                                        '<span>' + esc(email) + '</span>' +
                                    '</div>' : '') +
                                '</div>' +
                            '</div>' +
                        '</div>';
                    });

                    if (activitiesContainer) {
                        if (append) activitiesContainer.innerHTML += html;
                        else        activitiesContainer.innerHTML = html;
                    }

                    if (loadMoreContainer) {
                        loadMoreContainer.style.display = (data.data.length >= 20) ? 'block' : 'none';
                    }
                    isLoading = false;
                })
                .catch(function (err) {
                    console.error('خطا در بارگذاری فعالیت‌ها:', err);
                    if (!append && activitiesContainer) {
                        activitiesContainer.innerHTML =
                            '<div style="padding:40px 20px;text-align:center;color:var(--red)">' +
                                '<span class="material-icons" aria-hidden="true" style="font-size:48px;opacity:0.5">error_outline</span>' +
                                '<p style="margin-top:16px;font-size:14px">خطا در بارگذاری فعالیت‌ها</p>' +
                            '</div>';
                    }
                    isLoading = false;
                });
        }

        loadActivities(false);

        var filterEl = document.getElementById('activityTypeFilter');
        if (filterEl) {
            filterEl.addEventListener('change', function (e) {
                currentType = e.target.value;
                loadActivities(false);
            });
        }

        var loadMoreBtn = document.getElementById('loadMoreBtn');
        if (loadMoreBtn) {
            loadMoreBtn.addEventListener('click', function () {
                currentPage++;
                loadActivities(true);
            });
        }

        /* ═══════════════════════════════════════════════════════
           وضعیت سیستم / Cron / درگاه‌ها / صف ایمیل / منابع
           ═══════════════════════════════════════════════════════ */
        function setById(id, html) {
            var el = document.getElementById(id);
            if (el) el.innerHTML = html;
        }

        function loadSystemStatus(useLoader) {
            if (useLoader) loaderShow('در حال بروزرسانی وضعیت سیستم...');
            fetch(URLS.systemStatus || '')
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.success || !data.data) throw new Error('خطا در دریافت داده');
                    renderSystemStatus(data.data.services || []);
                    renderCronJobs(data.data.cron_jobs || []);
                    renderPaymentGates(data.data.payment_gates || []);
                    renderEmailQueue(data.data.email_queue || {});
                    renderServerResources(data.data.resources || {});
                    renderDatabaseHealth(data.data.database || {});
                    renderUptime(data.data.uptime || '');
                    if (useLoader) loaderHide();
                })
                .catch(function (err) {
                    console.error('خطا در بارگذاری وضعیت سیستم:', err);
                    setById('systemStatusContainer',
                        '<div style="padding:20px 0;text-align:center;color:var(--red)">' +
                            '<span class="material-icons" aria-hidden="true" style="font-size:36px;opacity:0.5">error_outline</span>' +
                            '<p style="margin-top:12px;font-size:13px">خطا در بارگذاری</p>' +
                        '</div>');
                    if (useLoader) loaderHide();
                });
        }

        function renderSystemStatus(services) {
            var SM = {
                online:  { cls: 'online', icon: 'check_circle', label: 'آنلاین' },
                warning: { cls: 'warn',   icon: 'warning',      label: 'هشدار' },
                error:   { cls: 'error',  icon: 'error',        label: 'خطا' },
                info:    { cls: 'online', icon: 'info',         label: 'اطلاعات' },
                unknown: { cls: 'muted',  icon: 'help_outline', label: 'نامشخص' }
            };
            var online = 0, warn = 0, err = 0, html = '';
            services.forEach(function (s) {
                var m = SM[s.status] || SM.unknown;
                if (s.status === 'online' || s.status === 'info') online++;
                else if (s.status === 'warning') warn++;
                else if (s.status === 'error') err++;
                html +=
                '<div class="ws-row">' +
                    '<div class="ws-row-left">' +
                        '<span class="ws-dot ' + m.cls + '"></span>' +
                        '<div>' +
                            '<div class="ws-name">' + esc(s.name) + '</div>' +
                            (s.hint ? '<div class="ws-hint">' + esc(s.hint) + '</div>' : '') +
                        '</div>' +
                    '</div>' +
                    '<span class="ws-pill ' + m.cls + '">' +
                        '<span class="material-icons" aria-hidden="true">' + m.icon + '</span>' + esc(s.label) +
                    '</span>' +
                '</div>';
            });
            if (!html) html = '<div class="ws-empty">اطلاعاتی موجود نیست</div>';
            setById('systemStatusContainer', html);
            var badge = document.getElementById('sysStatusBadge');
            if (badge) {
                badge.textContent = online + '/' + services.length + ' آنلاین';
                badge.style.setProperty('--w-badge-color', err > 0 ? 'var(--down)' : warn > 0 ? 'var(--warn)' : 'var(--up)');
                badge.style.setProperty('--w-badge-bg',    err > 0 ? 'var(--down-bg)' : warn > 0 ? 'var(--warn-bg)' : 'var(--up-bg)');
            }
        }

        function renderCronJobs(cronJobs) {
            var SM = {
                online:  { cls: 'online', icon: 'check_circle', label: 'موفق' },
                warning: { cls: 'warn',   icon: 'warning',      label: 'هشدار' },
                error:   { cls: 'error',  icon: 'error',        label: 'خطا' },
                unknown: { cls: 'muted',  icon: 'help_outline', label: 'نامشخص' }
            };
            var ok = 0, fail = 0, html = '';
            cronJobs.forEach(function (job) {
                var s = SM[job.status] || SM.unknown;
                if (job.status === 'online') ok++; else if (job.status === 'error') fail++;
                html +=
                '<div class="ws-row ws-cron-row">' +
                    '<div class="ws-row-left" style="flex-direction:column;align-items:flex-start;gap:3px">' +
                        '<div style="display:flex;align-items:center;gap:7px">' +
                            '<span class="ws-dot ' + s.cls + '"></span>' +
                            '<span class="ws-name">' + esc(job.name) + '</span>' +
                            '<span class="ws-cron-tag">' + esc(job.schedule) + '</span>' +
                        '</div>' +
                        '<div class="ws-hint" style="padding-right:15px">' +
                            'آخرین: ' + esc(job.last_run_ago || 'نامشخص') +
                            (job.execution_time ? ' · ' + esc(job.execution_time) + 's' : '') +
                            (job.items_processed ? ' · ' + esc(job.items_processed) + ' آیتم' : '') +
                        '</div>' +
                    '</div>' +
                    '<span class="ws-pill ' + s.cls + '">' +
                        '<span class="material-icons" aria-hidden="true">' + s.icon + '</span>' + s.label +
                    '</span>' +
                '</div>';
            });
            if (!html) html = '<div class="ws-empty">اطلاعاتی موجود نیست</div>';
            setById('cronJobsContainer', html);
            var badge = document.getElementById('cronBadge');
            if (badge) {
                badge.textContent = cronJobs.length + ' job';
                if (fail > 0) {
                    badge.style.setProperty('--w-badge-color', 'var(--down)');
                    badge.style.setProperty('--w-badge-bg', 'var(--down-bg)');
                }
            }
        }

        function renderPaymentGates(gates) {
            var online = 0, warn = 0, err = 0, html = '';
            gates.forEach(function (gate) {
                var sc = gate.status === 'online' ? 'online' : gate.status === 'warning' ? 'warn' : gate.status === 'error' ? 'error' : 'muted';
                var slabel = gate.status === 'online' ? 'متصل' : gate.status === 'warning' ? 'کند' : gate.status === 'error' ? 'قطع' : 'نامشخص';
                var sicon = gate.status === 'online' ? 'wifi' : gate.status === 'warning' ? 'warning' : 'wifi_off';
                if (sc === 'online') online++; else if (sc === 'error') err++; else warn++;
                var sr = gate.success_rate || 0;
                var srCls = sr >= 90 ? 'good' : sr >= 70 ? 'warn' : 'bad';
                html +=
                '<div class="wg-block">' +
                    '<div class="wg-header">' +
                        '<div class="wg-name-row">' +
                            '<span class="ws-dot ' + sc + '"></span>' +
                            '<span class="ws-name">' + esc(gate.name) + '</span>' +
                            (gate.ping_ms !== null && gate.ping_ms !== undefined ? '<span class="wg-ping">' + esc(gate.ping_ms) + 'ms</span>' : '') +
                        '</div>' +
                        '<span class="ws-pill ' + sc + '"><span class="material-icons" aria-hidden="true">' + sicon + '</span>' + slabel + '</span>' +
                    '</div>' +
                    '<div class="wg-cells">' +
                        '<div class="wg-cell"><div class="wg-cell-lbl">تراکنش امروز</div><div class="wg-cell-val">' + (gate.txn_today || 0).toLocaleString() + '</div></div>' +
                        '<div class="wg-cell bad"><div class="wg-cell-lbl">شکست امروز</div><div class="wg-cell-val">' + esc(gate.failed_today || 0) + '</div></div>' +
                        '<div class="wg-cell ' + srCls + '"><div class="wg-cell-lbl">نرخ موفق</div><div class="wg-cell-val">' + esc(sr) + '٪</div></div>' +
                        '<div class="wg-cell good"><div class="wg-cell-lbl">مبلغ امروز</div><div class="wg-cell-val">' + (gate.amount_today || 0).toLocaleString() + ' ت</div></div>' +
                    '</div>' +
                    '<div class="wg-last">آخرین تراکنش: ' + esc(gate.last_success) + '</div>' +
                '</div>';
            });
            if (!html) html = '<div class="ws-empty">درگاهی فعال نیست</div>';
            setById('paymentGatesContainer', html);
            var badge = document.getElementById('gatesBadge');
            if (badge) {
                badge.textContent = gates.length + ' درگاه';
                badge.style.setProperty('--w-badge-color', err > 0 ? 'var(--down)' : warn > 0 ? 'var(--warn)' : 'var(--up)');
                badge.style.setProperty('--w-badge-bg',    err > 0 ? 'var(--down-bg)' : warn > 0 ? 'var(--warn-bg)' : 'var(--up-bg)');
            }
        }

        function renderEmailQueue(queue) {
            var sr = queue.success_rate || 100;
            var cp = queue.capacity_pct || 0;
            var srColor = sr >= 90 ? 'var(--up)' : sr >= 70 ? 'var(--warn)' : 'var(--down)';
            var cpColor = cp >= 80 ? 'var(--down)' : cp >= 60 ? 'var(--warn)' : 'var(--up)';
            var badge = document.getElementById('emailBadge');
            if (badge) {
                var q = queue.queued || 0;
                badge.textContent = q > 0 ? (q + ' در صف') : 'خالی';
                badge.style.setProperty('--w-badge-color', q > 50 ? 'var(--down)' : q > 10 ? 'var(--warn)' : 'var(--up)');
                badge.style.setProperty('--w-badge-bg',    q > 50 ? 'var(--down-bg)' : q > 10 ? 'var(--warn-bg)' : 'var(--up-bg)');
            }
            var html =
            '<div class="wm-grid">' +
                '<div class="wm-stat" style="--ms-a:var(--warn)"><div class="wm-label">در صف</div><div class="wm-val">' + (queue.queued || 0) + '</div><div class="wm-sub">منتظر ارسال</div></div>' +
                '<div class="wm-stat" style="--ms-a:var(--up)"><div class="wm-label">ارسال امروز</div><div class="wm-val">' + (queue.sent_today || 0) + '</div><div class="wm-sub" style="color:var(--up)">موفق</div></div>' +
                '<div class="wm-stat" style="--ms-a:var(--down)"><div class="wm-label">شکست امروز</div><div class="wm-val">' + (queue.failed_today || 0) + '</div><div class="wm-sub">تلاش مجدد</div></div>' +
                '<div class="wm-stat" style="--ms-a:var(--info)"><div class="wm-label">در پردازش</div><div class="wm-val">' + (queue.processing || 0) + '</div><div class="wm-sub">فعال</div></div>' +
            '</div>' +
            ((queue.stuck || 0) > 0 ? '<div class="wm-alert"><span class="material-icons" aria-hidden="true">warning</span>معلق +۳۰ دقیقه: ' + esc(queue.stuck) + '</div>' : '') +
            '<div class="wm-prog">' +
                '<div class="wm-prog-hd"><span class="wm-prog-lbl">نرخ موفقیت ۷ روز</span><span class="wm-prog-val" style="color:' + srColor + '">' + esc(sr) + '٪</span></div>' +
                '<div class="wm-bar"><div class="wm-fill" style="width:' + esc(sr) + '%;background:' + srColor + '"></div></div>' +
            '</div>' +
            '<div class="wm-prog">' +
                '<div class="wm-prog-hd"><span class="wm-prog-lbl">ظرفیت صف</span><span class="wm-prog-val" style="color:' + cpColor + '">' + esc(cp) + '٪</span></div>' +
                '<div class="wm-bar"><div class="wm-fill" style="width:' + esc(cp) + '%;background:' + cpColor + '"></div></div>' +
            '</div>';

            if (queue.recent_failed && queue.recent_failed.length > 0) {
                html += '<div class="wm-fails-title">ایمیل‌های شکست‌خورده:</div>';
                queue.recent_failed.forEach(function (em) {
                    html +=
                    '<div class="wm-fail-row">' +
                        '<div class="wm-fail-to">' + esc(em.recipient || 'نامشخص') + '</div>' +
                        '<div class="wm-fail-sub">' + esc(em.subject || 'بدون موضوع') + '</div>' +
                        '<div class="wm-fail-err">' + esc(em.error_message || 'خطای نامشخص') + '</div>' +
                        '<div class="wm-fail-meta">' + esc(em.time_ago || '') + ' · ' + esc(em.attempts || 0) + ' تلاش</div>' +
                    '</div>';
                });
            }
            setById('emailQueueContainer', html);
        }

        function renderServerResources(res) {
            if (!res || !res.cpu) {
                setById('serverResourcesContainer', '<div class="ws-empty">اطلاعاتی موجود نیست</div>');
                return;
            }
            function resColor(pct) {
                if (pct == null) return 'var(--fg-muted)';
                return pct >= 85 ? 'var(--down)' : pct >= 60 ? 'var(--warn)' : 'var(--up)';
            }
            function resItem(icon, name, pct, detail) {
                var c = resColor(pct);
                var w = pct != null ? pct : 0;
                return '<div class="wr-block">' +
                    '<div class="wr-top">' +
                        '<div class="wr-left" style="color:' + c + '">' +
                            '<span class="material-icons" aria-hidden="true">' + icon + '</span>' +
                            '<span class="wr-name">' + name + '</span>' +
                        '</div>' +
                        '<span class="wr-pct" style="color:' + c + '">' + (pct != null ? pct + '%' : '—') + '</span>' +
                    '</div>' +
                    (detail ? '<div class="wr-detail">' + esc(detail) + '</div>' : '') +
                    '<div class="wr-bar"><div class="wr-fill" style="width:' + w + '%;background:' + c + '"></div></div>' +
                '</div>';
            }
            var cpu = res.cpu || {}, ram = res.ram || {}, disk = res.disk || {}, gpu = res.gpu || {};
            var cpuD = '', ramD = '', diskD = '';
            if (cpu.cores) cpuD += cpu.cores + ' هسته';
            if (cpu.freq)  cpuD += (cpuD ? ' · ' : '') + cpu.freq;
            if (ram.used_gb !== undefined)  ramD  = ram.used_gb  + ' / ' + ram.total_gb  + ' GB';
            if (disk.used_gb !== undefined) diskD = disk.used_gb + ' / ' + disk.total_gb + ' GB';
            if (disk.type) diskD += (diskD ? ' · ' : '') + disk.type;
            var html = resItem('developer_board', 'CPU',  cpu.pct  != null ? cpu.pct  : null, cpuD) +
                       resItem('storage',         'RAM',  ram.pct  != null ? ram.pct  : null, ramD) +
                       resItem('hard_drive',      'دیسک', disk.pct != null ? disk.pct : null, diskD);
            if (gpu.available) {
                var gpuD = gpu.vram_gb ? gpu.vram_gb + ' GB VRAM' : '';
                if (gpu.model) gpuD += (gpuD ? ' · ' : '') + gpu.model;
                html += resItem('videocam', 'GPU', gpu.pct != null ? gpu.pct : null, gpuD);
            }
            setById('serverResourcesContainer', html);

            // Show uptime
            var uptimeEl = document.getElementById('dashUptime');
            if (uptimeEl && res.uplink) {
                uptimeEl.textContent = '⏱ ' + esc(res.uplink);
            }
        }

        function renderDatabaseHealth(db) {
            if (!db || !db.status) {
                setById('dbHealthContainer', '<div class="ws-empty">اطلاعاتی موجود نیست</div>');
                return;
            }

            var statusColor = db.status === 'فعال' ? 'var(--up)' :
                              db.status === 'هشدار' ? 'var(--warn)' :
                              db.status === 'محدودشده' ? 'var(--down)' : 'var(--fg-muted)';

            var html = '<div class="wr-block">' +
                '<div class="wr-top">' +
                    '<div class="wr-left" style="color:' + statusColor + '">' +
                        '<span class="material-icons" aria-hidden="true">dns</span>' +
                        '<span class="wr-name">وضعیت</span>' +
                    '</div>' +
                    '<span class="wr-pct" style="color:' + statusColor + '">' + esc(db.status) + '</span>' +
                '</div>' +
            '</div>';

            if (db.slow_queries > 0) {
                html += '<div class="wr-detail db-warn">' +
                    '<span class="material-icons" aria-hidden="true" style="font-size:14px">speed</span> ' +
                    esc(db.slow_queries) + ' کوئری کند شناسایی شد' +
                '</div>';
            }
            if (db.deadlocks_recent > 0) {
                html += '<div class="wr-detail db-warn">' +
                    '<span class="material-icons" aria-hidden="true" style="font-size:14px">sync_problem</span> ' +
                    esc(db.deadlocks_recent) + ' بن‌بست اخیر' +
                '</div>';
            }
            if (db.tables_analyzed > 0) {
                html += '<div class="wr-detail db-ok">' +
                    '<span class="material-icons" aria-hidden="true" style="font-size:14px">table_chart</span> ' +
                    esc(db.tables_analyzed) + ' جدول بررسی‌شده' +
                '</div>';
            }
            if (db.warnings && db.warnings.length > 0) {
                db.warnings.forEach(function(w) {
                    html += '<div class="wr-detail db-warn">' +
                        '<span class="material-icons" aria-hidden="true" style="font-size:14px">warning_amber</span> ' +
                        esc(w) +
                    '</div>';
                });
            }

            setById('dbHealthContainer', html);

            // Update DB health badge
            var badge = document.getElementById('dbHealthBadge');
            if (badge) {
                var issues = (db.slow_queries || 0) + (db.deadlocks_recent || 0) + (db.warnings ? db.warnings.length : 0);
                if (issues > 0) {
                    badge.textContent = issues + ' هشدار';
                    badge.style.setProperty('--w-badge-color', 'var(--down)');
                    badge.style.setProperty('--w-badge-bg', 'var(--down-bg)');
                } else {
                    badge.textContent = 'سالم';
                    badge.style.setProperty('--w-badge-color', 'var(--up)');
                    badge.style.setProperty('--w-badge-bg', 'var(--up-bg)');
                }
            }
        }

        function renderUptime(uptime) {
            var el = document.getElementById('dashUptime');
            if (el && uptime) {
                el.textContent = uptime;
            }
        }

        loadSystemStatus(false);

        var refreshSystemStatusBtn = document.getElementById('refreshSystemStatus');
        if (refreshSystemStatusBtn) {
            refreshSystemStatusBtn.addEventListener('click', function () {
                loadSystemStatus(true);
            });
        }

        setInterval(function () { loadSystemStatus(false); }, 60000);
    });
})();
