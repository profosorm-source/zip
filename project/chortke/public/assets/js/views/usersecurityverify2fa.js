// BUG FIX 12: Vanilla JS - بدون jQuery
const notyf = new Notyf({ duration: 4000, position: { x: 'center', y: 'top' } });
const verify2faScript = document.currentScript;
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || window.csrfToken || '';
const verifyUrl = verify2faScript?.dataset.verifyUrl || '/verify-2fa';
const dashboardUrl = verify2faScript?.dataset.dashboardUrl || '/dashboard';

// ─── OTP Input handling ──────────────────────────────────────
const digits   = document.querySelectorAll('.otp-digit');
const hidden   = document.getElementById('code-hidden');
const submitBtn = document.getElementById('submit-btn');

digits.forEach((input, idx) => {
    input.addEventListener('keydown', e => {
        if (e.key === 'Backspace' && !input.value && idx > 0) {
            digits[idx - 1].focus();
            digits[idx - 1].value = '';
            digits[idx - 1].classList.remove('filled');
        }
    });

    input.addEventListener('input', e => {
        // فقط اعداد
        input.value = input.value.replace(/\D/g, '').slice(-1);
        if (input.value) {
            input.classList.add('filled');
            if (idx < 5) digits[idx + 1].focus();
        } else {
            input.classList.remove('filled');
        }
        syncHidden();
    });

    // پیست کردن کل کد
    input.addEventListener('paste', e => {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
        pasted.split('').forEach((ch, i) => {
            if (digits[i]) {
                digits[i].value = ch;
                digits[i].classList.add('filled');
            }
        });
        if (pasted.length === 6) {
            digits[5].focus();
            syncHidden();
            submitBtn.click();
        }
    });
});

function syncHidden() {
    const val = Array.from(digits).map(d => d.value).join('');
    hidden.value = val;
    submitBtn.disabled = val.length !== 6;
}

// ─── Submit ──────────────────────────────────────────────────
document.getElementById('verify-form').addEventListener('submit', async e => {
    e.preventDefault();
    await submitCode(hidden.value);
});

async function submitCode(code) {
    const btn = submitBtn;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> در حال تأیید...';
    document.getElementById('error-msg').classList.add('d-none');

    try {
        // BUG FIX 7: URL صحیح /verify-2fa
        const res = await fetch(verifyUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: 'code=' + encodeURIComponent(code)
                + '&_token=' + encodeURIComponent(csrfToken),
        });

        const data = await res.json();

        if (data.success) {
            notyf.success(data.message || 'ورود موفق');
            // BUG FIX 10: فیلد redirect درست است (نه data.redirect)
            setTimeout(() => {
                window.location.href = data.redirect || dashboardUrl;
            }, 800);
        } else {
            const errEl = document.getElementById('error-msg');
            errEl.textContent = data.message || 'کد نامعتبر است';
            errEl.classList.remove('d-none');
            // پاک کردن inputs
            digits.forEach(d => { d.value = ''; d.classList.remove('filled'); });
            hidden.value = '';
            digits[0].focus();
            btn.disabled = true;
            btn.innerHTML = '<span class="material-icons align-middle">check_circle</span> تأیید ورود';
        }
    } catch (err) {
        notyf.error('خطای شبکه. لطفاً دوباره امتحان کنید.');
        btn.disabled = false;
        btn.innerHTML = '<span class="material-icons align-middle">check_circle</span> تأیید ورود';
    }
}

// ─── Recovery code ───────────────────────────────────────────
async function useRecovery() {
    const code = document.getElementById('recovery-code').value.trim().toUpperCase();
    if (code.length < 6) {
        notyf.error('کد بازیابی نامعتبر است');
        return;
    }
    await submitCode(code);
}
