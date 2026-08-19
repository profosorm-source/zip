(function () {
    'use strict';

    const root = document.getElementById('investmentCreateRoot');
    if (!root) return;

    const config = {
        storeUrl: root.dataset.storeUrl || '',
        redirectUrl: root.dataset.redirectUrl || '/investment',
        csrf: root.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        min: parseFloat(root.dataset.min || '0'),
        max: parseFloat(root.dataset.max || '0'),
        feePercent: parseFloat(root.dataset.fee || '0')
    };

    const state = {
        step: 1,
        amount: 0,
        riskAccepted: false,
        submitting: false
    };

    const stepMeta = {
        1: {
            title: 'تعیین مبلغ سرمایه‌گذاری',
            sub: 'مبلغ را وارد کنید تا پیش‌نمایش کارمزد محاسبه شود.',
            next: 'ادامه'
        },
        2: {
            title: 'پذیرش ریسک بازار',
            sub: 'قبل از ادامه، ریسک‌های سرمایه‌گذاری را تأیید کنید.',
            next: 'ادامه'
        },
        3: {
            title: 'بازبینی و ثبت نهایی',
            sub: 'اطلاعات را بررسی کنید و در صورت صحت، درخواست را ثبت کنید.',
            next: 'تأیید و ثبت سرمایه‌گذاری'
        },
        4: {
            title: 'ثبت موفق',
            sub: 'درخواست شما ثبت شد.',
            next: 'بازگشت به مرکز'
        }
    };

    const amountInput = document.getElementById('amount');
    const amountValue = document.getElementById('amountValue');
    const amountWrap = document.getElementById('amountWrap');
    const amountError = document.getElementById('amountError');
    const amountErrorText = document.getElementById('amountErrorText');
    const riskCheck = document.getElementById('risk_accepted');
    const btnBack = document.getElementById('btnBack');
    const btnNext = document.getElementById('btnNext');
    const submitBtn = document.getElementById('submitBtn');
    const foot = document.getElementById('investFoot');
    const form = document.getElementById('investForm');

    let notifier = null;

    function userSafeMessage(message) {
        const text = String(message || '');
        const lower = text.toLowerCase();
        if (lower.includes('saga transaction failed') || lower.includes('insufficient balance') || lower.includes('wallet frozen')) {
            return 'موجودی کیف پول USDT شما برای ایجاد این پلن کافی نیست. لطفاً ابتدا کیف پول تتر خود را شارژ کنید.';
        }
        return text || 'عملیات انجام نشد. لطفاً دوباره تلاش کنید.';
    }

    function fallbackToast(type, message) {
        const toast = document.createElement('div');
        toast.className = 'inv-js-toast inv-js-toast--' + (type === 'error' ? 'error' : 'success');
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

    function normalizeDigits(value) {
        const fa = '۰۱۲۳۴۵۶۷۸۹';
        const ar = '٠١٢٣٤٥٦٧٨٩';
        return String(value || '')
            .replace(/[۰-۹]/g, function (d) { return String(fa.indexOf(d)); })
            .replace(/[٠-٩]/g, function (d) { return String(ar.indexOf(d)); });
    }

    function sanitizeAmount(value) {
        let raw = normalizeDigits(value).replace(/,/g, '').replace(/\s/g, '').replace(/[^0-9.]/g, '');
        const firstDot = raw.indexOf('.');
        if (firstDot !== -1) {
            raw = raw.slice(0, firstDot + 1) + raw.slice(firstDot + 1).replace(/\./g, '');
        }
        return raw;
    }

    function parseAmount(value) {
        const raw = sanitizeAmount(value);
        const parsed = parseFloat(raw);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function formatRawAmount(raw) {
        raw = sanitizeAmount(raw);
        if (!raw) return '';
        const parts = raw.split('.');
        const intPart = parts[0] ? formatAmount(parseInt(parts[0], 10) || 0, 0) : '0';
        if (parts.length > 1) {
            return intPart + '.' + parts.slice(1).join('').slice(0, 8);
        }
        return intPart;
    }

    function formatAmount(value, maximumFractionDigits) {
        const digits = typeof maximumFractionDigits === 'number' ? maximumFractionDigits : 8;
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 0,
            maximumFractionDigits: digits
        }).format(value || 0);
    }

    function formatMoney(value) {
        return formatAmount(value, 4) + ' USDT';
    }

    function isAmountValid() {
        return state.amount >= config.min && state.amount <= config.max;
    }

    function updateAmountError(showWhenEmpty) {
        if (!amountError || !amountErrorText) return;

        if (state.amount <= 0) {
            amountError.classList.toggle('show', !!showWhenEmpty);
            amountErrorText.textContent = 'لطفاً مبلغ سرمایه‌گذاری را وارد کنید.';
            return;
        }

        if (state.amount < config.min) {
            amountError.classList.add('show');
            amountErrorText.textContent = 'حداقل مبلغ سرمایه‌گذاری ' + formatMoney(config.min) + ' است.';
            return;
        }

        if (state.amount > config.max) {
            amountError.classList.add('show');
            amountErrorText.textContent = 'حداکثر مبلغ سرمایه‌گذاری ' + formatMoney(config.max) + ' است.';
            return;
        }

        amountError.classList.remove('show');
    }

    function updatePreview() {
        const fee = state.amount * (config.feePercent / 100);
        const preview = document.getElementById('feePreview');
        const previewAmount = document.getElementById('previewAmount');
        const previewFee = document.getElementById('previewFee');

        if (preview) preview.classList.toggle('show', state.amount > 0);
        if (previewAmount) previewAmount.textContent = state.amount > 0 ? formatMoney(state.amount) : '—';
        if (previewFee) previewFee.textContent = state.amount > 0 ? formatMoney(fee) : '—';

        const reviewAmount = document.getElementById('reviewAmount');
        const reviewFee = document.getElementById('reviewFee');
        const reviewNet = document.getElementById('reviewNet');
        if (reviewAmount) reviewAmount.textContent = state.amount > 0 ? formatMoney(state.amount) : '—';
        if (reviewFee) reviewFee.textContent = state.amount > 0 ? formatMoney(fee) : '—';
        if (reviewNet) reviewNet.textContent = state.amount > 0 ? formatMoney(state.amount) : '—';
    }

    function updateHeader() {
        const meta = stepMeta[state.step] || stepMeta[1];
        const stepNumber = document.getElementById('investStepNumber');
        const title = document.getElementById('investStepTitle');
        const sub = document.getElementById('investStepSub');

        if (stepNumber) stepNumber.textContent = state.step > 3 ? '۳' : String(state.step);
        if (title) title.textContent = meta.title;
        if (sub) sub.textContent = meta.sub;
    }

    function updateStepper() {
        document.querySelectorAll('.inv-stepper__seg').forEach(function (seg) {
            const idx = parseInt(seg.dataset.seg || '0', 10);
            seg.classList.remove('active', 'done');
            if (idx < state.step || state.step === 4) {
                seg.classList.add('done');
            } else if (idx === state.step) {
                seg.classList.add('active');
            }
        });
    }

    function updateButtons() {
        if (!btnBack || !btnNext || !submitBtn) return;

        btnBack.style.display = state.step > 1 && state.step < 4 ? 'inline-flex' : 'none';
        btnNext.style.display = state.step < 3 ? 'inline-flex' : 'none';
        submitBtn.style.display = state.step === 3 ? 'inline-flex' : 'none';

        const nextLabel = btnNext.querySelector('.inv-btn-label');
        if (nextLabel) nextLabel.textContent = (stepMeta[state.step] || stepMeta[1]).next;

        btnNext.disabled = (state.step === 1 && !isAmountValid()) || (state.step === 2 && !state.riskAccepted) || state.submitting;
        submitBtn.disabled = state.step !== 3 || !isAmountValid() || !state.riskAccepted || state.submitting;

        if (state.step === 4 && foot) {
            foot.style.display = 'none';
        }
    }

    function showStep(step) {
        document.querySelectorAll('.inv-form-step').forEach(function (panel) {
            panel.classList.toggle('current', parseInt(panel.dataset.step || '0', 10) === step);
        });
        state.step = step;
        updateHeader();
        updateStepper();
        updateButtons();
    }

    function setLoading(isLoading) {
        state.submitting = isLoading;
        if (submitBtn) submitBtn.classList.toggle('loading', isLoading);
        if (btnNext) btnNext.disabled = isLoading;
        if (btnBack) btnBack.disabled = isLoading;
        updateButtons();
    }

    amountInput?.addEventListener('focus', function () {
        amountWrap?.classList.add('focused');
    });

    amountInput?.addEventListener('blur', function () {
        amountWrap?.classList.remove('focused');
        updateAmountError(false);
    });

    amountInput?.addEventListener('input', function () {
        const raw = sanitizeAmount(amountInput.value);
        state.amount = parseAmount(raw);
        if (amountValue) amountValue.value = state.amount > 0 ? String(state.amount) : '';

        amountInput.value = formatRawAmount(raw);

        document.querySelectorAll('.inv-quick-chip').forEach(function (chip) {
            chip.classList.remove('active');
        });

        updateAmountError(false);
        updatePreview();
        updateButtons();
    });

    document.querySelectorAll('.inv-quick-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            const value = parseFloat(chip.dataset.amt || '0');
            state.amount = Number.isFinite(value) ? value : 0;
            if (amountInput) amountInput.value = state.amount > 0 ? formatAmount(state.amount, 8) : '';
            if (amountValue) amountValue.value = state.amount > 0 ? String(state.amount) : '';
            document.querySelectorAll('.inv-quick-chip').forEach(function (item) { item.classList.remove('active'); });
            chip.classList.add('active');
            updateAmountError(false);
            updatePreview();
            updateButtons();
        });
    });

    riskCheck?.addEventListener('change', function () {
        state.riskAccepted = !!riskCheck.checked;
        updateButtons();
    });

    btnNext?.addEventListener('click', function () {
        if (state.step === 1) {
            updateAmountError(true);
            if (!isAmountValid()) return;
            showStep(2);
            return;
        }
        if (state.step === 2) {
            if (!state.riskAccepted) {
                notify('error', 'برای ادامه باید هشدار ریسک را تأیید کنید.');
                return;
            }
            updatePreview();
            showStep(3);
        }
    });

    btnBack?.addEventListener('click', function () {
        if (state.step > 1 && state.step < 4) {
            showStep(state.step - 1);
        }
    });

    form?.addEventListener('submit', async function (event) {
        event.preventDefault();
        updateAmountError(true);

        if (!isAmountValid() || !state.riskAccepted || state.submitting) {
            updateButtons();
            return;
        }

        if (!config.storeUrl) {
            notify('error', 'آدرس ثبت سرمایه‌گذاری یافت نشد.');
            return;
        }

        setLoading(true);
        try {
            const idempotencyKey = (window.crypto && typeof window.crypto.randomUUID === 'function')
                ? window.crypto.randomUUID()
                : 'inv-' + Date.now() + '-' + Math.random().toString(16).slice(2);

            const response = await fetch(config.storeUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': config.csrf
                },
                body: JSON.stringify({
                    amount: state.amount,
                    risk_accepted: 1,
                    idempotency_key: idempotencyKey
                })
            });

            const data = await response.json().catch(function () { return {}; });
            if (response.ok && data.success) {
                const successMessage = document.getElementById('successMessage');
                if (successMessage) successMessage.textContent = data.message || 'پلن شما با موفقیت ایجاد شد و تا لحظاتی دیگر به مرکز سرمایه‌گذاری منتقل می‌شوید.';
                notify('success', data.message || 'سرمایه‌گذاری با موفقیت ثبت شد.');
                showStep(4);
                window.setTimeout(function () {
                    window.location.href = config.redirectUrl;
                }, 1300);
            } else {
                notify('error', data.message || 'ثبت سرمایه‌گذاری انجام نشد.');
            }
        } catch (error) {
            notify('error', 'خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.');
        } finally {
            if (state.step !== 4) {
                setLoading(false);
            }
        }
    });

    updatePreview();
    updateHeader();
    updateStepper();
    updateButtons();
})();
