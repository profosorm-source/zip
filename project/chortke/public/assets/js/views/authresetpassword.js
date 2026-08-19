function togglePass(id, btn) {
    const f = document.getElementById(id);
    const icon = btn.querySelector('.material-icons');
    if (f.type === 'password') {
        f.type = 'text';
        icon.textContent = 'visibility_off';
    } else {
        f.type = 'password';
        icon.textContent = 'visibility';
    }
}

// Password strength
document.getElementById('password').addEventListener('input', function() {
    const val = this.value;
    const bar = document.getElementById('strength-bar');
    const lbl = document.getElementById('strength-label');
    let score = 0;
    if (val.length >= 8)  score++;
    if (val.length >= 12) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const levels = [
        {w:'0%',   cls:'bg-danger',  txt:''},
        {w:'25%',  cls:'bg-danger',  txt:'ضعیف'},
        {w:'50%',  cls:'bg-warning', txt:'متوسط'},
        {w:'75%',  cls:'bg-info',    txt:'خوب'},
        {w:'100%', cls:'bg-success', txt:'قوی'},
    ];
    const lvl = levels[Math.min(score, 4)];
    bar.style.width = lvl.w;
    bar.className = 'progress-bar ' + lvl.cls;
    lbl.textContent = lvl.txt;
});