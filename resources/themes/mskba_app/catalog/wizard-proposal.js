// DESIGN REVIEW ONLY — configurable wizard specimen, no API/auth calls.
// Intended contract for future shared Vue WizardShell; do not use as product runtime.
const flowConfig = {
  initialStep: 'role',
  submitLabel: 'Создать аккаунт',
  nextLabel: 'Далее',
  skipLabel: 'Пропустить',
  backLabel: 'Назад',
  steps: [
    {
      id: 'role', title: 'Кем вы хотите быть в MSKBA?',
      hint: 'Вы можете выбрать роль сразу и в будущем её изменить или добавить новую.',
      fields: ['role'], required: [], visible: () => true,
    },
    {
      id: 'person', title: 'Расскажите немного о себе',
      hint: 'Это поможет подбирать игры. Все поля необязательные — их можно заполнить позже.',
      fields: ['gender','birth_date'], required: [], visible: state => state.role === 'player',
    },
    {
      id: 'sport', title: 'Спортивные характеристики',
      hint: 'Чтобы подобрать подходящую команду. Вводите только то, что знаете.',
      fields: ['height_cm','weight_kg','position','body_type','experience_started_year'],
      required: [], visible: state => state.role === 'player',
    },
    {
      id: 'name', title: 'Как к вам обращаться?',
      hint: 'ФИО необязательно и пригодится для персонализации профиля.',
      fields: ['last_name','first_name','middle_name'], required: [], visible: () => true,
    },
    {
      id: 'account', title: 'Создайте аккаунт',
      hint: 'Логин и пароль нужны для входа. Согласие на обработку данных обязательно.',
      fields: ['username','password','password_confirmation','privacy_consent'],
      required: ['username','password','password_confirmation','privacy_consent'],
      visible: () => true,
    },
  ],
};
const form = document.querySelector('#wizard-form');
const els = {
  current: document.querySelector('#wizard-step-count'),
  title: document.querySelector('#wizard-step-title'),
  hint: document.querySelector('#wizard-step-hint'),
  progress: document.querySelector('#wizard-progress'),
  back: document.querySelector('#wizard-back'),
  next: document.querySelector('#wizard-next'),
  errorSummary: document.querySelector('#wizard-error-summary'),
};
let currentStepId = flowConfig.initialStep;
const elFor = field => form.elements.namedItem(field);
const valueFor = field => {
  const el = elFor(field);
  if (!el) return '';
  if (el instanceof RadioNodeList) return el.value || '';
  return el.type === 'checkbox' ? el.checked : el.value.trim();
};
const values = () => Object.fromEntries(flowConfig.steps.flatMap(s => s.fields).map(name => [name, valueFor(name)]));
const visible = () => flowConfig.steps.filter(step => step.visible(values()));
const current = () => flowConfig.steps.find(step => step.id === currentStepId);
const errors = new Map();
const roleGroups = [...form.querySelectorAll('[data-role-group]')];

// Compact pulse + travelling sheen: only when Skip becomes Next.
// Back/Forward and changing one selected value to another never trigger it.
function clearNextAttention() {
  els.next.classList.remove('is-choice-attention');
}
function highlightNext() {
  if (els.next.disabled || els.next.textContent !== flowConfig.nextLabel ||
      window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  clearNextAttention();
  void els.next.offsetWidth;
  els.next.classList.add('is-choice-attention');
}
els.next.addEventListener('animationend', event => {
  if (event.target === els.next && event.animationName === 'wizard-next-pulse')
    clearNextAttention();
});

// Accessible native <details> with one expanded group at a time.
// Changing the visible group never resets the single role radio selection.
let groupsUpdating = false;
for (const group of roleGroups) {
  group.addEventListener('toggle', () => {
    if (groupsUpdating || !group.open) return;
    groupsUpdating = true;
    for (const other of roleGroups) if (other !== group) other.open = false;
    groupsUpdating = false;
  });
}
function updateRoleGroupLabels() {
  const chosen = form.querySelector('input[name="role"]:checked');
  for (const group of roleGroups) {
    const summary = group.closest('.wizard-role-item').querySelector('[data-role-selection]');
    const selected = Boolean(chosen && group.contains(chosen));
    const selectedText = selected
      ? chosen.closest('label')?.querySelector('strong')?.textContent?.trim()
      : null;
    summary.textContent = selectedText || '';
    group.classList.toggle('has-selection', selected);
    const item = group.closest('.wizard-role-item');
    const picked = item.querySelector('[data-role-picked]');
    picked.hidden = !selected;
    const clearButton = item.querySelector('[data-clear-role]');
    clearButton.setAttribute('aria-label', selectedText ? 'Сбросить роль «' + selectedText + '»' : 'Сбросить выбранную роль');
    const groupLabel = group.dataset.roleGroup === 'primary' ? 'Основные' : 'Другие';
    group.querySelector('summary').setAttribute('aria-label', selectedText
      ? groupLabel + ', выбрано ' + selectedText
      : groupLabel);
  }
}
for (const clearButton of form.querySelectorAll('[data-clear-role]')) {
  clearButton.addEventListener('click', () => {
    const group = clearButton.closest('.wizard-role-item').querySelector('[data-role-group]');
    const selected = group.querySelector('input[name="role"]:checked');
    if (!selected) return;
    selected.checked = false;
    cleanError('role');
    for (const other of roleGroups) other.open = other === group;
    updateRoleGroupLabels();
    showStep(currentStepId);
    group.querySelector('summary').focus({ preventScroll: true });
  });
}

function showErrors() {
  for (const input of form.querySelectorAll('input,select')) {
    const name = input.name;
    if (!name) continue;
    input.setAttribute('aria-invalid', String(errors.has(name)));
    const helper = document.getElementById(name + '-hint');
    const note = document.getElementById(name + '-error');
    if (note) {
      note.textContent = errors.get(name) || '';
      note.hidden = !errors.has(name);
    }
    const desc = [helper && helper.id, errors.has(name) && note && note.id].filter(Boolean).join(' ');
    if (desc) input.setAttribute('aria-describedby', desc);
    else input.removeAttribute('aria-describedby');
  }
}
function fail(field, message) { errors.set(field, message); }
function cleanError(field) { errors.delete(field); showErrors(); }

function fieldValidation(field, required = false) {
  const el = elFor(field);
  const value = valueFor(field);
  if (required && (value === '' || value === false)) return 'Заполните обязательное поле.';
  if (value === '' || value === false) return null; // truly optional value
  if (field === 'birth_date') {
    const date = new Date(value + 'T00:00:00');
    if (Number.isNaN(date.getTime()) || date >= new Date()) return 'Укажите дату раньше сегодняшнего дня.';
  }
  if (field === 'height_cm' && (!Number.isInteger(Number(value)) || Number(value)<150 || Number(value)>220))
    return 'Рост должен быть от 150 до 220 см.';
  if (field === 'weight_kg' && (!Number.isFinite(Number(value)) || Number(value)<40 || Number(value)>140))
    return 'Вес должен быть от 40 до 140 кг.';
  if (field === 'experience_started_year' && (!Number.isInteger(Number(value)) || Number(value)<1976 || Number(value)>2026))
    return 'Укажите год от 1976 до 2026.';
  if (field === 'username' && String(value).length < 3) return 'Логин: минимум 3 символа.';
  if (field === 'password' && String(value).length < 8) return 'Пароль: минимум 8 символов.';
  if (field === 'password_confirmation' && value !== valueFor('password')) return 'Пароли не совпадают.';
  return null;
}
function focusFirstError(step) {
  for (const field of step.fields) {
    if (!errors.has(field)) continue;
    const el = elFor(field);
    if (el instanceof RadioNodeList) {
      const selected = form.querySelector('input[name="' + field + '"]:checked');
      const targetGroup = selected?.closest('[data-role-group]') || roleGroups[0];
      // Unhide the field first: focus never goes to a radio inside closed details.
      for (const group of roleGroups) group.open = group === targetGroup;
      const input = selected || targetGroup?.querySelector('input[type="radio"]');
      input?.focus({preventScroll:true});
      input?.scrollIntoView({block:'nearest',behavior:'auto'});
    } else {
      el?.focus({preventScroll:true});
      el?.scrollIntoView({block:'nearest',behavior:'auto'});
    }
    break;
  }
}
function validate(step) {
  for (const field of step.fields) errors.delete(field);
  for (const field of step.fields) {
    const error = fieldValidation(field, step.required.includes(field));
    if (error) fail(field, error);
  }
  showErrors();
  if (step.fields.some(field => errors.has(field))) {
    els.errorSummary.textContent = 'Проверьте выделенные поля, чтобы продолжить.';
    els.errorSummary.hidden = false;
    focusFirstError(step);
    return false;
  }
  els.errorSummary.hidden = true;
  return true;
}
function updateNextButtonLabel(animateOnTransition = false) {
  const previousLabel = els.next.textContent.trim();
  const step = current();
  const lastStep = visible().at(-1)?.id === step.id;
  const hasRequired = step.required.length > 0;
  const hasEnteredValues = step.fields.some(field => {
    const value = valueFor(field);
    return value !== '' && value !== false;
  });
  // Every step follows the same contract: a required or filled step uses Next;
  // an entirely empty optional step uses Skip. The final action is contextual.
  els.next.textContent = lastStep
    ? flowConfig.submitLabel
    : (hasRequired || hasEnteredValues ? flowConfig.nextLabel : flowConfig.skipLabel);
  if (els.next.textContent !== flowConfig.nextLabel) clearNextAttention();
  else if (animateOnTransition && previousLabel === flowConfig.skipLabel) highlightNext();
}
function updateVisibleStepCount() {
  const steps = visible(), i = steps.findIndex(s => s.id === currentStepId);
  els.current.textContent = 'ШАГ ' + (i + 1) + ' / ' + steps.length;
  els.progress.max = steps.length;
  els.progress.value = i + 1;
  els.progress.setAttribute('aria-valuetext', 'Шаг ' + (i + 1) + ' из ' + steps.length);
}
function showStep(stepId, focus = false) {
  // A step change is not a selection; no inherited animation on navigation.
  clearNextAttention();
  const steps = visible();
  if (!steps.some(s => s.id === stepId)) stepId = steps[0].id;
  currentStepId = stepId;
  const step = current();
  const index = steps.findIndex(s => s.id === stepId);
  for (const section of form.querySelectorAll('[data-step]')) section.hidden = section.dataset.step !== stepId;
  updateVisibleStepCount();
  els.title.textContent = step.title;
  els.hint.textContent = step.hint;
  updateNextButtonLabel();
  els.back.disabled = index === 0;
  els.back.textContent = flowConfig.backLabel;
  els.errorSummary.hidden = true;
  showErrors();
  if (focus) els.title.focus({preventScroll:true});
}
function stepForward() {
  const step = current();
  // On optional steps, 'Skip' retains entered data, but still validates any
  // PROVIDED values before allowing a transition. Empty values never block.
  if (!validate(step)) return;
  const steps = visible();
  const index = steps.findIndex(s => s.id === step.id);
  if (index === steps.length - 1) {
    els.errorSummary.hidden = false;
    els.errorSummary.classList.add('wizard-success-note');
    els.errorSummary.textContent = 'Демо: проверка пройдена. Реальный аккаунт не создан.';
    return;
  }
  els.errorSummary.classList.remove('wizard-success-note');
  showStep(steps[index+1].id, true);
}
els.next.addEventListener('click', stepForward);
els.back.addEventListener('click', () => {
  const steps=visible();const i=steps.findIndex(s=>s.id===currentStepId);
  if(i>0) showStep(steps[i-1].id,true); // no validation and no reset
});
form.addEventListener('input', e => {
  if (!e.target.name) return;
  cleanError(e.target.name);
  updateNextButtonLabel(true);
});
form.addEventListener('change', e => {
  if (!e.target.name) return;
  cleanError(e.target.name);
  updateNextButtonLabel(true);
  if (e.target.name === 'role') {
    const group = e.target.closest('[data-role-group]');
    // The branch count changes, but the current step doesn't. Avoid a
    // full showStep() redraw which would cancel the just-started animation.
    for (const item of roleGroups) item.open = false;
    updateRoleGroupLabels();
    updateVisibleStepCount();
    group?.querySelector('summary')?.focus({ preventScroll: true });
  }
});
form.addEventListener('submit', e=>{e.preventDefault();stepForward();});
// One help trigger per full label (including its wording and asterisk).
form.addEventListener('click', e => {
  const label = e.target.closest('label.wizard-required-label');
  if (!label) return;
  const open = !label.classList.contains('is-tooltip-open');
  form.querySelectorAll('.wizard-required-label.is-tooltip-open')
    .forEach(other => other.classList.remove('is-tooltip-open'));
  label.classList.remove('is-tooltip-dismissed');
  label.classList.toggle('is-tooltip-open', open);
});
document.addEventListener('pointerdown', e => {
  if (e.target.closest('label.wizard-required-label')) return;
  form.querySelectorAll('.wizard-required-label.is-tooltip-open')
    .forEach(label => label.classList.remove('is-tooltip-open'));
});
form.addEventListener('pointerout', e => {
  const label = e.target.closest('label.wizard-required-label');
  if (label && !label.contains(e.relatedTarget)) label.classList.remove('is-tooltip-dismissed');
});
form.addEventListener('focusin', e => {
  e.target.closest('label.wizard-required-label')?.classList.remove('is-tooltip-dismissed');
});
document.addEventListener('keydown', e => {
  if (e.key !== 'Escape') return;
  const label = form.querySelector('.wizard-required-label.is-tooltip-open')
    || form.querySelector('.wizard-required-label:hover');
  if (!label) return;
  e.preventDefault();
  e.stopImmediatePropagation();
  label.classList.remove('is-tooltip-open');
  label.classList.add('is-tooltip-dismissed');
}, true);
document.querySelector('#wizard-reset').addEventListener('click',()=>{
  form.reset(); errors.clear(); els.errorSummary.classList.remove('wizard-success-note');
  for (const group of roleGroups) group.open = group.dataset.roleGroup === 'primary';
  updateRoleGroupLabels();
  showStep(flowConfig.initialStep,true);
});
// Simulated Laravel FormRequest 422: map field -> step and focus first invalid.
function applyServerErrors(payload) {
  const activeSteps=visible();
  for(const [field,messages] of Object.entries(payload.errors || {})){
    const step=flowConfig.steps.find(s=>s.fields.includes(field));
    if(!step || !activeSteps.some(s=>s.id===step.id)) continue;
    errors.set(field, Array.isArray(messages) ? messages[0] : String(messages));
  }
  const target=activeSteps.find(step=>step.fields.some(field=>errors.has(field)));
  if(target) {
    showStep(target.id);
    els.errorSummary.hidden=false;
    els.errorSummary.textContent=payload.message || 'Проверьте данные на этом шаге.';
    showErrors();
    focusFirstError(target);
  }
}
document.querySelector('#wizard-simulate-server-error').addEventListener('click',()=>{
  const field=valueFor('role')==='player'?'height_cm':'username';
  applyServerErrors({message:'Ответ сервера: найдена ошибка в другом шаге.',errors:{
    [field]:['Сервер отклонил значение. Проверьте данные.']
  }});
});
updateRoleGroupLabels();
showStep(flowConfig.initialStep);
