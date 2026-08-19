(function () {
  'use strict';
  const root = document.getElementById('supportTicketShowRoot');
  if (!root) return;
  const csrf = root.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  let notifier = null;
  function notify(type, message) {
    if (!notifier && typeof window.Notyf !== 'undefined') notifier = new window.Notyf({ duration:4500, position:{x:'left',y:'top'}, dismissible:true });
    if (notifier && typeof notifier[type] === 'function') return notifier[type](message);
    console[type === 'error' ? 'error' : 'log'](message);
  }
  document.getElementById('replyFiles')?.addEventListener('change', function () {
    const el = document.getElementById('fileNames');
    if (!el) return;
    el.innerHTML = '';
    Array.from(this.files || []).forEach(file => {
      const chip = document.createElement('span'); chip.className = 'sup-file-chip'; chip.innerHTML = '<i class="material-icons">insert_drive_file</i>' + file.name; el.appendChild(chip);
    });
  });
  document.getElementById('replyForm')?.addEventListener('submit', async function (event) {
    event.preventDefault();
    const btn = document.getElementById('sendBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="material-icons" style="animation:spin .8s linear infinite">refresh</i> در حال ارسال...'; }
    try {
      const response = await fetch(root.dataset.replyUrl, { method:'POST', credentials:'same-origin', headers:{ 'X-CSRF-TOKEN':csrf, Accept:'application/json' }, body:new FormData(this) });
      const data = await response.json().catch(() => ({}));
      if (response.ok && data.success) { notify('success', data.message || 'پاسخ ارسال شد'); setTimeout(() => location.reload(), 900); }
      else { notify('error', data.message || 'ارسال پاسخ انجام نشد'); if (btn) { btn.disabled=false; btn.innerHTML='<i class="material-icons">send</i> ارسال پاسخ'; } }
    } catch (_) { notify('error','خطا در ارسال پاسخ'); if (btn) { btn.disabled=false; btn.innerHTML='<i class="material-icons">send</i> ارسال پاسخ'; } }
  });
  document.addEventListener('click', async function (event) {
    const target = event.target.closest('[data-action="close-ticket"]');
    if (!target) return;
    const id = target.dataset.ticketId;
    let confirmed = true;
    if (window.Swal) {
      const result = await window.Swal.fire({ title:'بستن تیکت', text:'پس از بستن امکان ارسال پاسخ وجود نخواهد داشت.', icon:'question', showCancelButton:true, confirmButtonText:'بله، ببند', cancelButtonText:'انصراف', confirmButtonColor:'#F6465D' });
      confirmed = result.isConfirmed;
    }
    if (!confirmed) return;
    try {
      const res = await fetch(root.dataset.closeUrl, { method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':csrf }, body:JSON.stringify({ id }) });
      const data = await res.json().catch(() => ({}));
      if (res.ok && data.success) { notify('success', data.message || 'تیکت بسته شد'); setTimeout(() => location.reload(), 900); }
      else notify('error', data.message || 'بستن تیکت انجام نشد');
    } catch (_) { notify('error','خطا در ارتباط با سرور'); }
  });
  window.addEventListener('load', () => { const c = document.getElementById('messagesContainer'); if (c) c.scrollTop = c.scrollHeight; });
  const style = document.createElement('style'); style.textContent='@keyframes spin{to{transform:rotate(360deg)}}'; document.head.appendChild(style);
})();
