/* admin/settings-index.js — استخراج‌شده از views/admin/settings/index.php (CSP-safe) */
(function(){
'use strict';
var __D=(function(){var el=document.getElementById('settings-index-data');try{return JSON.parse(el.textContent);}catch(e){return [];}})();

window.updateSetting = async function(settingId) {
  const el = document.getElementById('setting_' + settingId);
  if (!el) return;
  try {
    const response = await fetch(`/admin/settings/${settingId}/update`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': __D[0] },
      credentials: 'same-origin',
      body: JSON.stringify({ id: settingId, key: el.dataset.key, value: el.value })
    });
    const result = await response.json();
    if (result.success) notyf.success(result.message || 'ذخیره شد');
    else notyf.error(result.message || 'خطا');
  } catch { notyf.error('خطا در ارتباط با سرور'); }
}

async function uploadImage(settingId, input) {
  const file = input.files[0];
  if (!file) return;
  if (!file.type.startsWith('image/')) { notyf.error('لطفاً یک تصویر انتخاب کنید'); return; }
  if (file.size > 2 * 1024 * 1024) { notyf.error('حجم فایل نباید بیشتر از 2MB باشد'); return; }
  const formData = new FormData();
  formData.append('image', file);
  formData.append('setting_id', settingId);
  input.disabled = true;
  try {
    const response = await fetch(__D[1], { method:'POST', headers:{'X-CSRF-TOKEN':__D[2]}, credentials:'same-origin', body:formData });
    const result = await response.json();
    input.disabled = false;
    if (result.success) {
      notyf.success(result.message || 'آپلود شد');
      const previewDiv = document.getElementById('preview_' + settingId);
      previewDiv.className = 'bx-img-upload__preview';
      previewDiv.innerHTML = `<img src="${result.url}" alt="تصویر"><button type="button" class="bx-img-upload__remove" data-click="removeImage" data-args="${settingId}"><i class="material-icons">delete</i></button>`;
      input.value = '';
    } else notyf.error(result.message || 'خطا');
  } catch { input.disabled=false; notyf.error('خطا'); }
}

window.removeImage = async function(settingId) {
  if (!confirm('آیا از حذف این تصویر اطمینان دارید؟')) return;
  try {
    const response = await fetch(__D[3], { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':__D[4]}, credentials:'same-origin', body:JSON.stringify({setting_id:settingId}) });
    const result = await response.json();
    if (result.success) {
      notyf.success(result.message || 'حذف شد');
      const previewDiv = document.getElementById('preview_' + settingId);
      previewDiv.className = 'bx-img-upload__empty';
      previewDiv.innerHTML = '<i class="material-icons">cloud_upload</i><span>آپلود نشده</span>';
    } else notyf.error(result.message || 'خطا');
  } catch { notyf.error('خطا'); }
}

})();
