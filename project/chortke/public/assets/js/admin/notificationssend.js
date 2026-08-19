/* public/assets/js/admin/notifications-send.js — استخراج‌شده از views/admin/notifications/send.php برای سازگاری با CSP و کش‌پذیری */
(function () {
  'use strict';
document.addEventListener('DOMContentLoaded', function () {
    const notyf = new Notyf({ duration: 3000, position: { x: 'right', y: 'top' } });

    // ── target toggle ─────────────────────────────────────────────────────────
    document.querySelectorAll('input[name="target"]').forEach(radio => {
        radio.addEventListener('change', function () {
            document.getElementById('segmentSection').style.display = this.value === 'segment' ? '' : 'none';
            document.getElementById('userSection').style.display    = this.value === 'user'    ? '' : 'none';
        });
    });

    // ── custom segment toggle ─────────────────────────────────────────────────
    document.querySelector('select[name="segment"]')?.addEventListener('change', function () {
        document.getElementById('customFilters').style.display = this.value === 'custom' ? '' : 'none';
    });

    // ── schedule toggle ───────────────────────────────────────────────────────
    document.getElementById('enableSchedule')?.addEventListener('change', function () {
        document.getElementById('scheduleSection').style.display = this.checked ? '' : 'none';
        if (!this.checked) {
            document.querySelector('input[name="scheduled_at"]').value = '';
        }
    });

    // ── char counter ──────────────────────────────────────────────────────────
    document.querySelectorAll('.char-counter').forEach(counter => {
        const targetName = counter.dataset.target;
        const field = document.querySelector(`[name="${targetName}"]`);
        const max   = parseInt(field?.getAttribute('maxlength') || '500');
        if (!field) return;
        field.addEventListener('input', () => {
            counter.textContent = `${field.value.length} / ${max}`;
        });
    });

    // ── پیش‌نمایش ─────────────────────────────────────────────────────────────
    document.getElementById('previewBtn')?.addEventListener('click', function () {
        const title   = document.querySelector('[name="title"]').value;
        const message = document.querySelector('[name="message"]').value;
        const action  = document.querySelector('[name="action_text"]').value;

        const preview = document.getElementById('notifPreview');
        document.getElementById('previewTitle').textContent   = title   || 'عنوان';
        document.getElementById('previewMessage').textContent = message || 'متن پیام';
        const actionEl = document.getElementById('previewAction');
        if (action) {
            actionEl.textContent    = action;
            actionEl.style.display  = '';
        } else {
            actionEl.style.display  = 'none';
        }
        preview.style.display = '';
    });

    // ── ارسال فرم ─────────────────────────────────────────────────────────────
    document.getElementById('sendForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const form = this;

        const scheduledAt = document.querySelector('[name="scheduled_at"]')?.value || '';
        const confirmText = scheduledAt
            ? `اعلان برای زمان ${scheduledAt} زمان‌بندی می‌شود. ادامه می‌دهید؟`
            : 'اعلان فوراً ارسال می‌شود. آیا مطمئن هستید؟';

        confirmAction({
            type:               'warning',
            title:              'تأیید ارسال',
            text:               confirmText,
            confirmButtonText:  'بله، ارسال شود',
            onConfirm: () => {
                form.submit();
            }
        });
    });
});
})();
