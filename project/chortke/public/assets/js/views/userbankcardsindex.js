(function () {
  'use strict';
  const script = document.currentScript;
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || window.csrfToken || '';
  const setDefaultTemplate = script?.dataset.setDefaultUrl || '';
  const deleteTemplate = script?.dataset.deleteUrl || '';

  function notify(type, message) {
    if (typeof notyf !== 'undefined' && notyf[type]) notyf[type](message);
    else alert(message);
  }

  function postTo(template, cardId) {
    const url = template.replace('__ID__', encodeURIComponent(cardId));
    const fd = new FormData();
    fd.append('_csrf_token', csrfToken);
    return fetch(url, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    }).then(r => r.json());
  }

  function deleteCard(cardId) {
    postTo(deleteTemplate, cardId)
      .then(d => { d.success ? notify('success', d.message || 'انجام شد') : notify('error', d.message || 'خطا'); if (d.success) setTimeout(() => location.reload(), 1000); })
      .catch(() => notify('error', 'خطا در ارتباط با سرور'));
  }

  document.addEventListener('click', function(e) {
    const target = e.target.closest('[data-action]');
    if (!target) return;
    const action = target.dataset.action;
    const cardId = target.dataset.cardId;
    if (action === 'set-default-card' && cardId) {
      if (!confirm('آیا می‌خواهید این کارت را به عنوان پیش‌فرض تنظیم کنید؟')) return;
      postTo(setDefaultTemplate, cardId)
        .then(d => { d.success ? notify('success', d.message || 'انجام شد') : notify('error', d.message || 'خطا'); if (d.success) setTimeout(() => location.reload(), 1000); })
        .catch(() => notify('error', 'خطا در ارتباط با سرور'));
    }
    if (action === 'confirm-delete-card' && cardId) {
      if (typeof Swal !== 'undefined') {
        Swal.fire({ title: 'حذف کارت بانکی', text: 'آیا از حذف این کارت اطمینان دارید؟', icon: 'warning', showCancelButton: true, confirmButtonColor: '#E53E3E', cancelButtonColor: '#999', confirmButtonText: 'بله، حذف شود', cancelButtonText: 'انصراف' })
          .then(r => { if (r.isConfirmed) deleteCard(cardId); });
      } else if (confirm('آیا از حذف این کارت اطمینان دارید؟')) deleteCard(cardId);
    }
  });
})();
