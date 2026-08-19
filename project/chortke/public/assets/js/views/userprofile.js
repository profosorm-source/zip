(function () {
  'use strict';

  const root = document.getElementById('accountProfileRoot');
  if (!root) return;

  const config = {
    uploadUrl: root.dataset.avatarUploadUrl || '',
    deleteUrl: root.dataset.avatarDeleteUrl || '',
    csrf: root.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
  };

  let notifier = null;
  function notify(type, message) {
    if (!notifier && typeof window.Notyf !== 'undefined') {
      notifier = new window.Notyf({ duration: 4500, position: { x: 'left', y: 'top' }, dismissible: true });
    }
    if (notifier && typeof notifier[type] === 'function') return notifier[type](message);
    const toast = document.createElement('div');
    toast.textContent = message;
    Object.assign(toast.style, { position:'fixed', left:'24px', top:'78px', zIndex:'10050', maxWidth:'360px', padding:'12px 16px', borderRadius:'12px', background:type==='error'?'rgba(246,70,93,.96)':'rgba(30,217,168,.96)', color:'#fff', fontFamily:'Vazirmatn,Tahoma,sans-serif', fontSize:'13px', fontWeight:'800', lineHeight:'1.8', boxShadow:'0 14px 34px rgba(0,0,0,.28)' });
    document.body.appendChild(toast); setTimeout(() => toast.remove(), 4500);
  }

  const input = document.getElementById('avatarInput');
  const loader = document.getElementById('avatarLoader');
  const preview = document.getElementById('avatarPreview');
  const MAX_BYTES = 2 * 1024 * 1024;
  const ALLOWED = ['image/jpeg','image/png','image/jpg','image/gif','image/webp'];

  function startLoader(){ loader?.classList.add('active'); }
  function stopLoader(){ loader?.classList.remove('active'); }

  document.addEventListener('click', function (event) {
    const action = event.target.closest('[data-action]')?.dataset.action;
    if (action === 'trigger-avatar-upload') input?.click();
    if (action === 'delete-avatar') deleteAvatar();
  });

  input?.addEventListener('change', async function (event) {
    const file = event.target.files && event.target.files[0] ? event.target.files[0] : null;
    if (!file) return;
    if (!ALLOWED.includes(file.type)) { notify('error','فرمت فایل مجاز نیست. فقط JPG/PNG/GIF/WEBP'); event.target.value=''; return; }
    if (file.size > MAX_BYTES) { notify('error','حجم تصویر نباید بیشتر از ۲ مگابایت باشد'); event.target.value=''; return; }

    try {
      const reader = new FileReader();
      reader.onload = ev => { if (preview) preview.src = ev.target.result; };
      reader.readAsDataURL(file);
    } catch (_) {}

    startLoader();
    const fd = new FormData(); fd.append('avatar', file);
    try {
      const res = await fetch(config.uploadUrl, { method:'POST', credentials:'same-origin', body:fd, headers:{ 'X-Requested-With':'XMLHttpRequest', 'X-CSRF-TOKEN':config.csrf, Accept:'application/json' } });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.success) throw new Error(data.message || 'آپلود ناموفق بود');
      notify('success', data.message || 'آواتار بروزرسانی شد');
      if (data.avatar_url) {
        const t = Date.now();
        document.querySelectorAll('.user-avatar').forEach(img => { if (img.tagName === 'IMG') img.src = data.avatar_url + '?t=' + t; });
        if (preview) preview.src = data.avatar_url + '?t=' + t;
      }
    } catch (error) {
      notify('error', error.message || 'خطا در ارتباط با سرور');
    } finally {
      stopLoader(); event.target.value = '';
    }
  });

  async function deleteAvatar() {
    let confirmed = true;
    if (window.Swal) {
      const result = await window.Swal.fire({ title:'حذف تصویر پروفایل؟', text:'تصویر پیش‌فرض جایگزین می‌شود.', icon:'warning', showCancelButton:true, confirmButtonText:'بله، حذف شود', cancelButtonText:'انصراف', confirmButtonColor:'#F6465D' });
      confirmed = result.isConfirmed;
    }
    if (!confirmed) return;

    try {
      const res = await fetch(config.deleteUrl, { method:'POST', credentials:'same-origin', headers:{ 'X-Requested-With':'XMLHttpRequest', 'X-CSRF-TOKEN':config.csrf, Accept:'application/json' } });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.success) throw new Error(data.message || 'خطا در حذف آواتار');
      notify('success', data.message || 'تصویر پروفایل حذف شد');
      if (data.avatar_url && preview) preview.src = data.avatar_url + '?t=' + Date.now();
      setTimeout(() => location.reload(), 700);
    } catch (error) { notify('error', error.message || 'خطا در ارتباط با سرور'); }
  }

  document.getElementById('changePasswordForm')?.addEventListener('submit', function (event) {
    const p1 = document.getElementById('newPassword')?.value || '';
    const p2 = document.getElementById('confirmPassword')?.value || '';
    if (p1 !== p2) { event.preventDefault(); notify('error','رمز عبور جدید و تکرار آن یکسان نیستند'); }
  });
})();
