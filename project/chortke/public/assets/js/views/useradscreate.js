/**
 * Ads Wizard — Progressive AJAX Wizard
 * Features: Step-by-step disclosure, real-time validation, cost preview, zero reload.
 */

(function() {
  'use strict';

  const WIZARD = {
    step: 1,
    maxSteps: 4,
    selectedType: null,
    typeMeta: null,
    formData: new FormData(),
    validations: {},
    csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',
  };

  const els = {};

  document.addEventListener('DOMContentLoaded', init);

  function init() {
    cacheElements();
    bindEvents();
    window.WIZARD_RESET_TO_STEP1 = () => {
      WIZARD.selectedType = null;
      WIZARD.typeMeta = null;
      els.typeCards?.forEach(c => c.classList.remove('selected'));
      const step2Title = document.getElementById('step2Title');
      if (step2Title) step2Title.textContent = 'جزئیات تبلیغ';
      advanceTo(1);
    };
    window.addEventListener('popstate', () => {
      const p = new URLSearchParams(window.location.search);
      const st = parseInt(p.get('step') || '1', 10);
      const tp = p.get('type');
      if (tp && tp !== WIZARD.selectedType) {
        onSelectType(tp);
      } else if (st >= 1 && st <= 4 && st !== WIZARD.step) {
        advanceTo(st, false);
      }
    });
    const p = new URLSearchParams(window.location.search);
    const initialStep = parseInt(p.get('step') || '1', 10);
    const initialType = p.get('type');
    if (initialType && initialStep > 1) {
      onSelectType(initialType);
    } else {
      renderStep(1);
    }
  }

  function cacheElements() {
    els.container = document.getElementById('adsWizard');
    els.stepper = document.getElementById('wizardStepper');
    els.step1 = document.getElementById('step1');
    els.step2 = document.getElementById('step2');
    els.step3 = document.getElementById('step3');
    els.step4 = document.getElementById('step4');
    els.success = document.getElementById('wizardSuccess');
    els.typeCards = document.querySelectorAll('.type-card');
    els.dynamicForm = document.getElementById('dynamicForm');
    els.budgetPreview = document.getElementById('budgetPreview');
    els.btnNext = document.getElementById('btnNext');
    els.btnPrev = document.getElementById('btnPrev');
    els.btnSubmit = document.getElementById('btnSubmit');
    els.loadingOverlay = document.getElementById('stepLoading');
  }

  function bindEvents() {
    els.typeCards.forEach(card => {
      card.addEventListener('click', () => onSelectType(card.dataset.type));
    });

    document.querySelectorAll('.step-node').forEach((node, idx) => {
      node.addEventListener('click', () => {
        const targetStep = idx + 1;
        if (targetStep < WIZARD.step || (targetStep === 2 && WIZARD.selectedType)) {
          advanceTo(targetStep);
        }
      });
    });

    els.btnNext?.addEventListener('click', onNext);
    els.btnPrev?.addEventListener('click', onPrev);
    els.btnSubmit?.addEventListener('click', onSubmit);

    // Delegate real-time validation on dynamic form
    els.dynamicForm?.addEventListener('input', debounce(onFieldInput, 350));
    els.dynamicForm?.addEventListener('change', onFieldChange);
  }

  function endpoint(key, fallback) {
    return els.container?.dataset?.[key] || fallback;
  }

  function withQuery(url, params) {
    const sep = url.includes('?') ? '&' : '?';
    return url + sep + params;
  }

  // ── Step 1: Type Selection ───────────────────────────────────────────

  async function onSelectType(type) {
    if (WIZARD.step !== 1) advanceTo(1);

    setLoading(true);

    try {
      const res = await fetch(withQuery(endpoint('typeInfoUrl', '/ads/api/type-info'), 'type=' + encodeURIComponent(type)), {
        headers: { 'Accept': 'application/json' }
      });
      const json = await res.json();

      if (!json.success) throw new Error(json.message || 'خطا در دریافت اطلاعات');

      WIZARD.selectedType = type;
      WIZARD.typeMeta = json.data;

      // Visual feedback
      els.typeCards.forEach(c => c.classList.toggle('selected', c.dataset.type === type));

      // Build dynamic form before transition
      buildDynamicForm(json.data.fields);

      // Small delay for visual confirmation then advance
      await sleep(280);
      advanceTo(2);
    } catch (err) {
      showToast('error', err.message);
    } finally {
      setLoading(false);
    }
  }

  // ── Step 2: Dynamic Form ───────────────────────────────────────────

  function buildDynamicForm(fields) {
    if (!els.dynamicForm) return;
    els.dynamicForm.innerHTML = '';
    WIZARD.validations = {};

    const fragment = document.createDocumentFragment();

    fields.forEach((field, index) => {
      const group = document.createElement('div');
      group.className = 'field-group';
      group.dataset.field = field.name;

      const label = document.createElement('label');
      label.textContent = field.label + (field.required ? ' *' : '');
      group.appendChild(label);

      let input;
      if (field.type === 'textarea') {
        input = document.createElement('textarea');
        input.rows = 3;
      } else if (field.type === 'select') {
        input = document.createElement('select');
        input.innerHTML = '<option value="" disabled selected>انتخاب کنید...</option>' +
          field.options.map(o => `<option value="${o}">${o}</option>`).join('');
      } else if (field.type === 'file') {
        input = document.createElement('input');
        input.type = 'file';
        if (field.accept) input.accept = field.accept;
      } else {
        input = document.createElement('input');
        input.type = field.type;
        if (field.min) input.min = field.min;
        if (field.max) input.max = field.max;
        if (field.step) input.step = field.step;
        if (field.placeholder) input.placeholder = field.placeholder;
      }

      input.name = field.name;
      input.className = 'form-control';
      input.required = !!field.required;
      input.dataset.validate = 'true';
      if (field.default) input.value = field.default;

      // RTL text inputs
      if (field.type === 'text' || field.type === 'textarea') {
        input.dir = 'rtl';
      }
      if (field.type === 'url' || field.type === 'number') {
        input.dir = 'ltr';
      }

      group.appendChild(input);

      // Feedback element
      const feedback = document.createElement('div');
      feedback.className = 'validation-feedback';
      group.appendChild(feedback);

      if (field.hint) {
        const hint = document.createElement('div');
        hint.className = 'field-hint';
        hint.textContent = field.hint;
        group.appendChild(hint);
      }

      fragment.appendChild(group);
    });

    // Hidden type field
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'ad_type';
    hidden.value = WIZARD.selectedType;
    fragment.appendChild(hidden);

    els.dynamicForm.appendChild(fragment);
    if (WIZARD.step === 2) {
      els.dynamicForm.classList.add('active');
    }
  }

  // ── Real-time Validation ───────────────────────────────────────────

  async function onFieldInput(e) {
    const field = e.target;
    if (!field.dataset.validate || !field.name) return;

    // Skip empty optional fields on input (validate on blur/change instead)
    if (!field.required && !field.value.trim()) {
      clearFieldStatus(field);
      return;
    }

    await validateField(field);
    updateStep2Validity();
  }

  async function onFieldChange(e) {
    const field = e.target;
    if (!field.dataset.validate || !field.name) return;
    await validateField(field);
    updateStep2Validity();

    // If price/quantity changed, refresh budget preview (if on step 3)
    if (WIZARD.step === 3 && isBudgetField(field.name)) {
      await refreshBudgetPreview();
    }
  }

  async function validateField(field) {
    const group = field.closest('.field-group');
    const feedback = group?.querySelector('.validation-feedback');
    if (!feedback) return;

    try {
      const formData = collectFormData();
      const res = await fetch(endpoint('validateFieldUrl', '/ads/api/validate-field'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': WIZARD.csrf,
        },
        body: JSON.stringify({
          ad_type: WIZARD.selectedType,
          field: field.name,
          value: field.value,
          ...formData,
        }),
      });
      const json = await res.json();

      WIZARD.validations[field.name] = json.success;

      field.classList.remove('is-valid', 'is-invalid');
      field.classList.add(json.success ? 'is-valid' : 'is-invalid');
      feedback.classList.toggle('show', true);
      feedback.classList.toggle('valid', json.success);
      feedback.classList.toggle('invalid', !json.success);
      feedback.innerHTML = json.success
        ? '<span class="material-icons wiz-feedback-icon">check_circle</span> ' + (json.message || 'معتبر')
        : '<span class="material-icons wiz-feedback-icon">error</span> ' + (json.errors?.[0] || 'نامعتبر');
    } catch (err) {
      // Silent fail on network error — don't block typing
    }
  }

  function clearFieldStatus(field) {
    field.classList.remove('is-valid', 'is-invalid');
    const group = field.closest('.field-group');
    const fb = group?.querySelector('.validation-feedback');
    if (fb) fb.classList.remove('show');
  }

  function updateStep2Validity() {
    const fields = els.dynamicForm?.querySelectorAll('[data-validate]');
    if (!fields) return;
    const allValid = Array.from(fields).every(f => {
      if (f.required && !f.value.trim()) return false;
      if (WIZARD.validations[f.name] === false) return false;
      return true;
    });
    if (els.btnNext) els.btnNext.disabled = !allValid;
  }

  // ── Navigation ─────────────────────────────────────────────────────

  async function onNext() {
    if (WIZARD.step === 2) {
      // Validate all required fields before proceeding
      const fields = els.dynamicForm?.querySelectorAll('[data-validate]');
      let allValid = true;
      for (const f of fields) {
        if (f.required && !f.value.trim()) allValid = false;
        if (WIZARD.validations[f.name] === false) allValid = false;
      }
      if (!allValid) {
        showToast('warning', 'لطفاً همه فیلدهای الزامی را صحیح وارد کنید.');
        return;
      }
      await refreshBudgetPreview();
    }
    if (WIZARD.step < WIZARD.maxSteps) advanceTo(WIZARD.step + 1);
  }

  function onPrev() {
    if (WIZARD.step > 1) advanceTo(WIZARD.step - 1);
  }

  function advanceTo(step, push = true) {
    WIZARD.step = step;
    renderStep(step);
    updateStepper(step);
    updateButtons(step);
    if (push) {
      try {
        const url = new URL(window.location.href);
        url.searchParams.set('section', 'create');
        url.searchParams.set('step', step);
        if (WIZARD.selectedType) url.searchParams.set('type', WIZARD.selectedType);
        else url.searchParams.delete('type');
        history.pushState(null, '', url.toString());
      } catch(e){}
    }
  }

  function renderStep(step) {
    [1,2,3,4].forEach(n => {
      const el = els['step'+n];
      if (el) el.classList.toggle('active', n === step);
    });

    // Ensure dynamic detail form becomes visible when the details step is active.
    if (els.dynamicForm) {
      els.dynamicForm.classList.toggle('active', step === 2);
    }

    // Budget preview only on step 3
    if (step === 3) {
      els.budgetPreview?.classList.add('active');
    } else {
      els.budgetPreview?.classList.remove('active');
    }
  }

  function updateStepper(step) {
    if (!els.stepper) return;
    els.stepper.className = 'wizard-stepper step-' + step;
    const nodes = els.stepper.querySelectorAll('.step-node');
    nodes.forEach((node, idx) => {
      const s = idx + 1;
      node.classList.remove('active', 'completed');
      if (s === step) node.classList.add('active');
      else if (s < step) node.classList.add('completed');
    });
  }

  function updateButtons(step) {
    if (els.btnPrev) {
      els.btnPrev.style.visibility = step === 1 ? 'hidden' : 'visible';
    }
    if (els.btnNext) {
      els.btnNext.classList.toggle('d-none', step === 4);
    }
    if (els.btnSubmit) {
      els.btnSubmit.classList.toggle('d-none', step !== 4);
    }
  }

  // ── Step 3: Budget Preview ───────────────────────────────────────────

  async function refreshBudgetPreview() {
    if (!els.budgetPreview) return;
    els.budgetPreview.classList.add('loading');

    const data = collectFormData();

    try {
      const res = await fetch(endpoint('previewCostUrl', '/ads/api/preview-cost'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': WIZARD.csrf,
        },
        body: JSON.stringify(data),
      });
      const json = await res.json();

      if (json.success) {
        renderBudgetPreview(json.preview);
      } else {
        showToast('warning', json.message || 'خطا در محاسبه بودجه');
      }
    } catch (err) {
      showToast('error', 'خطای شبکه در محاسبه بودجه');
    } finally {
      els.budgetPreview.classList.remove('loading');
    }
  }

  function renderBudgetPreview(p) {
    if (!els.budgetPreview) return;
    els.budgetPreview.innerHTML = `
      <div class="budget-preview-card" id="budgetPreviewCard">
        <div class="budget-row">
          <span>بودجه پایه</span>
          <span class="budget-amount">${fmtNumber(p.base_budget)} تومان</span>
        </div>
        <div class="budget-row">
          <span>کارمزد سایت (${p.site_fee_percent}%)</span>
          <span class="budget-amount">${fmtNumber(p.site_fee_amount)} تومان</span>
        </div>
        <div class="budget-row total">
          <span>جمع کل کسر از کیف پول</span>
          <span class="budget-amount">${fmtNumber(p.total_with_fee)} تومان</span>
        </div>
        ${p.estimated_reach ? `
        <div class="budget-row budget-row-estimate">
          <span>تخمین دسترسی</span>
          <span class="budget-amount">~${fmtNumber(p.estimated_reach)} نفر</span>
        </div>` : ''}
      </div>
    `;
  }

  // ── Step 4: Confirm & Submit ───────────────────────────────────────

  async function onSubmit() {
    setLoading(true);
    els.btnSubmit.disabled = true;
    els.btnSubmit.innerHTML = '<span class="wizard-spinner"></span> در حال ثبت...';

    const data = collectFormData();
    const formData = new FormData();
    for (const [k, v] of Object.entries(data)) {
      formData.append(k, v);
    }

    // Handle file uploads
    const fileInputs = els.dynamicForm?.querySelectorAll('input[type="file"]');
    fileInputs?.forEach(input => {
      if (input.files[0]) formData.append(input.name, input.files[0]);
    });

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 45000);
    try {
      const res = await fetch(endpoint('storeUrl', '/ads/store'), {
        method: 'POST',
        headers: {
          'X-CSRF-Token': WIZARD.csrf,
          'Accept': 'application/json',
        },
        body: formData,
        signal: controller.signal,
      });
      const raw = await res.text();
      let json = {};
      try { json = raw ? JSON.parse(raw) : {}; } catch (_) {
        throw new Error('پاسخ سرور قابل خواندن نیست. لطفاً دوباره تلاش کنید.');
      }

      if (res.ok && json.success) {
        showSuccess(json.ad_id);
      } else {
        showToast('error', json.message || 'خطا در ثبت تبلیغ');
        resetSubmitButton();
      }
    } catch (err) {
      const msg = err && err.name === 'AbortError'
        ? 'زمان پاسخ سرور طولانی شد. لطفاً وضعیت آگهی‌های من را بررسی کنید یا دوباره تلاش کنید.'
        : (err.message || 'خطای شبکه در ارسال اطلاعات');
      showToast('error', msg);
      resetSubmitButton();
    } finally {
      clearTimeout(timeout);
      setLoading(false);
    }
  }

  function resetSubmitButton() {
    if (!els.btnSubmit) return;
    els.btnSubmit.disabled = false;
    els.btnSubmit.innerHTML = '<span class="material-icons icon-sm">check_circle</span> تأیید نهایی و ثبت';
  }

  function showSuccess(adId) {
    [els.step1, els.step2, els.step3, els.step4].forEach(el => {
      if (el) el.classList.remove('active');
    });
    if (els.success) els.success.classList.add('show');
    if (els.stepper) els.stepper.classList.add('d-none');
    document.getElementById('wizardActions')?.classList.add('d-none');
    if (els.btnSubmit) {
      els.btnSubmit.disabled = false;
      els.btnSubmit.innerHTML = '<span class="material-icons icon-sm">check_circle</span> ثبت شد';
    }

    // Auto-redirect to ad list after 2.5s
    setTimeout(() => {
      window.location.href = endpoint('indexUrl', '/ads');
    }, 2500);
  }

  // ── Utilities ──────────────────────────────────────────────────────

  function collectFormData() {
    const data = { ad_type: WIZARD.selectedType };
    const inputs = els.dynamicForm?.querySelectorAll('input, select, textarea');
    inputs?.forEach(input => {
      if (input.type === 'file') return;
      data[input.name] = input.value;
    });
    return data;
  }

  function isBudgetField(name) {
    return ['price_per_task', 'total_count', 'budget', 'min_payout', 'max_payout', 'quantity'].includes(name);
  }

  function fmtNumber(n) {
    return new Intl.NumberFormat('fa-IR').format(Math.round(n));
  }

  function showToast(type, message) {
    if (typeof Notyf !== 'undefined') {
      const notyf = new Notyf({ duration: 4000, position: { x: 'center', y: 'top' } });
      notyf[type === 'success' ? 'success' : 'error'](message);
    } else {
      alert(message);
    }
  }

  function setLoading(active) {
    if (els.loadingOverlay) {
      els.loadingOverlay.classList.toggle('show', active);
    }
  }

  function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

  function debounce(fn, ms) {
    let t;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), ms);
    };
  }

})();

