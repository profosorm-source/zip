document.querySelectorAll('.btn-release-escrow')?.forEach(btn => {
    btn.addEventListener('click', function() {
        if (!confirm('آیا از تأیید کامل پروژه/کالا و آزادسازی قطعی وجه به حساب فروشنده اطمینان دارید؟')) return;
        this.disabled = true;
        this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> پردازش...';
        
        const fd = new FormData();
        fd.append('escrow_id', this.dataset.id);
        fd.append('_csrf_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
        
        fetch('/wallet/escrow/release', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                notyf.success(d.message || 'وجه با موفقیت آزاد شد.');
                setTimeout(() => location.reload(), 1500);
            } else {
                notyf.error(d.message || 'خطا در آزادسازی وجه');
                this.disabled = false;
                this.innerHTML = '<span class="material-icons align-middle small">key</span> آزادسازی وجه';
            }
        })
        .catch(() => {
            notyf.error('خطای ارتباط با سرور.');
            this.disabled = false;
            this.innerHTML = '<span class="material-icons align-middle small">key</span> آزادسازی وجه';
        });
    });
});