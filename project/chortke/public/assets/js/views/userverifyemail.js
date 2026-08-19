// فقط حروف و اعداد — uppercase
document.getElementById('codeField').addEventListener('input', function() {
    this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
});

// کانتدون ۶۰ ثانیه برای ارسال مجدد
(function() {
    const btn = document.getElementById('resendBtn');
    let seconds = 0;
    const stored = sessionStorage.getItem('resend_ts');
    if (stored) {
        const elapsed = Math.floor((Date.now() - parseInt(stored)) / 1000);
        seconds = Math.max(0, 60 - elapsed);
    }
    if (seconds > 0) startCountdown(seconds);

    document.getElementById('resendForm').addEventListener('submit', function() {
        sessionStorage.setItem('resend_ts', Date.now().toString());
        startCountdown(60);
    });

    function startCountdown(sec) {
        btn.disabled = true;
        let s = sec;
        btn.textContent = 'ارسال مجدد (' + s + 'ث)';
        const t = setInterval(function() {
            s--;
            if (s <= 0) {
                clearInterval(t);
                btn.disabled = false;
                btn.textContent = 'ارسال مجدد کد';
            } else {
                btn.textContent = 'ارسال مجدد (' + s + 'ث)';
            }
        }, 1000);
    }
})();
