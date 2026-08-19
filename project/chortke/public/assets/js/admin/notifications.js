/* admin/notifications.js — منطق ماژول اعلان‌ها (index + templates) */
(function(){
'use strict';
var root=document.getElementById('notificationsRoot')||document.body;
var NOTIF_BASE=root.dataset.base||'';
var TPL_SAVE=root.dataset.tplSave||'';
var TPL_DELETE=root.dataset.tplDelete||'';

/* ===== index ===== */

// علامت‌گذاری به عنوان خوانده شده
function markAsRead(id) {
    fetch((NOTIF_BASE + '/mark-read/' + id), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': window.csrfToken
        }
    });
}

// علامت‌گذاری همه
document.getElementById('markAllRead')?.addEventListener('click', async function() {
    const btn = this;
    btn.disabled = true;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>در حال پردازش...';

    try {
        const response = await fetch((NOTIF_BASE + '/mark-all-read'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window.csrfToken
            }
        });

        const data = await response.json();

        if (data.success) {
            notyf.success(data.message);
            setTimeout(() => location.reload(), 1000);
        } else {
            notyf.error(data.message);
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    } catch (error) {
        notyf.error('خطا در ارتباط با سرور');
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
});

// حذف نوتیفیکیشن
async function deleteNotification(id) {
    const result = await Swal.fire({
        title: 'حذف اعلان',
        text: 'آیا مطمئنید که می‌خواهید این اعلان را حذف کنید؟',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'بله، حذف شود',
        cancelButtonText: 'انصراف'
    });

    if (!result.isConfirmed) return;

    try {
        const response = await fetch((NOTIF_BASE + '/delete/' + id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window.csrfToken
            }
        });

        const data = await response.json();

        if (data.success) {
            notyf.success(data.message);
            document.querySelector(`[data-id="${id}"]`).closest('.notification-item').remove();
            
            // اگر لیست خالی شد
            if (document.querySelectorAll('.notification-item').length === 0) {
                document.getElementById('notificationList').innerHTML = `
                    <div class="text-center py-5">
                        <i class="material-icons text-muted" style="font-size: 60px;">notifications_none</i>
                        <p class="text-muted mt-3">هیچ اعلانی وجود ندارد</p>
                    </div>
                `;
            }
        } else {
            notyf.error(data.message);
        }
    } catch (error) {
        notyf.error('خطا در ارتباط با سرور');
    }
}

// فیلتر
document.getElementById('applyFilter')?.addEventListener('click', function() {
    const type = document.getElementById('filterType').value;
    const status = document.getElementById('filterStatus').value;
    
    // فیلتر سمت کلاینت (ساده)
    document.querySelectorAll('.notification-item').forEach(item => {
        let show = true;
        
        if (type && !item.querySelector(`.badge:contains('${type}')`)) {
            show = false;
        }
        
        if (status === 'unread' && !item.classList.contains('unread')) {
            show = false;
        } else if (status === 'read' && item.classList.contains('unread')) {
            show = false;
        }
        
        item.style.display = show ? 'block' : 'none';
    });
});

// تابع زمان نسبی
function timeAgo(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const seconds = Math.floor((now - date) / 1000);
    
    if (seconds < 60) return 'همین الان';
    if (seconds < 3600) return Math.floor(seconds / 60) + ' دقیقه پیش';
    if (seconds < 86400) return Math.floor(seconds / 3600) + ' ساعت پیش';
    if (seconds < 2592000) return Math.floor(seconds / 86400) + ' روز پیش';
    if (seconds < 31536000) return Math.floor(seconds / 2592000) + ' ماه پیش';
    return Math.floor(seconds / 31536000) + ' سال پیش';
}

// نمایش زمان نسبی برای همه
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.notification-item small.text-muted').forEach(el => {
        const dateStr = el.textContent.trim();
        if (dateStr && dateStr.match(/\d{4}\/\d{2}\/\d{2}/)) {
            // اگر تاریخ کامل باشد، نگه‌دار
        }
    });
});

/* ===== templates ===== */

document.addEventListener('DOMContentLoaded', function () {
    const notyf   = new Notyf({ duration: 3000, position: { x: 'right', y: 'top' } });
    const modal   = new bootstrap.Modal(document.getElementById('editModal'));
    const CSRF    = window.csrfToken;

    let activeVars = {};
    let lastFocused = null;

    // ── باز کردن modal ویرایش ─────────────────────────────────────────────────
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const key     = this.dataset.key;
            const title   = this.dataset.title;
            const message = this.dataset.message;
            activeVars    = JSON.parse(this.dataset.vars || '{}');

            document.getElementById('editKey').value      = key;
            document.getElementById('modalTemplateKey').textContent = key;
            document.getElementById('editTitle').value    = title;
            document.getElementById('editMessage').value  = message;
            document.getElementById('editError').classList.add('d-none');

            // badge متغیرها
            const badgeContainer = document.getElementById('modalVarBadges');
            badgeContainer.innerHTML = '';
            Object.entries(activeVars).forEach(([v, desc]) => {
                const span = document.createElement('span');
                span.className    = 'badge bg-primary cursor-pointer var-badge';
                span.style.cursor = 'pointer';
                span.innerHTML    = `{{${v}}} <small class="opacity-75">${desc}</small>`;
                span.dataset.var  = `{{${v}}}`;
                badgeContainer.appendChild(span);
            });

            modal.show();
        });
    });

    // ── درج متغیر با کلیک ──────────────────────────────────────────────────
    document.getElementById('modalVarBadges').addEventListener('click', function (e) {
        const badge = e.target.closest('.var-badge');
        if (!badge) return;
        const varText = badge.dataset.var;

        const target = lastFocused || document.getElementById('editMessage');
        const start  = target.selectionStart ?? target.value.length;
        const end    = target.selectionEnd   ?? target.value.length;
        target.value = target.value.slice(0, start) + varText + target.value.slice(end);
        target.focus();
        target.setSelectionRange(start + varText.length, start + varText.length);
    });

    ['editTitle', 'editMessage'].forEach(id => {
        document.getElementById(id)?.addEventListener('focus', function () {
            lastFocused = this;
        });
    });

    // ── ذخیره override ──────────────────────────────────────────────────────
    document.getElementById('saveTemplateBtn').addEventListener('click', async function () {
        const key     = document.getElementById('editKey').value;
        const title   = document.getElementById('editTitle').value.trim();
        const message = document.getElementById('editMessage').value.trim();
        const errEl   = document.getElementById('editError');

        if (!title || !message) {
            errEl.textContent = 'عنوان و متن الزامی هستند.';
            errEl.classList.remove('d-none');
            return;
        }

        try {
            const res  = await fetch(TPL_SAVE, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body:    JSON.stringify({ template_key: key, title, message }),
            });
            const data = await res.json();

            if (data.success) {
                modal.hide();
                notyf.success('قالب ذخیره شد');
                setTimeout(() => location.reload(), 800);
            } else {
                errEl.textContent = data.error || 'خطا در ذخیره‌سازی';
                errEl.classList.remove('d-none');
            }
        } catch {
            errEl.textContent = 'خطا در ارتباط با سرور';
            errEl.classList.remove('d-none');
        }
    });

    // ── بازگشت به پیش‌فرض ────────────────────────────────────────────────────
    document.querySelectorAll('.reset-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const key = this.dataset.key;
            confirmAction({
                type: 'warning',
                title: 'بازگشت به قالب پیش‌فرض',
                text:  `Override قالب «${key}» حذف می‌شود. آیا مطمئن هستید؟`,
                confirmButtonText: 'بله، بازگردان',
                onConfirm: async () => {
                    const res  = await fetch(TPL_DELETE, {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                        body:    JSON.stringify({ template_key: key }),
                    });
                    const data = await res.json();
                    if (data.success) {
                        notyf.success(data.message);
                        setTimeout(() => location.reload(), 800);
                    }
                }
            });
        });
    });
});

})();
