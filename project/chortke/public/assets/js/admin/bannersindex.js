/* public/assets/js/admin/bannersindex.js — CSP-safe admin banner actions */
(function () {
  'use strict';

  window.rejectBanner = function (id) {
    const reason = window.prompt('دلیل رد:');
    if (!reason) return;
    const root = document.getElementById('adminBannersRoot');
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = (root && root.dataset.rejectUrl) ? root.dataset.rejectUrl : '/admin/banners/reject';

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || window.csrfToken || '';
    form.innerHTML = ''
      + '<input type="hidden" name="id" value="' + String(id).replace(/"/g, '&quot;') + '">'
      + '<input type="hidden" name="reason" value="' + String(reason).replace(/"/g, '&quot;') + '">'
      + (csrf ? '<input type="hidden" name="_csrf_token" value="' + csrf.replace(/"/g, '&quot;') + '">' : '');
    document.body.appendChild(form);
    form.submit();
  };
})();
