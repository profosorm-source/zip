/**
 * User Dashboard Script
 * Charts, daily voting, lottery countdown, tab switching.
 */
(function () {
    'use strict';

    function readBootstrap() {
        var el = document.getElementById('user-dashboard-data');
        if (!el) return {};
        try {
            return JSON.parse(el.textContent || '{}');
        } catch (e) {
            console.error('User dashboard bootstrap parse error:', e);
            return {};
        }
    }

    var cfg = readBootstrap();

    function isDark() {
        return document.documentElement.getAttribute('data-theme') === 'dark';
    }

    function gc() {
        return isDark() ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.05)';
    }

    function tc() {
        return isDark() ? '#848E9C' : '#9A9AB0';
    }

    function grad(ctx, a, b) {
        var g = ctx.createLinearGradient(0, 0, 0, 250);
        g.addColorStop(0, a);
        g.addColorStop(1, b);
        return g;
    }

    Chart.defaults.font.family = "'Vazirmatn',Tahoma,sans-serif";

    document.addEventListener('DOMContentLoaded', function () {
        renderCharts();
        initTabs();
        initVoting();
        initLotteryCountdown();
    });

    function renderCharts() {
        var el, cx;

        el = document.getElementById('cInc');
        if (el) {
            cx = el.getContext('2d');
            new Chart(el, {
                type: 'line',
                data: {
                    labels: cfg.chartLabels || [],
                    datasets: [{
                        label: 'درآمد',
                        data: cfg.chartData || [],
                        borderColor: '#C99A00',
                        backgroundColor: grad(cx, 'rgba(201,154,0,.35)', 'rgba(201,154,0,.01)'),
                        borderWidth: 2,
                        tension: 0.42,
                        fill: true,
                        pointRadius: 0,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#C99A00',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: { top: 10, right: 6, bottom: -5, left: 4 } },
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            rtl: true,
                            backgroundColor: isDark() ? '#2B3139' : '#1A1A2E',
                            titleColor: '#C99A00',
                            bodyColor: '#fff',
                            padding: 8,
                            cornerRadius: 8,
                            callbacks: {
                                label: function (c) {
                                    return ' ' + c.parsed.y.toLocaleString() + ' تومان';
                                }
                            }
                        }
                    },
                    scales: {
                        x: { grid: { color: gc() }, ticks: { font: { size: 9 }, color: tc() } },
                        y: {
                            beginAtZero: true,
                            grid: { color: gc() },
                            ticks: {
                                callback: function (v) {
                                    return v >= 1000 ? (v / 1000) + 'K' : v;
                                },
                                font: { size: 9 },
                                color: tc()
                            }
                        }
                    }
                }
            });
        }

        el = document.getElementById('cTsk');
        if (el) {
            new Chart(el, {
                type: 'bar',
                data: {
                    labels: ['انجام‌شده', 'در انتظار', 'رد شده'],
                    datasets: [{
                        data: [cfg.tasksCompleted || 0, cfg.tasksPending || 0, cfg.tasksRejected || 0],
                        backgroundColor: ['#18B95A', '#C99A00', '#E53E3E'],
                        borderRadius: 6,
                        borderSkipped: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: { top: 10, right: 6, bottom: -5, left: 4 } },
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: gc() }, ticks: { color: tc() } },
                        x: { grid: { display: false }, ticks: { color: tc() } }
                    }
                }
            });
        }

        el = document.getElementById('cPlt');
        if (el) {
            new Chart(el, {
                type: 'doughnut',
                data: {
                    labels: cfg.platformLabels || ['اینستاگرام', 'یوتیوب', 'تلگرام'],
                    datasets: [{
                        data: cfg.platformData || [1, 1, 1],
                        backgroundColor: ['#E1306C', '#FF0000', '#0088cc', '#CC0000', '#1DA1F2', '#C99A00'],
                        borderWidth: 0,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: 8 },
                    cutout: '62%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { font: { size: 9 }, padding: 7, boxWidth: 7, color: tc() }
                        }
                    }
                }
            });
        }

        el = document.getElementById('cDep');
        if (el) {
            var net = (cfg.totalDeposits || 0) - (cfg.totalWithdraws || 0);
            new Chart(el, {
                type: 'bar',
                data: {
                    labels: ['واریز', 'برداشت', 'موجودی خالص'],
                    datasets: [{
                        data: [cfg.totalDeposits || 0, cfg.totalWithdraws || 0, net],
                        backgroundColor: ['#18B95A', '#E53E3E', net >= 0 ? '#3B82F6' : '#E53E3E'],
                        borderRadius: 6,
                        borderSkipped: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: { top: 10, right: 6, bottom: -5, left: 4 } },
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: gc() }, ticks: { color: tc() } },
                        x: { grid: { display: false }, ticks: { color: tc() } }
                    }
                }
            });
        }
    }

    function initTabs() {
        document.querySelectorAll('.ctab').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tab = this.getAttribute('data-tab');
                if (!tab) return;
                switchTab(tab);
            });
        });
    }

    window.switchDashboardTab = function (tab) {
        switchTab(tab);
    };

    function switchTab(tab) {
        document.querySelectorAll('.ctab').forEach(function (b) {
            b.classList.remove('on');
        });
        var activeBtn = document.querySelector('.ctab[data-tab="' + tab + '"]');
        if (activeBtn) activeBtn.classList.add('on');

        ['inc', 'tsk', 'plt', 'dep'].forEach(function (t) {
            var e = document.getElementById('ch-' + t);
            if (e) e.classList.toggle('hidden', t !== tab);
        });
    }

    function initVoting() {
        var wrap = document.getElementById('voteButtons');
        if (!wrap) return;

        var inactiveMode = wrap.getAttribute('data-inactive');
        var modal = createVoteModal();
        var pendingNum = null;

        function createVoteModal() {
            var m = document.createElement('div');
            m.id = 'vcm';
            m.innerHTML =
                '<div class="vcm-backdrop"></div>' +
                '<div class="vcm-box">' +
                    '<div class="vcm-icon"><span class="material-icons">how_to_vote</span></div>' +
                    '<div class="vcm-title">تأیید رأی</div>' +
                    '<div class="vcm-body">آیا مطمئنید می‌خواهید به عدد <strong class="vcm-num" id="vcmNum"></strong> رأی بدهید؟</div>' +
                    '<div class="vcm-note">بعد از ثبت، رأی قابل تغییر نیست.</div>' +
                    '<div class="vcm-btns">' +
                        '<button class="vcm-btn-cancel" id="vcmCancel">انصراف</button>' +
                        '<button class="vcm-btn-confirm" id="vcmConfirm"><span class="material-icons">check_circle</span>بله، ثبت شود</button>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(m);

            m.querySelector('.vcm-backdrop').addEventListener('click', closeModal);
            document.getElementById('vcmCancel').addEventListener('click', closeModal);
            document.getElementById('vcmConfirm').addEventListener('click', function () {
                closeModal();
                if (pendingNum !== null) castVote(pendingNum);
            });

            return m;
        }

        function openModal(num) {
            pendingNum = num;
            document.getElementById('vcmNum').textContent = num;
            modal.classList.add('vcm-open');
        }

        function closeModal() {
            modal.classList.remove('vcm-open');
            document.querySelectorAll('.vn').forEach(function (b) { b.classList.remove('sel'); });
            pendingNum = null;
        }

        wrap.addEventListener('click', function (e) {
            var btn = e.target.closest('.vn');
            if (!btn) return;

            var inactive = btn.getAttribute('data-inactive');
            var num = parseInt(btn.getAttribute('data-num'));

            if (inactive === '1') {
                showToast('رأی‌گیری فعالی در جریان نیست', 'warn');
                return;
            }
            if (inactive === '2') {
                showToast('عدد امروز هنوز تعیین نشده است', 'warn');
                return;
            }

            document.querySelectorAll('.vn').forEach(function (b) { b.classList.remove('sel'); });
            btn.classList.add('sel');
            openModal(num);
        });

        function castVote(num) {
            document.querySelectorAll('.vn').forEach(function (b) { b.disabled = true; });
            var msg = document.getElementById('voteMsg');
            if (msg) {
                msg.style.display = 'block';
                msg.textContent = 'در حال ثبت رأی...';
            }

            fetch(cfg.voteUrl || '/user/lottery/vote', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.csrfToken || ''
                },
                body: JSON.stringify({
                    round_id: cfg.roundId || 0,
                    voted_number: num,
                    daily_number_id: cfg.dailyNumberId || 0
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    wrap.innerHTML = '<div class="vote-done-msg"><span class="material-icons">check_circle</span>رأی شما (<strong style="font-size:1.1rem;margin-right:4px">' + num + '</strong>) ثبت شد!</div>';
                    if (msg) msg.style.display = 'none';
                    showToast('رأی شما به عدد ' + num + ' با موفقیت ثبت شد 🎯', 'success');
                } else {
                    if (msg) msg.textContent = data.message || 'خطا در ثبت رأی';
                    document.querySelectorAll('.vn').forEach(function (b) { b.disabled = false; });
                    showToast(data.message || 'خطا در ثبت رأی', 'error');
                }
            })
            .catch(function () {
                if (msg) msg.textContent = 'خطا در اتصال';
                document.querySelectorAll('.vn').forEach(function (b) { b.disabled = false; });
                showToast('خطا در اتصال به سرور', 'error');
            });
        }
    }

    function initLotteryCountdown() {
        if (!cfg.lotteryEnd) return;
        var end = cfg.lotteryEnd;
        var H = document.getElementById('lotH');
        var M = document.getElementById('lotM');
        var S = document.getElementById('lotS');
        var st = document.getElementById('lotSt');
        if (!H || !M || !S) return;

        function pad(n) {
            return String(n).padStart(2, '0');
        }

        function tick() {
            var s = end - Math.floor(Date.now() / 1000);
            if (s <= 0) {
                H.textContent = M.textContent = S.textContent = '00';
                if (st) {
                    st.className = 'lot-st lst-off';
                    st.innerHTML = '<span class="lot-dot"></span>زمان پایان یافت';
                }
                return;
            }
            H.textContent = pad(Math.floor(s % 86400 / 3600));
            M.textContent = pad(Math.floor(s % 3600 / 60));
            S.textContent = pad(s % 60);
        }

        tick();
        setInterval(tick, 1000);
    }

    function showToast(msg, type) {
        var t = document.createElement('div');
        t.className = 'vote-toast vote-toast-' + type;
        t.innerHTML = '<span class="material-icons">' + (type === 'success' ? 'check_circle' : type === 'warn' ? 'info' : 'error') + '</span><span>' + msg + '</span>';
        document.body.appendChild(t);
        t.getBoundingClientRect();
        t.classList.add('vt-show');
        setTimeout(function () {
            t.classList.remove('vt-show');
            setTimeout(function () {
                if (t.parentNode) t.parentNode.removeChild(t);
            }, 400);
        }, 3500);
    }
})();
