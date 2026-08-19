document.addEventListener('DOMContentLoaded', () => {
  // Handle detail toggle via data-action
  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-action="show-detail"]');
    if (!trigger) return;

    e.preventDefault();
    const id = trigger.getAttribute('data-id');
    if (!id) return;

    const row = document.getElementById('detail-' + id);
    if (!row) return;

    const isOpen = row.style.display !== 'none';

    // Close all
    document.querySelectorAll('[id^="detail-"]').forEach(r => r.style.display = 'none');
    document.querySelectorAll('.influencer-row').forEach(r => r.classList.remove('table-active'));

    if (!isOpen) {
      row.style.display = '';
      trigger.classList.add('table-active');
    }
  });
});
