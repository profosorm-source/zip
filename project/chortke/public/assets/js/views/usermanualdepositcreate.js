function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        notyf.success('کپی شد!');
    }).catch(err => {
        notyf.error('خطا در کپی کردن');
    });
}

// اعتبارسنجی فایل
document.getElementById('receipt_image')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        // بررسی حجم (2MB)
        if (file.size > 2 * 1024 * 1024) {
            notyf.error('حجم فایل نباید بیشتر از 2 مگابایت باشد');
            e.target.value = '';
            return;
        }
        
        // بررسی فرمت
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
        if (!allowedTypes.includes(file.type)) {
            notyf.error('فقط فرمت JPG و PNG مجاز است');
            e.target.value = '';
            return;
        }
    }
});

// ✅ Initialize Security Fields
(function() {
    // Generate Idempotency Key (once per page load)
    if (!document.getElementById('idempotencyKey').value) {
        document.getElementById('idempotencyKey').value = generateIdempotencyKey();
    }
    
    // Generate Device Fingerprint
    document.getElementById('deviceFingerprint').value = generateDeviceFingerprint();
    
    // Set timestamp on form submit
    const form = document.getElementById('depositForm');
    form?.addEventListener('submit', function() {
        document.getElementById('requestTimestamp').value = Date.now();
    });
    
    console.log('🔐 Security initialized for manual deposit');
})();

/**
 * Generate unique Idempotency Key
 */
function generateIdempotencyKey() {
    const now = new Date();
    const timestamp = now.getFullYear() +
        String(now.getMonth() + 1).padStart(2, '0') +
        String(now.getDate()).padStart(2, '0') + '_' +
        String(now.getHours()).padStart(2, '0') +
        String(now.getMinutes()).padStart(2, '0') +
        String(now.getSeconds()).padStart(2, '0');
    
    const random = Math.random().toString(36).substring(2, 15);
    
    return `MDP_${timestamp}_${random}`;
}

/**
 * Generate Device Fingerprint
 */
function generateDeviceFingerprint() {
    const components = [
        navigator.userAgent,
        navigator.language || navigator.userLanguage,
        screen.width + 'x' + screen.height + 'x' + screen.colorDepth,
        new Date().getTimezoneOffset(),
        navigator.hardwareConcurrency || 'unknown',
        navigator.deviceMemory || 'unknown'
    ];
    
    const str = components.join('|');
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        const char = str.charCodeAt(i);
        hash = ((hash << 5) - hash) + char;
        hash = hash & hash;
    }
    
    return Math.abs(hash).toString(16).padStart(16, '0').substring(0, 16);
}
// Data-action delegation for copy buttons
document.addEventListener('click', function(e) {
    const target = e.target.closest('[data-action="copy-to-clipboard"]');
    if (target) {
        const text = target.dataset.text;
        if (text) {
            copyToClipboard(text);
        }
    }
});
