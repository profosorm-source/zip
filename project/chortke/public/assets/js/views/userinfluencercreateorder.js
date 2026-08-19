document.addEventListener('click', function (event) {
  const card = event.target.closest('[data-action="select-order-type"]');
  if (!card) return;
  document.querySelectorAll('.order-type-card').forEach(c => c.classList.remove('border-primary', 'bg-primary', 'bg-opacity-10'));
  card.classList.add('border-primary', 'bg-primary', 'bg-opacity-10');
  const typeInput = document.getElementById('orderTypeInput');
  const durationInput = document.getElementById('durationInput');
  if (typeInput) typeInput.value = card.getAttribute('data-type') || 'story';
  if (durationInput) durationInput.value = card.getAttribute('data-hours') || '24';
  const priceDisplay = document.getElementById('priceDisplay');
  const priceText = (card.querySelector('.text-success') || {}).textContent || '';
  if (priceDisplay && priceText.trim()) priceDisplay.textContent = priceText.trim();
  const radio = card.querySelector('input[type="radio"]');
  if (radio) radio.checked = true;
});
