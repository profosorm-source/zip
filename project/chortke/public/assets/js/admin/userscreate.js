/* admin/users-create.js — ارسال AJAX فرم ایجاد کاربر */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('createUserForm');
        if (!form) return;
        var actionUrl = form.dataset.actionUrl;

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            document.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
            var formData = new FormData(this);
            var data = Object.fromEntries(formData);
            try {
                var response = await fetch(actionUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': data._token || window.csrfToken },
                    body: JSON.stringify(data)
                });
                var result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                    if (result.redirect) setTimeout(function () { window.location.href = result.redirect; }, 1000);
                } else {
                    if (result.errors) {
                        Object.keys(result.errors).forEach(function (field) {
                            var input = document.querySelector('[name="' + field + '"]');
                            if (input) {
                                input.classList.add('is-invalid');
                                if (input.nextElementSibling) input.nextElementSibling.textContent = result.errors[field][0];
                            }
                        });
                    }
                    showToast(result.message || 'خطا در ایجاد کاربر', 'error');
                }
            } catch (error) {
                showToast('خطا در ارتباط با سرور', 'error');
            }
        });
    });
})();
