const vitrineScript = document.currentScript;
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || window.csrfToken || '';
const ID = vitrineScript?.dataset.id || '';
const BASE_URL = (vitrineScript?.dataset.baseUrl || '/vitrine').replace(/\/$/, '');
const REQUEST_BASE_URL = (vitrineScript?.dataset.requestBaseUrl || '/vitrine/request').replace(/\/$/, '');
const headers = { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF };

function toast(msg, type = 'success') {
  const el = document.createElement('div');
  el.className = `alert alert-${type} position-fixed shadow`;
  el.style.cssText = 'top:20px;left:50%;transform:translateX(-50%);z-index:9999;min-width:280px;text-align:center;';
  el.textContent = msg;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 3500);
}

// ─── علاقه‌مندی ─────────────────────────────────────────────────────────────
function toggleWatch() {
  fetch(`${BASE_URL}/${encodeURIComponent(ID)}/watch`, { method: 'POST', headers })
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        toast(d.message, 'info');
        const btn  = document.getElementById('watchBtn');
        const icon = btn.querySelector('.material-icons');
        const cnt  = document.getElementById('watchCount');
        const isNow = d.watched;
        icon.textContent = isNow ? 'bookmark' : 'bookmark_border';
        btn.className = `btn btn-outline-${isNow ? 'warning' : 'secondary'} btn-sm`;
        cnt.textContent = parseInt(cnt.textContent || 0) + (isNow ? 1 : -1);
      } else toast(d.message, 'danger');
    });
}

// ─── درخواست خرید ────────────────────────────────────────────────────────────
function openRequestModal() {
  new bootstrap.Modal(document.getElementById('requestModal')).show();
}

function submitRequest() {
  const msg   = document.getElementById('requestMessage').value.trim();
  const price = document.getElementById('offerPrice').value;
  if (!msg) { toast('پیام به فروشنده الزامی است.', 'warning'); return; }

  fetch(`${BASE_URL}/${encodeURIComponent(ID)}/request`, {
    method: 'POST', headers,
    body: JSON.stringify({ message: msg, offer_price: price || null })
  }).then(r => r.json()).then(d => {
    bootstrap.Modal.getInstance(document.getElementById('requestModal'))?.hide();
    toast(d.message, d.success ? 'success' : 'danger');
  });
}

// ─── پیشنهاد فروش (آگهی خریدار) ────────────────────────────────────────────
function openContactModal() {
  new bootstrap.Modal(document.getElementById('contactModal')).show();
}

function submitContact() {
  const msg   = document.getElementById('contactMessage').value.trim();
  const price = document.getElementById('contactOfferPrice').value;
  if (!msg || !price) { toast('قیمت و توضیحات الزامی هستند.', 'warning'); return; }

  fetch(`${BASE_URL}/${encodeURIComponent(ID)}/request`, {
    method: 'POST', headers,
    body: JSON.stringify({ message: msg, offer_price: price })
  }).then(r => r.json()).then(d => {
    bootstrap.Modal.getInstance(document.getElementById('contactModal'))?.hide();
    toast(d.message, d.success ? 'success' : 'danger');
  });
}

// ─── پذیرش / رد درخواست ────────────────────────────────────────────────────
function acceptRequest(reqId) {
  if (!confirm('درخواست این خریدار پذیرفته شود؟')) return;
  fetch(`${REQUEST_BASE_URL}/${encodeURIComponent(reqId)}/accept`, { method: 'POST', headers, body: '{}' })
    .then(r => r.json())
    .then(d => { toast(d.message, d.success ? 'success' : 'danger'); if (d.success) setTimeout(() => location.reload(), 1500); });
}

function rejectRequest(reqId) {
  if (!confirm('این درخواست رد شود؟')) return;
  fetch(`${REQUEST_BASE_URL}/${encodeURIComponent(reqId)}/reject`, { method: 'POST', headers, body: '{}' })
    .then(r => r.json())
    .then(d => { toast(d.message, d.success ? 'success' : 'danger'); if (d.success) setTimeout(() => location.reload(), 1500); });
}

// ─── خرید / قفل escrow ──────────────────────────────────────────────────────
function buyListing() {
  if (!confirm('آیا از پرداخت اطمینان دارید؟ مبلغ تا تایید شما در escrow نگه داشته می‌شود.')) return;
  fetch(`${BASE_URL}/${encodeURIComponent(ID)}/buy`, { method: 'POST', headers, body: '{}' })
    .then(r => r.json())
    .then(d => { toast(d.message, d.success ? 'success' : 'danger'); if (d.success) setTimeout(() => location.reload(), 1800); });
}

// ─── تایید دریافت ────────────────────────────────────────────────────────────
function confirmDelivery() {
  if (!confirm('آیا اطلاعات دسترسی را دریافت کرده و از صحت آن مطمئن هستید؟\nبا تایید شما، وجه به فروشنده پرداخت می‌شود.')) return;
  fetch(`${BASE_URL}/${encodeURIComponent(ID)}/confirm`, { method: 'POST', headers, body: '{}' })
    .then(r => r.json())
    .then(d => { toast(d.message, d.success ? 'success' : 'danger'); if (d.success) setTimeout(() => location.reload(), 1800); });
}

// ─── اختلاف ──────────────────────────────────────────────────────────────────
function openDispute() {
  new bootstrap.Modal(document.getElementById('disputeModal')).show();
}

function submitDispute() {
  const reason = document.getElementById('disputeReason').value.trim();
  if (reason.length < 10) { toast('لطفاً دلیل اختلاف را با جزئیات بیشتر بنویسید.', 'warning'); return; }
  fetch(`${BASE_URL}/${encodeURIComponent(ID)}/dispute`, {
    method: 'POST', headers,
    body: JSON.stringify({ reason })
  }).then(r => r.json()).then(d => {
    bootstrap.Modal.getInstance(document.getElementById('disputeModal'))?.hide();
    toast(d.message, d.success ? 'success' : 'danger');
    if (d.success) setTimeout(() => location.reload(), 1800);
  });
}