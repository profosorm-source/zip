/**
 * Declarative Core Asset (Single-Word Naming Standard)
 * Unified Event Delegation Core for Automated AJAX Forms, Confirmation Dialogs, UI Toggles, and Inline Actions.
 */

document.addEventListener('DOMContentLoaded', () => {
    const notyf = typeof Notyf !== 'undefined' ? new Notyf({ duration: 5000, position: { x: 'left', y: 'top' }, dismissible: true }) : null;

    const notify = (msg, type = 'success') => {
        if (notyf) {
            type === 'success' ? notyf.success(msg) : notyf.error(msg);
        } else {
            alert(msg);
        }
    };

    // 1. Declarative Automated AJAX Forms
    document.addEventListener('submit', async (e) => {
        const form = e.target;
        if (!form.matches('[data-ajax="true"]')) return;

        e.preventDefault();

        // Clear previous validation errors
        form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        form.querySelectorAll('.invalid-feedback').forEach(el => el.textContent = '');

        const submitBtn = form.querySelector('[type="submit"]');
        const btnText = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>...';
        }

        const url = form.getAttribute('action') || window.location.href;
        const method = (form.getAttribute('method') || 'POST').toUpperCase();
        const formData = new FormData(form);

        // Convert FormData to JSON if needed or send directly
        const headers = { 'X-Requested-With': 'XMLHttpRequest' };
        const csrfToken = form.querySelector('[name="_csrf_token"]') ? form.querySelector('[name="_csrf_token"]').value : (typeof window.csrfToken !== 'undefined' ? window.csrfToken : '');
        if (csrfToken) {
            headers['X-CSRF-Token'] = csrfToken;
        }

        try {
            const reqOptions = { method, headers };
            if (method !== 'GET' && method !== 'HEAD') {
                if (form.matches('[data-json="true"]')) {
                    headers['Content-Type'] = 'application/json';
                    const jsonObj = {};
                    formData.forEach((v, k) => jsonObj[k] = v);
                    reqOptions.body = JSON.stringify(jsonObj);
                } else {
                    reqOptions.body = formData;
                }
            }

            const response = await fetch(url, reqOptions);
            const resData = await response.json().catch(() => ({ success: response.ok, message: response.statusText }));

            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = btnText;
            }

            if (!response.ok) {
                if (response.status === 422 && resData.errors) {
                    Object.entries(resData.errors).forEach(([field, err]) => {
                        const inputel = form.querySelector(`[name="${field}"]`);
                        if (inputel) {
                            inputel.classList.add('is-invalid');
                            let feedback = inputel.parentNode.querySelector('.invalid-feedback');
                            if (!feedback) {
                                feedback = document.createElement('div');
                                feedback.className = 'invalid-feedback d-block';
                                inputel.parentNode.appendChild(feedback);
                            }
                            feedback.textContent = typeof err === 'string' ? err : err[0];
                        }
                    });
                    notify('خطای اعتبارسنجی اطلاعات', 'error');
                } else {
                    notify(resData.message || 'خطا در اجرای عملیات', 'error');
                }
                return;
            }

            const successMsg = form.getAttribute('data-success') || resData.message || 'عملیات با موفقیت انجام شد';
            notify(successMsg, 'success');

            const redirectUrl = resData.redirect || form.getAttribute('data-redirect');
            if (redirectUrl) {
                setTimeout(() => window.location.href = redirectUrl, 1000);
            } else if (form.matches('[data-reset="true"]')) {
                form.reset();
            }

        } catch (err) {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = btnText;
            }
            notify('خطای شبکه. لطفاً اتصال خود را بررسی کنید.', 'error');
        }
    });

    // 2. Declarative Confirmation Popups & Immediate Actions
    document.addEventListener('click', async (e) => {
        let trigger = e.target.closest('[data-confirm="true"], [data-action]');
        if (!trigger) return;

        if (trigger.matches('[data-confirm="true"]')) {
            e.preventDefault();
            const confirmMsg = trigger.getAttribute('data-msg') || 'آیا از اجرای این عملیات اطمینان دارید؟';
            const url = trigger.getAttribute('href') || trigger.getAttribute('data-url');
            const method = (trigger.getAttribute('data-method') || 'POST').toUpperCase();

            if (typeof Swal !== 'undefined') {
                const res = await Swal.fire({
                    title: 'تأیید عملیات',
                    text: confirmMsg,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'بله، مطمئنم',
                    cancelButtonText: 'لغو',
                    reverseButtons: true
                });

                if (!res.isConfirmed) return;
            } else if (!confirm(confirmMsg)) {
                return;
            }

            if (!url) {
                const formel = trigger.closest('form');
                if (formel) formel.submit();
                return;
            }

            try {
                const headers = { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' };
                if (typeof window.csrfToken !== 'undefined') headers['X-CSRF-Token'] = window.csrfToken;

                const reqOptions = { method, headers };
                const response = await fetch(url, reqOptions);
                const resData = await response.json().catch(() => ({ success: response.ok, message: response.statusText }));

                if (!response.ok) {
                    notify(resData.message || 'خطا در اجرای عملیات', 'error');
                    return;
                }

                notify(resData.message || 'عملیات با موفقیت انجام شد', 'success');

                const redirectUrl = resData.redirect || trigger.getAttribute('data-redirect');
                if (redirectUrl) {
                    setTimeout(() => window.location.href = redirectUrl, 1000);
                } else {
                    setTimeout(() => window.location.reload(), 1000);
                }

            } catch (err) {
                notify('خطای شبکه در اجرای عملیات', 'error');
            }
        } else if (trigger.matches('[data-action]')) {
            e.preventDefault();
            const actionName = trigger.getAttribute('data-action');
            const actionArgs = trigger.getAttribute('data-args');

            if (actionName === 'dismissAlert') {
                const alertel = trigger.closest('.alert');
                if (alertel) alertel.remove();
            } else if (actionName === 'toggleModal') {
                const modSelector = trigger.getAttribute('data-target');
                const modalel = document.querySelector(modSelector);
                if (modalel && typeof bootstrap !== 'undefined') {
                    const bModal = new bootstrap.Modal(modalel);
                    bModal.show();
                }
            }
        }
    });

    // 3. Declarative Form Submit Trigger
    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-submit-form]');
        if (!trigger) return;
        e.preventDefault();
        const formId = trigger.getAttribute('data-submit-form');
        const form = document.getElementById(formId);
        if (form) form.submit();
    });

    // 4. Declarative Navigation Trigger
    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-href]');
        if (!trigger) return;
        e.preventDefault();
        const href = trigger.getAttribute('data-href');
        if (href) window.location.href = href;
    });

    // 5. Stop propagation for nested links inside data-href containers
    document.addEventListener('click', (e) => {
        const link = e.target.closest('.cta-link');
        if (!link) return;
        e.stopPropagation();
    });
});
