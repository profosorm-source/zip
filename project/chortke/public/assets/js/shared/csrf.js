/**
 * Shared CSRF Token Setup
 * Exposes window.csrfToken and configures jQuery AJAX when available.
 */
(function () {
    'use strict';

    const meta = document.querySelector('meta[name="csrf-token"]');
    const token = meta ? meta.getAttribute('content') : '';

    if (token) {
        window.csrfToken = token;

        if (typeof $ !== 'undefined' && $.ajaxSetup) {
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': token
                }
            });
        }
    }
})();
