<script setup>
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import WizardShell from './WizardShell.vue';
import { registrationSteps, validateRegistrationField } from './registrationWizardConfig.js';

const props = defineProps({
    options: { type: Object, required: true },
    busy: { type: Boolean, default: false },
    message: { type: String, default: '' },
    serverErrors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'login', 'step-change']);
const wizard = ref(null);
const expandedGroup = ref('primary');
// The entire label is the help trigger, never the decorative asterisk.
const openRequiredHint = ref('');
const dismissedRequiredHint = ref('');
function toggleRequiredHint(field) {
    dismissedRequiredHint.value = '';
    openRequiredHint.value = openRequiredHint.value === field ? '' : field;
}
function closeRequiredHintOnEscape(event) {
    if (event.key !== 'Escape') return;
    const hovered = document.querySelector('.mskba-wizard .wizard-required-label:hover');
    const target = openRequiredHint.value || hovered?.dataset.requiredField;
    if (!target) return; // Let the native modal handle Escape normally.
    openRequiredHint.value = '';
    dismissedRequiredHint.value = target;
    event.preventDefault();
    event.stopImmediatePropagation();
}
function closeRequiredHintOutside(event) {
    if (!event.target.closest('.mskba-wizard .wizard-required-label')) {
        openRequiredHint.value = '';
    }
}
onMounted(() => {
    document.addEventListener('pointerdown', closeRequiredHintOutside);
    document.addEventListener('keydown', closeRequiredHintOnEscape, true);
});
onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', closeRequiredHintOutside);
    document.removeEventListener('keydown', closeRequiredHintOnEscape, true);
});
const form = reactive({
    role: '', gender: '', birth_date: '', height_cm: '', weight_kg: '',
    position: '', body_type: '', experience_started_year: '',
    last_name: '', first_name: '', middle_name: '',
    username: '', password: '', password_confirmation: '', privacy_consent: false,
});
const groups = [
    { id: 'primary', title: 'Основные', roles: ['player', 'coach', 'referee', 'venue_related'] },
    { id: 'other', title: 'Другие', roles: ['media', 'statistician', 'organizer'] },
];
const displayName = value => value === 'media'
    ? 'Медиа (блогер)' : (props.options.roles?.find(r => r.value === value)?.label || value);
function selectRole(value, changed) {
    form.role = value;
    expandedGroup.value = '';
    changed('role', true);
}
function clearRole(id, changed) {
    form.role = '';
    expandedGroup.value = id;
    changed('role');
}
function focusInvalid(field) {
    if (field !== 'role') return;
    expandedGroup.value = groups.find(g => g.roles.includes(form.role))?.id || 'primary';
}
watch(() => props.serverErrors, async errors => {
    const entries = Object.entries(errors || {});
    if (!entries.length) return;
    const normal = Object.fromEntries(entries.map(([name, messages]) => [
        name, Array.isArray(messages) ? messages[0] : String(messages),
    ]));
    await wizard.value?.applyErrors(normal);
}, { deep: true });
function submit() {
    const payload = {
        role: form.role || null,
        gender: form.role === 'player' ? (form.gender || null) : null,
        birth_date: form.role === 'player' ? (form.birth_date || null) : null,
        first_name: form.first_name || null,
        last_name: form.last_name || null,
        middle_name: form.middle_name || null,
        username: form.username.trim(),
        password: form.password,
        password_confirmation: form.password_confirmation,
        privacy_consent: form.privacy_consent ? '1' : '0',
    };
    if (form.role === 'player') {
        for (const field of ['height_cm', 'weight_kg', 'body_type', 'position', 'experience_started_year']) {
            if (form[field] !== '') payload[field] = form[field];
        }
    }
    emit('submit', payload);
}
</script>

<template>
    <WizardShell ref="wizard" :steps="registrationSteps" :values="form"
        :validate-field="validateRegistrationField" submit-label="Создать аккаунт"
        :busy="busy" @submit="submit" @invalid="focusInvalid"
        @step-change="id => emit('step-change', id)">
        <template #role="{ changed, errors }">
            <fieldset class="wizard-role-options">
                <legend class="sr-only">Роль в баскетболе</legend>
                <div v-for="group in groups" :key="group.id" class="wizard-role-item">
                    <details class="wizard-role-group" :open="expandedGroup === group.id">
                        <summary class="wizard-role-group-toggle"
                            :aria-label="group.title + (group.roles.includes(form.role) ? ', выбрано ' + displayName(form.role) : '')"
                            @click.prevent="expandedGroup = expandedGroup === group.id ? '' : group.id">
                            <span>{{ group.title }}</span>
                            <svg aria-hidden="true"><use href="#chevron-down" /></svg>
                        </summary>
                        <div class="wizard-choice-set">
                            <label v-for="role in group.roles" :key="role" class="wizard-choice">
                                <input type="radio" name="role" :value="role" :checked="form.role === role"
                                    :disabled="busy" @change="selectRole(role, changed)" />
                                <span><strong>{{ displayName(role) }}</strong>
                                    <small>{{ ({
                                        player: 'Играю в баскетбол, участвую в играх и тренировках',
                                        coach: 'Провожу занятия, развиваю игроков',
                                        referee: 'Сужу игры и турниры',
                                        venue_related: 'Управляю или представляю площадку',
                                        media: 'Фото, видео и материалы о баскетболе',
                                        statistician: 'Веду статистику команд и матчей',
                                        organizer: 'Организую игры, тренировки и турниры',
                                    })[role] }}</small>
                                </span>
                            </label>
                        </div>
                    </details>
                    <div v-if="group.roles.includes(form.role)" class="wizard-role-picked">
                        <small>{{ displayName(form.role) }}</small>
                        <button type="button" class="wizard-role-clear" :disabled="busy"
                            :aria-label="'Сбросить роль «' + displayName(form.role) + '»'"
                            @click="clearRole(group.id, changed)">
                            <svg aria-hidden="true"><use href="#close" /></svg>
                        </button>
                    </div>
                </div>
            </fieldset>
            <p v-if="errors.role" class="error" role="alert">{{ errors.role }}</p>
        </template>

        <template #person="{ changed, errors }">
            <div class="field-group">
                <label for="mskba-register-gender">Пол</label>
                <span class="select-control">
                    <select id="mskba-register-gender" v-model="form.gender" name="gender"
                        :disabled="busy" @change="changed('gender', true)">
                        <option value="">Не указывать</option>
                        <option value="male">Мужской</option><option value="female">Женский</option>
                    </select>
                    <svg aria-hidden="true"><use href="#chevron-down" /></svg>
                </span>
                <p v-if="errors.gender" class="error" role="alert">{{ errors.gender }}</p>
            </div>
            <div class="field-group">
                <label for="mskba-register-birth">Дата рождения</label>
                <input id="mskba-register-birth" v-model="form.birth_date" name="birth_date"
                    type="date" :disabled="busy" :aria-invalid="!!errors.birth_date"
                    @input="changed('birth_date')" />
                <p v-if="errors.birth_date" class="error" role="alert">{{ errors.birth_date }}</p>
            </div>
        </template>

        <template #sport="{ changed, errors }">
            <div class="wizard-field-pair">
                <div class="field-group">
                    <label for="mskba-register-height">Рост, см</label>
                    <input id="mskba-register-height" v-model="form.height_cm" name="height_cm"
                        type="number" min="150" max="220" inputmode="numeric" placeholder="180"
                        :aria-invalid="!!errors.height_cm" :disabled="busy" @input="changed('height_cm')" />
                    <p v-if="errors.height_cm" class="error" role="alert">{{ errors.height_cm }}</p>
                </div>
                <div class="field-group">
                    <label for="mskba-register-weight">Вес, кг</label>
                    <input id="mskba-register-weight" v-model="form.weight_kg" name="weight_kg"
                        type="number" min="40" max="140" inputmode="decimal" placeholder="80"
                        :aria-invalid="!!errors.weight_kg" :disabled="busy" @input="changed('weight_kg')" />
                    <p v-if="errors.weight_kg" class="error" role="alert">{{ errors.weight_kg }}</p>
                </div>
            </div>
            <div class="field-group">
                <label for="mskba-register-position">Игровая позиция</label>
                <span class="select-control">
                    <select id="mskba-register-position" v-model="form.position" name="position"
                        :disabled="busy" @change="changed('position', true)">
                        <option value="">Выберу позже</option>
                        <option value="point_guard">Разыгрывающий</option>
                        <option value="shooting_guard">Атакующий защитник</option>
                        <option value="small_forward">Лёгкий форвард</option>
                        <option value="power_forward">Тяжёлый форвард</option>
                        <option value="center">Центровой</option>
                    </select>
                    <svg aria-hidden="true"><use href="#chevron-down" /></svg>
                </span>
            </div>
            <div class="field-group">
                <label for="mskba-register-body">Телосложение</label>
                <span class="select-control">
                    <select id="mskba-register-body" v-model="form.body_type" name="body_type"
                        :disabled="busy" @change="changed('body_type', true)">
                        <option value="">Не указывать</option>
                        <option value="slim">Худощавое</option>
                        <option value="athletic">Атлетичное</option>
                        <option value="muscular">Мускулистое</option>
                        <option value="stocky">Коренастое</option>
                        <option value="large">Крупное</option>
                    </select>
                    <svg aria-hidden="true"><use href="#chevron-down" /></svg>
                </span>
            </div>
            <div class="field-group">
                <label for="mskba-register-experience">Играю с (год)</label>
                <input id="mskba-register-experience" v-model="form.experience_started_year"
                    name="experience_started_year" type="number" inputmode="numeric"
                    :disabled="busy" :aria-invalid="!!errors.experience_started_year"
                    @input="changed('experience_started_year')" />
                <p v-if="errors.experience_started_year" class="error" role="alert">{{ errors.experience_started_year }}</p>
            </div>
        </template>

        <template #name="{ changed, errors }">
            <div v-for="field in ['last_name', 'first_name', 'middle_name']" :key="field" class="field-group">
                <label :for="'mskba-register-' + field">{{ ({
                    last_name: 'Фамилия', first_name: 'Имя', middle_name: 'Отчество',
                })[field] }}</label>
                <input :id="'mskba-register-' + field" v-model="form[field]" :name="field"
                    :disabled="busy" maxlength="255" @input="changed(field)" />
                <p v-if="errors[field]" class="error" role="alert">{{ errors[field] }}</p>
            </div>
        </template>

        <template #account="{ changed, errors }">
            <div class="field-group">
                <label for="mskba-register-username" class="wizard-required-label"
                    data-tooltip="Обязательное поле" data-required-field="username"
                    :class="{ 'is-tooltip-open': openRequiredHint === 'username', 'is-tooltip-dismissed': dismissedRequiredHint === 'username' }"
                    @pointerleave="dismissedRequiredHint = ''"
                    @click="toggleRequiredHint('username')">
                    Логин <span class="wizard-required" aria-hidden="true">*</span>
                </label>
                <input id="mskba-register-username" v-model="form.username" name="username"
                    autocomplete="username" required minlength="3" maxlength="32"
                    :disabled="busy" :aria-invalid="!!errors.username" @input="changed('username')" />
                <p v-if="errors.username" class="error" role="alert">{{ errors.username }}</p>
            </div>
            <div class="field-group">
                <label for="mskba-register-password" class="wizard-required-label"
                    data-tooltip="Обязательное поле" data-required-field="password"
                    :class="{ 'is-tooltip-open': openRequiredHint === 'password', 'is-tooltip-dismissed': dismissedRequiredHint === 'password' }"
                    @pointerleave="dismissedRequiredHint = ''"
                    @click="toggleRequiredHint('password')">
                    Пароль <span class="wizard-required" aria-hidden="true">*</span>
                </label>
                <input id="mskba-register-password" v-model="form.password" name="password"
                    type="password" autocomplete="new-password" required minlength="6"
                    :disabled="busy" :aria-invalid="!!errors.password" @input="changed('password')" />
                <p v-if="errors.password" class="error" role="alert">{{ errors.password }}</p>
            </div>
            <div class="field-group">
                <label for="mskba-register-confirm" class="wizard-required-label"
                    data-tooltip="Обязательное поле" data-required-field="password_confirmation"
                    :class="{ 'is-tooltip-open': openRequiredHint === 'password_confirmation', 'is-tooltip-dismissed': dismissedRequiredHint === 'password_confirmation' }"
                    @pointerleave="dismissedRequiredHint = ''"
                    @click="toggleRequiredHint('password_confirmation')">
                    Подтвердите пароль <span class="wizard-required" aria-hidden="true">*</span>
                </label>
                <input id="mskba-register-confirm" v-model="form.password_confirmation" name="password_confirmation"
                    type="password" autocomplete="new-password" required :disabled="busy"
                    :aria-invalid="!!errors.password_confirmation" @input="changed('password_confirmation')" />
                <p v-if="errors.password_confirmation" class="error" role="alert">{{ errors.password_confirmation }}</p>
            </div>
            <label class="mskba-auth-consent wizard-required-label"
                data-tooltip="Обязательное поле" data-required-field="privacy_consent"
                :class="{ 'is-tooltip-open': openRequiredHint === 'privacy_consent', 'is-tooltip-dismissed': dismissedRequiredHint === 'privacy_consent' }"
                @pointerleave="dismissedRequiredHint = ''"
                @click="toggleRequiredHint('privacy_consent')">
                <input v-model="form.privacy_consent" type="checkbox" name="privacy_consent"
                    required :disabled="busy" @change="changed('privacy_consent', true)" />
                <span>Я даю <a :href="options.consent" target="_blank" rel="noopener">согласие на обработку персональных данных</a>
                    <span class="wizard-required" aria-hidden="true">*</span></span>
            </label>
            <p v-if="errors.privacy_consent" class="error" role="alert">{{ errors.privacy_consent }}</p>
            <p class="mskba-auth-small">Подробнее в <a :href="options.privacyPolicy" target="_blank" rel="noopener">политике конфиденциальности</a>.</p>
            <p v-if="message" class="mskba-auth-message" role="alert">{{ message }}</p>
        </template>

        <template #footer>
            <p class="mskba-auth-footer">Уже есть аккаунт?
                <button type="button" class="mskba-auth-link" :disabled="busy" @click="emit('login')">Войти</button>
            </p>
        </template>
    </WizardShell>
</template>
