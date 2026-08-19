/* public/assets/js/admin/logs-notification-settings.js — استخراج‌شده از views/admin/logs/notification-settings.php برای سازگاری با CSP و کش‌پذیری */
(function () {
  'use strict';
function updateChannelForm() {
    const type = document.getElementById('channelType').value;
    document.getElementById('telegramFields').style.display = type === 'telegram' ? 'block' : 'none';
    document.getElementById('emailFields').style.display = type === 'email' ? 'block' : 'none';
    document.getElementById('webhookFields').style.display = type === 'webhook' ? 'block' : 'none';
}

function testChannel(channelId) {
    if (!confirm('آیا می‌خواهید یک پیام تست ارسال شود؟')) return;
    
    fetch('/admin/logs/test-channel', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'channel_id=' + channelId
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
    })
    .catch(err => {
        alert('خطا در ارسال: ' + err.message);
    });
}

function toggleRule(ruleId, isActive) {
    fetch('/admin/logs/toggle-rule', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'rule_id=' + ruleId + '&is_active=' + (isActive ? 1 : 0)
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            alert('خطا در تغییر وضعیت');
        }
    });
}
})();
