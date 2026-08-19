document.querySelectorAll('.btn-delete-social').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        const name = this.dataset.name;

        Swal.fire({
            title: 'حذف حساب',
            text: `آیا از حذف حساب @${name} مطمئن هستید؟`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'بله، حذف شود',
            cancelButtonText: 'انصراف',
            confirmButtonColor: '#f44336'
        }).then(result => {
            if (result.isConfirmed) {
                fetch(`${(document.currentScript?.dataset.baseUrl || '/social-accounts').replace(/\/$/, '')}/${encodeURIComponent(id)}/delete`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]')?.content || window.csrfToken || '')
                    },
                    body: JSON.stringify({ _csrf_token: (document.querySelector('meta[name="csrf-token"]')?.content || window.csrfToken || '') })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        notyf.success(data.message);
                        document.querySelector(`.social-card[data-id="${id}"]`).remove();
                    } else {
                        notyf.error(data.message);
                    }
                })
                .catch(() => notyf.error('خطا در ارتباط'));
            }
        });
    });
});