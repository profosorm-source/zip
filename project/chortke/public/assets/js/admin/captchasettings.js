/* admin/captcha-settings.js — استخراج‌شده از views/admin/captcha/settings.php (CSP-safe) */
(function(){
'use strict';
var __D=(function(){var el=document.getElementById('captcha-settings-data');try{return JSON.parse(el.textContent);}catch(e){return [];}})();

document.getElementById('captchaSettingsForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const settings = {
        captcha_enabled: document.getElementById('captcha_enabled').checked ? '1' : '0',
        captcha_type: document.getElementById('captcha_type').value,
        recaptcha_site_key: document.getElementById('recaptcha_site_key').value,
        recaptcha_secret_key: document.getElementById('recaptcha_secret_key').value,
        recaptcha_v3_threshold: document.getElementById('recaptcha_v3_threshold').value,
        captcha_expire_minutes: document.getElementById('captcha_expire_minutes').value,
        captcha_max_attempts: document.getElementById('captcha_max_attempts').value
    };
    
    // ذخیره هر تنظیم
    const promises = Object.entries(settings).map(([key, value]) => {
        return fetch(__D[0], {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': __D[1]
            },
            body: JSON.stringify({ key: key, value: value })
        });
    });
    
    Promise.all(promises).then(() => {
        notyf.success('تنظیمات ذخیره شد');
    }).catch(() => {
        notyf.error('خطا در ذخیره');
    });
});

})();
