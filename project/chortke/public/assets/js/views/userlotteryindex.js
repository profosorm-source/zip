document.addEventListener('click', function(e) {
    const target = e.target.closest('[data-action]');
    if (!target) return;

    const action = target.dataset.action;

    if (action === 'join-lottery') {
        const roundId = target.dataset.roundId;
        if (!roundId) return;

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'شرکت در قرعه‌کشی',
                text: 'آیا مطمئن هستید؟',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'بله، شرکت می‌کنم',
                cancelButtonText: 'انصراف'
            }).then(result => {
                if (result.isConfirmed) {
                    fetch((document.currentScript?.dataset.joinUrl || '/lottery/join'), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]')?.content || window.csrfToken || '') },
                        body: JSON.stringify({ round_id: roundId })
                    }).then(r => r.json()).then(res => {
                        if (res.success) {
                            Swal.fire('ثبت شد!', res.message + (res.code ? '\nکد شما: ' + res.code : ''), 'success')
                                .then(() => location.reload());
                        } else {
                            if (typeof notyf !== 'undefined') notyf.error(res.message);
                        }
                    });
                }
            });
        } else {
            // Fallback without Swal
            if (confirm('آیا مطمئن هستید که می‌خواهید شرکت کنید؟')) {
                fetch((document.currentScript?.dataset.joinUrl || '/lottery/join'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]')?.content || window.csrfToken || '') },
                    body: JSON.stringify({ round_id: roundId })
                }).then(r => r.json()).then(res => {
                    if (res.success) {
                        alert('ثبت شد! ' + (res.code ? 'کد شما: ' + res.code : ''));
                        location.reload();
                    } else {
                        if (typeof notyf !== 'undefined') notyf.error(res.message);
                    }
                });
            }
        }
    }

    if (action === 'cast-vote') {
        const roundId = target.dataset.roundId;
        const number = target.dataset.number;
        if (!roundId || !number) return;

        fetch((document.currentScript?.dataset.voteUrl || '/lottery/vote'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]')?.content || window.csrfToken || '') },
            body: JSON.stringify({ round_id: roundId, voted_number: number })
        }).then(r => r.json()).then(res => {
            if (res.success) {
                if (typeof notyf !== 'undefined') notyf.success(res.message);
                setTimeout(() => location.reload(), 1000);
            } else {
                if (typeof notyf !== 'undefined') notyf.error(res.message);
            }
        });
    }
});
