/* admin/cron-index.js — استخراج‌شده از views/admin/cron/index.php (CSP-safe) */
(function(){
'use strict';
var __D=(function(){var el=document.getElementById('cron-index-data');try{return JSON.parse(el.textContent);}catch(e){return [];}})();
window.copyCron = function() {
    const inp = document.getElementById('cronCmd');
    inp.select();
    navigator.clipboard.writeText(inp.value).then(() => notyf.success('کپی شد'));
}

// اجرای همه job ها (دکمه بالا)
window.runCron = function() {
    const btn = document.getElementById('runBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="material-icons align-middle spin">sync</span> در حال اجرا...';

    fetch(__D[0], {
        method: 'POST',
        headers: {'X-CSRF-TOKEN': __D[1]}
    })
    .then(r => r.json())
    .then(data => {
        showOutput(data.results || {});
        notyf.success('اجرا تمام شد');
    })
    .catch(() => notyf.error('خطا در اجرا'))
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<span class="material-icons align-middle">play_arrow</span> اجرای همه';
    });
}

// اجرای تکی یک job
window.runSingle = function(jobName, btn) {
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="material-icons spin" style="font-size:16px;vertical-align:middle">sync</span>';

    fetch(__D[2], {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': __D[3],
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({job: jobName})
    })
    .then(r => r.json())
    .then(data => {
        const result = data.result || {};
        const output = {};
        output[jobName] = result;
        showOutput(output);

        if (data.success) {
            notyf.success(`${jobName} اجرا شد`);
            // آپدیت وضعیت ردیف
            const statusCell = document.querySelector(`.status-${jobName}`);
            if (statusCell) {
                statusCell.innerHTML = '<span class="badge bg-success">فعال</span>';
            }
        } else {
            notyf.error(`خطا: ${result.message || 'نامشخص'}`);
        }
    })
    .catch(() => notyf.error('خطا در اجرا'))
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = orig;
    });
}
window.showOutput = function(results) {
    document.getElementById('cronOutput').classList.remove('d-none');
    let out = '';
    for (const [name, result] of Object.entries(results)) {
        const icon = result.status === 'ok' ? '✓' : result.status === 'error' ? '✗' : '⟳';
        out += `[${icon}] ${name}: ${result.status}`;
        if (result.output) {
            const parts = Object.entries(result.output).map(([k,v]) => `${k}=${v}`);
            if (parts.length) out += ' — ' + parts.join(', ');
        }
        if (result.message) out += ' — ' + result.message;
        out += '\n';
    }
    document.getElementById('cronResult').textContent = out;
    document.getElementById('cronResult').scrollTop = 0;
}

})();
