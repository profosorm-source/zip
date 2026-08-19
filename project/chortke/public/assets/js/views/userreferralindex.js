const referralScript = document.currentScript;
const referralCsrfToken = document.querySelector('meta[name="csrf-token"]')?.content || window.csrfToken || '';
const referredUsersUrl = referralScript?.dataset.usersUrl || '/referral/referred-users';
const referralCommissionsUrl = referralScript?.dataset.commissionsUrl || '/referral/commissions';
// ── Copy Link ──────────────────────────────────────────────
function copyLink() {
    const input = document.getElementById('referralLink');
    const btn   = document.getElementById('btnCopy');

    navigator.clipboard.writeText(input.value).then(() => {
        btn.innerHTML = '<i class="material-icons">check</i> کپی شد!';
        btn.classList.add('ref-copy-btn--success');
        setTimeout(() => {
            btn.innerHTML = '<i class="material-icons">content_copy</i> کپی لینک';
            btn.classList.remove('ref-copy-btn--success');
        }, 2500);
        notyf.success('لینک دعوت کپی شد!');
    }).catch(() => {
        // fallback برای مرورگرهای قدیمی
        input.select();
        document.execCommand('copy');
        notyf.success('لینک دعوت کپی شد!');
    });
}

// ── Load More Users ────────────────────────────────────────
let usersPage = 1;
function loadMoreUsers(btn) {
    usersPage++;
    btn.disabled = true;
    btn.innerHTML = '<i class="material-icons" style="animation:spin .8s linear infinite">refresh</i> در حال بارگذاری...';

    fetch(`${referredUsersUrl}?page=${usersPage}`, {
        headers: { 'X-CSRF-TOKEN': referralCsrfToken }
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success || !data.users.length) {
            btn.closest('.ref-load-more').remove();
            return;
        }
        const tbody = btn.closest('.ref-section').querySelector('tbody');
        const offset = (usersPage - 1) * 15;
        data.users.forEach((u, i) => {
            const tr = document.createElement('tr');
            const initial = u.full_name ? u.full_name.charAt(0) : 'ک';
            tr.innerHTML = `
                <td class="ref-td-num">${offset + i + 1}</td>
                <td><div class="ref-user-cell"><div class="ref-user-avatar">${initial}</div><span>${u.full_name || '—'}</span></div></td>
                <td class="ref-td-date">${u.joined_at_jalali || '—'}</td>
                <td class="ref-td-earn ref-text-irt">${Number(u.earned_irt||0).toLocaleString()}</td>
                <td class="ref-td-earn ref-text-usdt">${Number(u.earned_usdt||0).toFixed(2)}</td>
                <td><span class="ref-count-chip">${Number(u.commission_count||0).toLocaleString()}</span></td>
            `;
            tbody.appendChild(tr);
        });
        if (usersPage >= data.pages) btn.closest('.ref-load-more').remove();
        else { btn.disabled = false; btn.innerHTML = '<i class="material-icons">expand_more</i> نمایش بیشتر'; }
    })
    .catch(() => { btn.disabled = false; btn.innerHTML = '<i class="material-icons">expand_more</i> نمایش بیشتر'; });
}

// ── Load More Commissions ──────────────────────────────────
let commPage = 1;
function loadMoreCommissions(btn) {
    commPage++;
    btn.disabled = true;
    btn.innerHTML = '<i class="material-icons" style="animation:spin .8s linear infinite">refresh</i> در حال بارگذاری...';

    fetch(`${referralCommissionsUrl}?page=${commPage}`, {
        headers: { 'X-CSRF-TOKEN': referralCsrfToken }
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success || !data.commissions.length) {
            btn.closest('.ref-load-more').remove();
            return;
        }
        const tbody = btn.closest('.ref-section').querySelector('tbody');
        const offset = (commPage - 1) * 15;
        data.commissions.forEach((c, i) => {
            const isPaid = c.status === 'paid';
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="ref-td-num">${offset + i + 1}</td>
                <td class="ref-td-name">${c.referred_name || '—'}</td>
                <td><span class="ref-source-chip">${c.source_label || c.source_type}</span></td>
                <td dir="ltr" class="ref-td-amount">${c.currency==='usdt' ? Number(c.source_amount).toFixed(2) : Number(c.source_amount).toLocaleString()}</td>
                <td class="ref-td-pct">${c.commission_percent}%</td>
                <td dir="ltr" class="ref-td-comm"><strong class="ref-text-earn">${c.currency==='usdt' ? Number(c.commission_amount).toFixed(2) : Number(c.commission_amount).toLocaleString()}</strong></td>
                <td><span class="ref-currency-chip ref-currency-chip--${c.currency}">${c.currency==='usdt'?'USDT':'تومان'}</span></td>
                <td><span class="ref-badge ${c.status_class}">${c.status_label}</span></td>
                <td class="ref-td-date">${c.created_at_jalali || '—'}</td>
            `;
            tbody.appendChild(tr);
        });
        if (commPage >= data.pages) btn.closest('.ref-load-more').remove();
        else { btn.disabled = false; btn.innerHTML = '<i class="material-icons">expand_more</i> نمایش بیشتر'; }
    })
    .catch(() => { btn.disabled = false; btn.innerHTML = '<i class="material-icons">expand_more</i> نمایش بیشتر'; });
}

// spin animation
const style = document.createElement('style');
style.textContent = '@keyframes spin{to{transform:rotate(360deg)}}';
document.head.appendChild(style);