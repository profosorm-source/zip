(function () {
  'use strict';

  const fileInput = document.getElementById('verificationImage');
  const previewBox = document.getElementById('kycPreviewBox');
  const previewImg = document.getElementById('previewImg');
  const submitBtn = document.getElementById('submitBtn');
  const confirmCheck = document.getElementById('confirmCheck');
  const ncInput = document.getElementById('national_code');
  let notifier = null;

  function notify(type, message) {
    if (!notifier && typeof window.Notyf !== 'undefined') {
      notifier = new window.Notyf({ duration: 4500, position: { x: 'left', y: 'top' }, dismissible: true });
    }
    if (notifier && typeof notifier[type] === 'function') return notifier[type](message);
    alert(message);
  }

  function updateSubmitBtn() {
    const hasFile = fileInput?.files?.length > 0;
    const hasConfirm = !!confirmCheck?.checked;
    const hasNc = (ncInput?.value || '').length === 10;
    if (submitBtn) submitBtn.disabled = !(hasFile && hasConfirm && hasNc);
  }

  ncInput?.addEventListener('input', function () {
    this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10);
    updateSubmitBtn();
  });

  fileInput?.addEventListener('change', function () {
    const file = this.files && this.files[0] ? this.files[0] : null;
    if (!file) return updateSubmitBtn();
    if (file.size > 5 * 1024 * 1024) { notify('error', 'حجم فایل نباید بیشتر از ۵ مگابایت باشد'); this.value = ''; return updateSubmitBtn(); }
    if (!['image/jpeg', 'image/jpg', 'image/png'].includes(file.type)) { notify('error', 'فقط فرمت JPG و PNG مجاز است'); this.value = ''; return updateSubmitBtn(); }
    const reader = new FileReader();
    reader.onload = e => {
      if (previewImg) previewImg.src = e.target.result;
      if (previewBox) previewBox.style.display = 'flex';
    };
    reader.readAsDataURL(file);
    updateSubmitBtn();
  });

  confirmCheck?.addEventListener('change', updateSubmitBtn);

  document.getElementById('kycForm')?.addEventListener('submit', function () {
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<i class="material-icons" style="animation:spin .8s linear infinite">refresh</i> در حال ارسال...';
    }
  });

  const style = document.createElement('style');
  style.textContent = '@keyframes spin{to{transform:rotate(360deg)}}';
  document.head.appendChild(style);
  updateSubmitBtn();
})();
