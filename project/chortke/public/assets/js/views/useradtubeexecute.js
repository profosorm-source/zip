(function () {
  'use strict';
  const script = document.currentScript;
  const totalDuration = parseInt(script?.dataset.duration || '30', 10);
  let remaining = (!Number.isFinite(totalDuration) || totalDuration < 0) ? 30 : totalDuration;
  let watched = 0;
  const countdown = document.getElementById('countdown');
  const watchedSeconds = document.getElementById('watchedSeconds');
  const watchTimeInput = document.getElementById('watchTime');
  const progressPercentInput = document.getElementById('progressPercent');
  const playbackSpeedInput = document.getElementById('playbackSpeed');
  const waitAlert = document.getElementById('waitAlert');
  const btnSubmit = document.getElementById('btnSubmit');

  const timer = setInterval(() => {
    remaining--;
    watched++;
    const progress = Math.min(100, Math.round((watched / (totalDuration || 30)) * 100));

    if (countdown) countdown.textContent = String(Math.max(0, remaining));
    if (watchedSeconds) watchedSeconds.value = String(watched);
    if (watchTimeInput) watchTimeInput.value = String(watched);
    if (progressPercentInput) progressPercentInput.value = String(progress);
    if (playbackSpeedInput && !playbackSpeedInput.value) playbackSpeedInput.value = '1.0';

    if (remaining <= 0) {
      clearInterval(timer);
      if (waitAlert) {
        waitAlert.className = 'alert alert-success d-flex align-items-center gap-2';
        waitAlert.innerHTML = '<span class="material-icons">check_circle</span><div>تماشا کامل شد! روی دکمه زیر کلیک کنید.</div>';
      }
      if (btnSubmit) btnSubmit.disabled = false;
    }
  }, 1000);
})();
