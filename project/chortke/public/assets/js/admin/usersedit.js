/* admin/users-edit.js — ارسال AJAX فرم ویرایش کاربر */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('editUserForm');
        if (!form) return;
        var actionUrl = form.dataset.actionUrl;

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            var formData = new FormData(this);
            var data = Object.fromEntries(formData.entries());
            document.querySelectorAll('.bx-input.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
            document.querySelectorAll('.bx-invalid-msg').forEach(function (el) { el.textContent = ''; });
            try {
                var response = await fetch(actionUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': data._token || window.csrfToken },
                    body: JSON.stringify(data)
                });
                var result = await response.json();
                if (result.success) {
                    notyf.success(result.message);
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
                    notyf.error(result.message || 'خطا در به‌روزرسانی');
                }
            } catch (err) { notyf.error('خطا در ارتباط با سرور'); }
        });
    });
})();
