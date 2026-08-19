document.getElementById('createEscrowForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('submitEscrowBtn');
    if (!confirm('آیا از قفل کردن این مبلغ در صندوق امانات برای معامله با فروشنده مشخص‌شده اطمینان دارید؟')) return;
    
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> درحال قفل‌گذاری اتمیک...';
    
    const formData = new FormData(this);
    fetch(this.action, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            notyf.success(d.message || 'وجه با موفقیت در اسکرو قفل شد.');
            setTimeout(() => location.href = '/wallet/escrows', 1500);
        } else {
            notyf.error(d.message || 'خطا در ایجاد معامله امن.');
            btn.disabled = false;
            btn.innerHTML = '<span class="material-icons align-middle me-2">lock</span> قفل‌گذاری آنی وجه در صندوق امانات';
        }
    })
    .catch(() => {
        notyf.error('خطای ارتباط با سرور.');
        btn.disabled = false;
        btn.innerHTML = '<span class="material-icons align-middle me-2">lock</span> قفل‌گذاری آنی وجه در صندوق امانات';
    });
});