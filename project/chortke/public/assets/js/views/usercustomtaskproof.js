(function () {
  'use strict';

  const root = document.getElementById('customTaskProofRoot');
  const form = document.getElementById('customTaskProofForm');
  if (!root || !form) return;

  const proofType = (root.dataset.proofType || 'text').toLowerCase();
  let notifier = null;

  function notify(type, message) {
    if (!notifier && typeof window.Notyf !== 'undefined') {
      notifier = new window.Notyf({ duration: 4500, position: { x: 'left', y: 'top' }, dismissible: true });
    }
    if (notifier && typeof notifier[type] === 'function') return notifier[type](message);
    if (type === 'error') return alert(message);
    console.log(message);
  }

  function csrfToken() {
    return root.dataset.csrf || window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  }

  function validateByProofType() {
    const proofText = (form.querySelector('[name="proof_text"]')?.value || '').trim();
    const proofUrl = (form.querySelector('[name="proof_url"]')?.value || '').trim();
    const proofCode = (form.querySelector('[name="proof_code"]')?.value || '').trim();
    const proofFile = form.querySelector('[name="proof_file"]')?.files?.[0] || null;

    if (proofType === 'text') {
      if (proofText.length < 10) return 'برای این تسک، متن مدرک حداقل ۱۰ کاراکتر الزامی است.';
    } else if (proofType === 'code') {
      if (proofCode.length < 2) return 'برای این تسک، کد یا شناسه مدرک الزامی است.';
    } else if (proofType === 'url') {
      try {
        const url = new URL(proofUrl);
        if (!['http:', 'https:'].includes(url.protocol)) return 'لینک مدرک باید http یا https باشد.';
      } catch (_) {
        return 'برای این تسک، لینک مدرک معتبر الزامی است.';
      }
    } else if (proofType === 'video') {
      if (!proofUrl && !proofFile) return 'برای مدرک ویدیویی، لینک معتبر یا فایل ویدیو الزامی است.';
      if (proofUrl) {
        try {
          const url = new URL(proofUrl);
          if (!['http:', 'https:'].includes(url.protocol)) return 'لینک ویدیو باید http یا https باشد.';
        } catch (_) { return 'لینک ویدیو معتبر نیست.'; }
      }
      if (proofFile) {
        const ok = /\.(mp4|webm|mov)$/i.test(proofFile.name || '') || /^video\/(mp4|webm|quicktime)$/.test(proofFile.type);
        if (!ok) return 'فرمت ویدیو باید mp4، webm یا mov باشد.';
      }
    } else if (proofType === 'screenshot') {
      if (!proofFile) return 'برای این تسک، فایل اسکرین‌شات الزامی است.';
      if (!/^image\/(jpeg|png|webp)$/.test(proofFile.type)) return 'اسکرین‌شات باید JPG، PNG یا WEBP باشد.';
    } else if (proofType === 'file') {
      if (!proofFile) return 'برای این تسک، فایل مدرک الزامی است.';
      const ok = /^image\/(jpeg|png|webp)$/.test(proofFile.type) || proofFile.type === 'application/pdf' || /\.pdf$/i.test(proofFile.name || '');
      if (!ok) return 'فایل مدرک باید تصویر یا PDF باشد.';
    }

    if (proofFile) {
      const maxSize = proofType === 'video' ? 30 * 1024 * 1024 : 5 * 1024 * 1024;
      if (proofFile.size > maxSize) return proofType === 'video' ? 'حجم ویدیو نباید بیشتر از ۳۰ مگابایت باشد.' : 'حجم فایل مدرک نباید بیشتر از ۵ مگابایت باشد.';
    }
    return null;
  }

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    const error = validateByProofType();
    if (error) {
      notify('error', error);
      return;
    }

    const btn = document.getElementById('customProofSubmitBtn');
    const original = btn ? btn.innerHTML : '';
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<span class="tm-loading-dot"></span> در حال ارسال...';
    }

    try {
      const response = await fetch(root.dataset.submitUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        body: new FormData(form)
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.success) {
        notify('error', data.message || 'ارسال مدرک انجام نشد.');
        return;
      }
      notify('success', data.message || 'مدرک با موفقیت ارسال شد.');
      setTimeout(() => { window.location.href = root.dataset.returnUrl || '/custom-tasks/my-submissions'; }, 700);
    } catch (_) {
      notify('error', 'خطا در ارتباط با سرور.');
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = original;
      }
    }
  });
})();
