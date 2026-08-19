document.addEventListener('DOMContentLoaded', function() {
  const select = document.getElementById('platformSelect');
  if (select) {
    select.addEventListener('change', function() {
      const p = this.value;
      const ig = document.getElementById('pricingInstagram');
      const tg = document.getElementById('pricingTelegram');
      if (ig) ig.classList.toggle('d-none', p !== 'instagram');
      if (tg) tg.classList.toggle('d-none', p !== 'telegram');
    });
  }
});
