document.getElementById('peerTransferForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('submitTransferBtn');
    if (!confirm('آیا از انتقال این مبلغ به گیرنده مشخص‌شده کاملاً اطمینان دارید؟')) return;
    
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> درحال پردازش اتمیک...';
    
    const formData = new FormData(this);
    fetch(this.action, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            notyf.success(d.message || 'انتقال با موفقیت انجام شد.');
            setTimeout(() => location.href = '/wallet/history', 1500);
        } else {
            notyf.error(d.message || 'بروز خطا در انتقال اعتبار.');
            btn.disabled = false;
            btn.innerHTML = '<span class="material-icons align-middle me-2">send</span> تأیید و انتقال آنی اعتبار';
        }
    })
    .catch(() => {
        notyf.error('خطای ارتباط با سرور.');
        btn.disabled = false;
        btn.innerHTML = '<span class="material-icons align-middle me-2">send</span> تأیید و انتقال آنی اعتبار';
    });
});