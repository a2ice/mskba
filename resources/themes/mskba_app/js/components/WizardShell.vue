<script setup>
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps({
    steps: { type: Array, required: true },
    values: { type: Object, required: true },
    validateField: { type: Function, required: true },
    submitLabel: { type: String, default: 'Готово' },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['submit', 'invalid', 'step-change']);
const activeId = ref(props.steps[0]?.id);
const issues = ref({});
const content = ref(null);
const nextButton = ref(null);
const attention = ref(false);
const visibleSteps = computed(() => props.steps.filter(step => !step.visibleWhen || step.visibleWhen(props.values)));
const index = computed(() => Math.max(0, visibleSteps.value.findIndex(s => s.id === activeId.value)));
const step = computed(() => visibleSteps.value[index.value]);
const lastStep = computed(() => index.value === visibleSteps.value.length - 1);
const hasRequired = computed(() => step.value?.required?.length > 0);
const hasValues = computed(() => step.value?.fields?.some(field => {
    const value = props.values[field];
    return value !== '' && value !== null && value !== undefined && value !== false;
}) || false);
const nextLabel = computed(() => lastStep.value
    ? props.submitLabel : (hasRequired.value || hasValues.value ? 'Далее' : 'Пропустить'));
// Track the last displayed action within the current step; navigating does not
// count as an empty -> filled transition.
let previousNextLabel = nextLabel.value;

watch(visibleSteps, steps => {
    if (!steps.some(s => s.id === activeId.value)) activeId.value = steps[0]?.id;
});
function stopAttention() {
    attention.value = false;
}
function valueChanged(field) {
    delete issues.value[field];
    const label = nextLabel.value;
    const becameNext = previousNextLabel === 'Пропустить' && label === 'Далее';
    previousNextLabel = label;
    if (label !== 'Далее') return stopAttention();
    // An additional choice/keystroke when the action is already Next is not
    // a reason to re-run the decorative animation.
    if (!becameNext || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    stopAttention();
    void nextButton.value?.offsetWidth;
    attention.value = true;
}
watch(nextLabel, label => { if (label !== 'Далее') stopAttention(); });

function validateCurrent() {
    const errors = {};
    for (const field of step.value.fields) {
        const issue = props.validateField(field, props.values, step.value.required.includes(field));
        if (issue) errors[field] = issue;
    }
    issues.value = { ...issues.value, ...errors };
    const first = step.value.fields.find(field => errors[field]);
    if (first) focusError(first);
    return !first;
}
async function focusError(field) {
    emit('invalid', field);
    await nextTick();
    const input = [...(content.value?.querySelectorAll('[name]') || [])]
        .find(el => el.name === field && el.getClientRects().length);
    input?.focus({ preventScroll: true });
    input?.scrollIntoView({ block: 'nearest' });
}
function fieldControls() {
    const names = new Set();
    return [...(content.value?.querySelectorAll('input[name], select[name], textarea[name]') || [])]
        .filter(field => {
            if (field.disabled || field.closest('[inert]') || field.type === 'hidden' ||
                !field.getClientRects().length || names.has(field.name)) return false;
            names.add(field.name);
            return true;
        });
}
async function go(id, focusFirstField = false) {
    stopAttention();
    if (activeId.value !== id) emit('step-change', id);
    activeId.value = id;
    previousNextLabel = nextLabel.value;
    await nextTick();
    if (content.value) content.value.scrollTop = 0;
    // Enter advances to the first field, whereas button/Back navigation
    // retains the original action-focused keyboard behavior.
    (focusFirstField ? fieldControls()[0] || nextButton.value : nextButton.value)
        ?.focus({ preventScroll: true });
}
function validateEnteredField(field) {
    const issue = props.validateField(
        field, props.values, step.value.required?.includes(field) || false,
    );
    if (!issue) {
        delete issues.value[field];
        return true;
    }
    issues.value = { ...issues.value, [field]: issue };
    void focusError(field);
    return false;
}
async function validateAllForSubmit() {
    const errors = {};
    let firstInvalidStep = null;
    let firstInvalidField = null;
    for (const candidate of visibleSteps.value) {
        for (const field of candidate.fields) {
            const issue = props.validateField(
                field, props.values, candidate.required?.includes(field) || false,
            );
            if (!issue) continue;
            errors[field] = issue;
            if (!firstInvalidStep) {
                firstInvalidStep = candidate;
                firstInvalidField = field;
            }
        }
    }
    issues.value = { ...issues.value, ...errors };
    if (!firstInvalidStep) return true;
    if (firstInvalidStep.id !== activeId.value) await go(firstInvalidStep.id);
    await focusError(firstInvalidField);
    return false;
}
// Enter is field-first: validate the current value before moving focus.
// Selects, textareas and native pickers keep their established keyboard behavior.
function handleInputEnter(event) {
    const input = event.target;
    if (event.defaultPrevented || input?.tagName !== 'INPUT' ||
        ['hidden', 'button', 'submit', 'reset', 'file', 'image', 'range', 'color']
            .includes(input.type) || event.isComposing || event.keyCode === 229 ||
        props.busy) return;
    const fields = fieldControls();
    const currentIndex = fields.findIndex(field => field.name === input.name);
    if (currentIndex < 0 || !input.name) return;
    event.preventDefault();
    if (!validateEnteredField(input.name)) return;
    const next = fields[currentIndex + 1];
    if (next) next.focus({ preventScroll: true });
    else void forward(true);
}
function back() {
    if (index.value > 0 && !props.busy) go(visibleSteps.value[index.value - 1].id);
}
async function forward(focusFirstField = false) {
    if (props.busy || !step.value || !validateCurrent()) return;
    if (lastStep.value) {
        if (await validateAllForSubmit()) emit('submit');
    } else {
        await go(visibleSteps.value[index.value + 1].id, focusFirstField);
    }
}
async function applyErrors(errors) {
    issues.value = { ...issues.value, ...errors };
    const target = visibleSteps.value.find(s => s.fields.some(field => issues.value[field]));
    if (!target) return;
    await go(target.id);
    const field = target.fields.find(name => issues.value[name]);
    if (field) await focusError(field);
}
function reset() {
    issues.value = {};
    go(visibleSteps.value[0]?.id);
}
defineExpose({ applyErrors, reset });
</script>

<template>
    <form class="mskba-wizard" novalidate @submit.prevent="forward()" @keydown.enter="handleInputEnter">
        <header class="mskba-wizard__progress">
            <progress :value="index + 1" :max="visibleSteps.length" aria-label="Прогресс мастера" />
            <span role="status" aria-live="polite">ШАГ {{ index + 1 }} / {{ visibleSteps.length }}</span>
        </header>
        <div ref="content" class="mskba-modal__body mskba-scroll mskba-wizard__body" role="region" aria-label="Содержимое шага">
            <h3 class="wizard-step-title">{{ step?.title }}</h3>
            <p v-if="step?.hint" class="wizard-step-hint">{{ step.hint }}</p>
            <div class="wizard-step">
                <slot :name="step?.id" :values="values" :errors="issues" :changed="valueChanged" />
            </div>
            <p v-if="Object.keys(issues).some(field => step?.fields.includes(field))"
                class="mskba-wizard__form-error" role="alert">Проверь выделенные поля.</p>
        </div>
        <footer class="mskba-modal__footer mskba-wizard__footer">
            <div class="wizard-actions">
                <button type="button" class="button secondary" :disabled="busy || index === 0" @click="back">Назад</button>
                <button ref="nextButton" type="submit" class="button primary wizard-next" :disabled="busy"
                    :class="{ 'is-choice-attention': attention }"
                    @animationend="event => { if (event.target === nextButton && event.animationName === 'wizard-next-pulse') stopAttention(); }">
                    <span v-if="busy" class="spinner" aria-hidden="true"></span>
                    {{ busy ? 'Создаём аккаунт…' : nextLabel }}
                </button>
            </div>
            <slot name="footer" />
        </footer>
    </form>
</template>
