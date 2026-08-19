(function () {
  'use strict';

  document.querySelectorAll('.sup-priority-opt input').forEach(function (radio) {
    radio.addEventListener('change', function () {
      document.querySelectorAll('.sup-priority-opt').forEach(el => el.classList.remove('active'));
      radio.closest('.sup-priority-opt')?.classList.add('active');
    });
  });

  document.getElementById('attachFiles')?.addEventListener('change', function () {
    const preview = document.getElementById('filePreview');
    if (!preview) return;
    preview.innerHTML = '';
    Array.from(this.files || []).forEach(function (file) {
      const chip = document.createElement('span');
      chip.className = 'sup-file-chip';
      chip.innerHTML = '<i class="material-icons">insert_drive_file</i>' + file.name;
      preview.appendChild(chip);
    });
  });
})();
