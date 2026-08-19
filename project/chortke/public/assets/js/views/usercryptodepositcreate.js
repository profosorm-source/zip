const cryptoScript = document.querySelector('script[src*="usercryptodepositcreate.js"]');
const cryptoConfig = cryptoScript ? cryptoScript.dataset : {};
let networkConfig = {};
try { networkConfig = cryptoConfig.networks ? JSON.parse(cryptoConfig.networks) : {}; } catch (_) { networkConfig = {}; }
let selectedNetwork = null;

async function createIntent(network) {
    const requested = document.getElementById('requested_amount');
    const form = document.getElementById('cryptoDepositForm');
    const amount = requested ? requested.value.trim() : '';
    if (!amount || Number(amount) <= 0) {
        if (typeof notyf !== 'undefined') notyf.error('ابتدا مبلغ درخواستی را وارد کنید');
        return null;
    }
    const csrf = form ? form.querySelector('input[name="_csrf_token"]') : null;
    const body = new URLSearchParams({ network, requested_amount: amount });
    if (csrf && csrf.value) body.set('_csrf_token', csrf.value);
    try {
        const response = await fetch(cryptoConfig.intentUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body: body.toString(),
        });
        const result = await response.json();
        if (!result.success) {
            if (typeof notyf !== 'undefined') notyf.error(result.message || 'ایجاد درخواست واریز انجام نشد');
            return null;
        }
        return result;
    } catch (_) {
        if (typeof notyf !== 'undefined') notyf.error('ارتباط با سرور برای ایجاد درخواست برقرار نشد');
        return null;
    }
}

async function selectNetwork(network) {
    const intent = await createIntent(network.toUpperCase());
    if (!intent) return;
    selectedNetwork = network;
    const selectedInput = document.getElementById('selected_network');
    const intentInput = document.getElementById('intent_id');
    const exactAmount = document.getElementById('amount');
    if (selectedInput) selectedInput.value = network.toUpperCase();
    if (intentInput) intentInput.value = intent.intent_id || '';
    if (exactAmount) exactAmount.value = intent.expected_amount || '';

    const info = document.getElementById('intent_info');
    if (info) {
        info.textContent = `مبلغ دقیق قابل پرداخت: ${intent.expected_amount} USDT — مهلت ثبت تراکنش: ${intent.expires_at}`;
        info.classList.remove('d-none');
    }

    Object.keys(networkConfig).forEach(key => document.getElementById(key + '_wallet')?.style.setProperty('display', 'none'));
    const formEl = document.getElementById('deposit_form');
    if (formEl) formEl.style.display = 'none';

    const walletCard = document.getElementById(network + '_wallet');
    if (walletCard) {
        walletCard.style.display = 'block';
        const qrDiv = document.getElementById('qr_' + network);
        if (qrDiv) {
            qrDiv.innerHTML = '';
            const address = networkConfig[network] ? (networkConfig[network].address || '') : '';
            if (typeof QRCode !== 'undefined') new QRCode(qrDiv, { text: address, width: 200, height: 200, colorDark: '#000000', colorLight: '#ffffff' });
        }
        if (formEl) formEl.style.display = 'block';
    }
    setTimeout(() => formEl?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 300);
}

function copyToClipboard(text) { navigator.clipboard.writeText(text).then(() => { if (typeof notyf !== 'undefined') notyf.success('آدرس کپی شد!'); }); }
function resetForm() { selectedNetwork = null; document.getElementById('intent_id').value = ''; Object.keys(networkConfig).forEach(key => document.getElementById(key + '_wallet')?.style.setProperty('display', 'none')); document.getElementById('deposit_form')?.style.setProperty('display', 'none'); }

document.addEventListener('click', function(e) {
    const target = e.target.closest('[data-action]'); if (!target) return;
    if (target.dataset.action === 'select-network' && target.dataset.network) { const radio = document.getElementById('network_' + target.dataset.network); if (radio) radio.checked = true; selectNetwork(target.dataset.network); }
    if (target.dataset.action === 'copy-to-clipboard' && target.dataset.text) copyToClipboard(target.dataset.text);
    if (target.dataset.action === 'reset-crypto-form') resetForm();
});

document.getElementById('cryptoDepositForm')?.addEventListener('submit', function(e) {
    if (!selectedNetwork || !document.getElementById('intent_id')?.value) { e.preventDefault(); if (typeof notyf !== 'undefined') notyf.error('ابتدا درخواست واریز و مبلغ دقیق را ایجاد کنید'); }
});
