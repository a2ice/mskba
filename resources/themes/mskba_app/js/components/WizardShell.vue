<script setup>
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps({
    steps: { type: Array, required: true },
    values: { type: Object, required: true },
    validateField: { type: Function, required: true },
    submitLabel: { type: String, default: 'Готово' },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['submit', 'invalid']);
const activeId = ref(props.steps[0]?.id);
const issues = ref({});
const content = ref(null);
const stepTitle = ref(null);
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

watch(visibleSteps, steps => {
    if (!steps.some(s => s.id === activeId.value)) activeId.value = steps[0]?.id;
});
function stopAttention() {
    attention.value = false;
}
function valueChanged(field, choice = false) {
    delete issues.value[field];
    // Called from actual UI interaction, never from step navigation.
    if (nextLabel.value !== 'Далее') return stopAttention();
    if (!choice && !hasValues.value) return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
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
async function go(id) {
    stopAttention();
    activeId.value = id;
    await nextTick();
    if (content.value) content.value.scrollTop = 0;
    stepTitle.value?.focus({ preventScroll: true });
}
function back() {
    if (index.value > 0 && !props.busy) go(visibleSteps.value[index.value - 1].id);
}
function forward() {
    if (props.busy || !step.value || !validateCurrent()) return;
    if (lastStep.value) emit('submit');
    else go(visibleSteps.value[index.value + 1].id);
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
    <form class="mskba-wizard" novalidate @submit.prevent="forward">
        <header class="mskba-wizard__progress">
            <progress :value="index + 1" :max="visibleSteps.length" aria-label="Прогресс мастера" />
            <span role="status" aria-live="polite">ШАГ {{ index + 1 }} / {{ visibleSteps.length }}</span>
        </header>
        <div ref="content" class="mskba-modal__body mskba-scroll mskba-wizard__body" role="region" aria-label="Содержимое шага" tabindex="0">
            <h3 ref="stepTitle" class="wizard-step-title" tabindex="-1">{{ step?.title }}</h3>
            <p v-if="step?.hint" class="wizard-step-hint">{{ step.hint }}</p>
            <div class="wizard-step">
                <slot :name="step?.id" :values="values" :errors="issues" :changed="valueChanged" />
            </div>
            <p v-if="Object.keys(issues).some(field => step?.fields.includes(field))"
                class="mskba-wizard__form-error" role="alert">Проверьте выделенные поля.</p>
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
