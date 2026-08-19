(function () {
  'use strict';
  const root = document.getElementById('socialTaskExecuteRoot');
  const form = document.getElementById('socialTaskSubmitForm');
  if (!root || !form) return;

  const submitUrl = root.dataset.submitUrl || '';
  const behaviorUrl = root.dataset.behaviorUrl || '/api/social-tasks/behavior';
  const cameraUrl = root.dataset.cameraUrl || '/api/social-tasks/camera-verify';
  const csrf = root.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const executionId = parseInt(root.dataset.executionId || '0', 10);
  const taskType = root.dataset.taskType || 'social';
  const clientMode = root.dataset.clientMode || (isNativeMobileApp() ? 'mobile_app' : 'web');
  const expectedTime = parseInt(root.dataset.expectedTime || '60', 10);
  function isNativeMobileApp() {
    return /ChortkeMobile|ChortkeApp/i.test(navigator.userAgent) || window.ChortkeMobileApp === true;
  }
  const isMobile = /Android|iPhone|iPad|Mobile/i.test(navigator.userAgent) || (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) || isNativeMobileApp();
  let activeTime = 0;
  let focused = true;
  let notifier = null;
  let cameraFlowStarted = false;
  let lastActionAt = Date.now();
  const actionDelays = [];
  const behavior = {
    tap_count: 0,
    swipe_count: 0,
    scroll_count: 0,
    touch_pauses: 0,
    touch_timing_variance: 999,
    scroll_speed_variance: 999,
    scroll_pauses: 0,
    session_duration: 0,
    active_time: 0,
    expected_time: expectedTime,
    reconnect_count: 0,
    app_blur_count: 0,
    max_blur_duration: 0,
    hesitation_count: 0,
    avg_action_delay_ms: 0,
    natural_delay_count: 0,
    is_mobile: isMobile ? 1 : 0,
    client_mode: clientMode,
    client_version: window.ChortkeAppVersion || ''
  };
  let blurStartedAt = 0;
  let touchStart = null;

  function notify(type, message) {
    if (!notifier && typeof window.Notyf !== 'undefined') notifier = new window.Notyf({ duration: 4500, position: { x: 'left', y: 'top' }, dismissible: true });
    if (notifier && typeof notifier[type] === 'function') return notifier[type](message);
    alert(message);
  }

  function idempotencyKey() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return 'SOC_' + window.crypto.randomUUID();
    return 'SOC_' + Date.now() + '_' + Math.random().toString(16).slice(2);
  }

  function updateActionStats() {
    const now = Date.now();
    const delay = now - lastActionAt;
    lastActionAt = now;
    if (delay > 0 && delay < 30000) actionDelays.push(delay);
    if (actionDelays.length > 30) actionDelays.shift();
    if (delay > 450) behavior.natural_delay_count += 1;
    if (delay > 1200) behavior.hesitation_count += 1;
    if (actionDelays.length >= 2) {
      const avg = actionDelays.reduce((a, b) => a + b, 0) / actionDelays.length;
      const variance = actionDelays.reduce((a, b) => a + Math.pow(b - avg, 2), 0) / actionDelays.length;
      behavior.avg_action_delay_ms = Math.round(avg);
      behavior.touch_timing_variance = Math.round(Math.sqrt(variance));
    }
  }

  function currentSignals() {
    behavior.session_duration = activeTime;
    behavior.active_time = activeTime;
    behavior.expected_time = expectedTime;
    return Object.assign({}, behavior);
  }

  async function postJson(url, payload) {
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify(Object.assign({ _csrf_token: csrf }, payload))
    });
    const data = await response.json().catch(() => ({}));
    return { response, data, payload: data.data || data };
  }

  async function sendBehaviorSignals() {
    if (!executionId || !behaviorUrl) return;
    try {
      const { response, payload } = await postJson(behaviorUrl, { execution_id: executionId, signals: currentSignals() });
      if (response.ok && payload && payload.camera_required) {
        await runCameraVerification(payload);
      }
    } catch (_) {
      // behavior tracking must be silent and must not block proof submission
    }
  }

  async function runCameraVerification(serverPayload) {
    if (cameraFlowStarted || !isMobile) return;
    cameraFlowStarted = true;

    const message = serverPayload?.message || 'برای اطمینان از واقعی بودن اجرای موبایل، تأیید دوربین لازم است. هیچ عکس یا اسکرین‌شاتی ذخیره یا ارسال نمی‌شود؛ فقط امتیاز پردازش محلی ثبت می‌شود.';
    let confirmed = true;
    if (typeof Swal !== 'undefined') {
      confirmed = await Swal.fire({
        icon: 'warning',
        title: 'تأیید دوربین برای رفتار مشکوک',
        text: message,
        showCancelButton: true,
        confirmButtonText: 'اجازه می‌دهم',
        cancelButtonText: 'بعداً',
        confirmButtonColor: '#f0b90b'
      }).then(r => r.isConfirmed);
    } else {
      confirmed = window.confirm(message);
    }
    if (!confirmed) {
      cameraFlowStarted = false;
      return;
    }

    if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
      notify('error', 'مرورگر شما دسترسی دوربین را پشتیبانی نمی‌کند.');
      cameraFlowStarted = false;
      return;
    }

    let stream = null;
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
      const video = document.createElement('video');
      video.muted = true;
      video.playsInline = true;
      video.srcObject = stream;
      await video.play().catch(() => {});

      // سه نمونه ابتدا/میانه/انتها فقط برای تحلیل محلی گرفته می‌شود؛
      // هیچ تصویر خام یا اسکرین‌شاتی به سرور ارسال یا در مرورگر نگهداری دائمی نمی‌شود.
      const samples = [];
      const canvas = document.createElement('canvas');
      const ctx = canvas.getContext('2d', { willReadFrequently: true });
      async function sampleFrame(label, waitMs) {
        await new Promise(resolve => setTimeout(resolve, waitMs));
        const width = video.videoWidth || 64;
        const height = video.videoHeight || 64;
        canvas.width = Math.min(width, 96);
        canvas.height = Math.min(height, 96);
        if (ctx && width > 0 && height > 0) {
          ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
          const image = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
          let sum = 0;
          for (let i = 0; i < image.length; i += 16) sum += image[i] + image[i + 1] + image[i + 2];
          samples.push({ label, luminance: Math.round(sum / Math.max(1, image.length / 16)) });
        } else {
          samples.push({ label, luminance: 0 });
        }
      }
      await sampleFrame('start', 180);
      await sampleFrame('middle', 420);
      await sampleFrame('end', 420);

      const hasLiveVideo = video.videoWidth > 0 || stream.getVideoTracks().some(track => track.readyState === 'live');
      const luminances = samples.map(s => s.luminance);
      const delta = luminances.length >= 2 ? Math.max(...luminances) - Math.min(...luminances) : 0;
      const frameScore = samples.length >= 3 ? 10 : 0;
      const liveScore = hasLiveVideo ? 70 : 35;
      const motionScore = delta > 3 ? 15 : 5;
      const cameraScore = Math.max(0, Math.min(100, liveScore + frameScore + motionScore));
      const verifiedSignals = ['camera_permission_granted', 'no_raw_image_uploaded', 'frame_count_' + samples.length];
      if (hasLiveVideo) verifiedSignals.push('live_video_stream');
      if (delta > 3) verifiedSignals.push('frame_variation_detected');
      verifiedSignals.push('local_frame_analysis');
      if (taskType) verifiedSignals.push('task_type_' + String(taskType).replace(/[^a-z0-9_\-]/gi, '_'));

      const { response, payload } = await postJson(cameraUrl, {
        execution_id: executionId,
        camera_score: cameraScore,
        task_type: taskType,
        verified_signals: verifiedSignals,
        frame_count: samples.length,
        frame_signals: {
          luminance_start: luminances[0] || 0,
          luminance_middle: luminances[1] || 0,
          luminance_end: luminances[2] || 0,
          luminance_delta: delta,
          local_analysis: true
        },
        client_context: {
          client_mode: clientMode,
          client_version: window.ChortkeAppVersion || '',
          raw_image_uploaded: false
        }
      });
      if (response.ok && (payload.success !== false)) {
        notify('success', 'تأیید دوربین ثبت شد. عکس یا تصویر ذخیره نشد.');
      } else {
        notify('error', payload.message || 'تأیید دوربین ثبت نشد.');
      }
    } catch (err) {
      notify('error', err && err.name === 'NotAllowedError' ? 'اجازه دوربین داده نشد.' : 'خطا در دسترسی به دوربین.');
      cameraFlowStarted = false;
    } finally {
      if (stream) stream.getTracks().forEach(track => track.stop());
    }
  }

  document.addEventListener('visibilitychange', () => {
    focused = !document.hidden;
    if (!focused) {
      behavior.app_blur_count += 1;
      blurStartedAt = Date.now();
    } else if (blurStartedAt) {
      const duration = Math.round((Date.now() - blurStartedAt) / 1000);
      behavior.max_blur_duration = Math.max(behavior.max_blur_duration, duration);
      blurStartedAt = 0;
    }
  });

  window.addEventListener('online', () => { behavior.reconnect_count += 1; });
  window.addEventListener('touchstart', event => {
    behavior.tap_count += 1;
    touchStart = event.touches && event.touches[0] ? { x: event.touches[0].clientX, y: event.touches[0].clientY } : null;
    updateActionStats();
  }, { passive: true });
  window.addEventListener('touchmove', event => {
    if (!touchStart || !event.touches || !event.touches[0]) return;
    const dx = Math.abs(event.touches[0].clientX - touchStart.x);
    const dy = Math.abs(event.touches[0].clientY - touchStart.y);
    if (dx > 35 || dy > 35) {
      behavior.swipe_count += 1;
      touchStart = null;
      updateActionStats();
    }
  }, { passive: true });
  window.addEventListener('scroll', () => {
    behavior.scroll_count += 1;
    updateActionStats();
  }, { passive: true });

  setInterval(() => {
    if (focused) activeTime++;
    const input = document.getElementById('activeTime');
    if (input) input.value = String(activeTime);
    const timer = document.getElementById('timerValue');
    if (timer) timer.textContent = String(Math.max(expectedTime - activeTime, 0));
  }, 1000);

  // برای موبایل، سیگنال رفتار چند ثانیه بعد از شروع و سپس دوره‌ای ارسال می‌شود.
  if (isMobile) {
    setTimeout(sendBehaviorSignals, 3500);
    setInterval(sendBehaviorSignals, 20000);
  }

  async function completeTask(retryAfterCamera) {
    const payload = {
      active_time: activeTime,
      expected_time: expectedTime,
      behavior_signals: currentSignals(),
      idempotency_key: idempotencyKey(),
      client_mode: clientMode,
      _csrf_token: csrf
    };
    const response = await fetch(submitUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await response.json().catch(() => ({}));
    if (response.ok && data.success) {
      notify('success', data.message || 'نتیجه تسک محاسبه شد.');
      setTimeout(() => { window.location.href = '/tasks?type=social&submitted=1'; }, 900);
      return;
    }
    if (data && data.camera_required && !retryAfterCamera) {
      await runCameraVerification(data);
      await completeTask(true);
      return;
    }
    notify('error', data.message || 'محاسبه نتیجه تسک انجام نشد.');
  }

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    await sendBehaviorSignals();

    const btn = document.getElementById('submitBtn');
    if (btn) { btn.disabled = true; btn.dataset.originalText = btn.innerHTML; btn.innerHTML = '<i class="material-icons" style="animation:spin .8s linear infinite">refresh</i> در حال محاسبه...'; }
    document.getElementById('idempotencyKey').value = idempotencyKey();
    document.getElementById('activeTime').value = String(activeTime);

    try {
      await completeTask(false);
    } catch (_) {
      notify('error', 'خطا در ارتباط با سرور.');
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = btn.dataset.originalText || '<i class="material-icons">task_alt</i> پایان و محاسبه امتیاز'; }
    }
  });

  const style = document.createElement('style');
  style.textContent = '@keyframes spin{to{transform:rotate(360deg)}}';
  document.head.appendChild(style);
})();
