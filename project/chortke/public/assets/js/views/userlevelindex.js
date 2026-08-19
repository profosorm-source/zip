document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-buy-level').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var slug = this.dataset.slug;
            var name = this.dataset.name;
            var priceLabel = this.dataset.priceLabel;
            var currency = this.dataset.currency;

            Swal.fire({
                title: 'خرید سطح «' + name + '»',
                html: 'مبلغ: <strong>' + priceLabel + '</strong><br><small class="text-muted">مبلغ از کیف پول شما کسر خواهد شد.</small>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#4caf50',
                confirmButtonText: 'تأیید و پرداخت',
                cancelButtonText: 'انصراف'
            }).then(function(result) {
                if (result.isConfirmed) {
                    fetch((document.currentScript?.dataset.purchaseUrl || '/level/purchase'), {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]')?.content || window.csrfToken || '')
                        },
                        body: JSON.stringify({
                            _csrf_token: (document.querySelector('meta[name="csrf-token"]')?.content || window.csrfToken || ''),
                            level: slug,
                            currency: currency
                        })
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        var notyf = new Notyf({duration: 4000, position: {x:'left',y:'top'}});
                        if (data.success) {
                            notyf.success(data.message);
                            setTimeout(function() { location.reload(); }, 1500);
                        } else {
                            notyf.error(data.message);
                        }
                    })
                    .catch(function() {
                        var notyf = new Notyf({duration: 4000, position: {x:'left',y:'top'}});
                        notyf.error('خطا در ارتباط با سرور');
                    });
                }
            });
        });
    });
});