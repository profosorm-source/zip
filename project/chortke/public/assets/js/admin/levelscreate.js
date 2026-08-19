/* public/assets/js/admin/levels-create.js — استخراج‌شده از views/admin/levels/create.php برای سازگاری با CSP و کش‌پذیری */
(function () {
  'use strict';
// پیش‌نمایش زنده آیکون و رنگ
const iconInput = document.querySelector('[name="icon"]');
const colorInput = document.querySelector('[name="color"]');
const nameInput  = document.querySelector('[name="name"]');
const iconPreview = document.getElementById('iconPreview');
const namePreview = document.getElementById('namePreview');

function updatePreview() {
    iconPreview.textContent = iconInput.value || 'workspace_premium';
    iconPreview.style.color = colorInput.value;
    namePreview.textContent = nameInput.value || 'نام سطح';
    namePreview.style.color = colorInput.value;
}

iconInput.addEventListener('input', updatePreview);
colorInput.addEventListener('input', updatePreview);
nameInput.addEventListener('input', updatePreview);

// auto-generate slug از name
nameInput.addEventListener('input', function() {
    const slugInput = document.querySelector('[name="slug"]');
    if (!slugInput.dataset.manual) {
        // تبدیل فارسی به انگلیسی پایه‌ای
        const map = {'الف':'a','ب':'b','پ':'p','ت':'t','ث':'s','ج':'j','چ':'ch','ح':'h','خ':'kh','د':'d','ذ':'z','ر':'r','ز':'z','ژ':'zh','س':'s','ش':'sh','ص':'s','ض':'z','ط':'t','ظ':'z','ع':'a','غ':'gh','ف':'f','ق':'gh','ک':'k','گ':'g','ل':'l','م':'m','ن':'n','و':'v','ه':'h','ی':'y','ا':'a'};
        let slug = this.value.toLowerCase();
        for (const [fa, en] of Object.entries(map)) slug = slug.split(fa).join(en);
        slug = slug.replace(/[^a-z0-9]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
        slugInput.value = slug;
    }
});

document.querySelector('[name="slug"]').addEventListener('input', function() {
    this.dataset.manual = '1';
});

// مقداردهی اولیه پیش‌نمایش
updatePreview();
})();
