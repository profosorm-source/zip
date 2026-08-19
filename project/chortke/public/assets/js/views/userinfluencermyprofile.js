document.addEventListener('click', function (event) {
  const btn = event.target.closest('[data-action="copy-code"]');
  if (!btn) return;
  const code = btn.getAttribute('data-code') || '';
  if (!code) return;
  const original = btn.innerHTML;
  const done = () => {
    btn.innerHTML = '<span class="material-icons icon-sm">check</span>';
    setTimeout(() => { btn.innerHTML = original; }, 1800);
  };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(code).then(done).catch(() => window.prompt('کد تایید را کپی کنید:', code));
  } else {
    window.prompt('کد تایید را کپی کنید:', code);
    done();
  }
});
