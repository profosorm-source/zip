document.addEventListener('DOMContentLoaded', function() {
    const rView = document.getElementById('rangeViews');
    const rCpm = document.getElementById('rangeCpm');
    const vView = document.getElementById('viewVal');
    const vCpm = document.getElementById('cpmVal');
    const out = document.getElementById('finalTotal');
    
    // درصد سهم پیش‌فرض
    const userSharePct = 0.60; 

    function updateCalc() {
        const v = parseInt(rView.value);
        const c = parseFloat(rCpm.value);
        
        vView.textContent = v.toLocaleString('fa-IR');
        vCpm.textContent = c.toLocaleString('fa-IR') + ' دلار';
        
        // (بازدید / ۱۰۰۰) * CPM * سهم کاربر
        const total = (v / 1000) * c * userSharePct;
        
        // انیمیشن تغییر عدد
        animateValue(out, parseFloat(out.innerText.replace(/,/g,'')) || 0, total, 300);
    }

    function animateValue(obj, start, end, duration) {
        let startTimestamp = null;
        const step = (timestamp) => {
            if (!startTimestamp) startTimestamp = timestamp;
            const progress = Math.min((timestamp - startTimestamp) / duration, 1);
            const val = (progress * (end - start) + start).toFixed(2);
            obj.innerHTML = Number(val).toLocaleString('fa-IR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            if (progress < 1) {
                window.requestAnimationFrame(step);
            }
        };
        window.requestAnimationFrame(step);
    }

    rView.addEventListener('input', updateCalc);
    rCpm.addEventListener('input', updateCalc);
    updateCalc(); // Initial load
});