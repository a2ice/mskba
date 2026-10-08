<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import RegistrationWizard from './RegistrationWizard.vue';

const props = defineProps({
    options: { type: Object, required: true },
});

const opened = ref(false);
const mode = ref('login');
const panel = ref(null);
const telegramContainer = ref(null);
const busy = ref(false);
const message = ref('');
const transientMessage = ref(false);
const errors = ref({});
const loginForm = reactive({ login: '', password: '', remember: false });
const restoreForm = reactive({ contact: '' });
let restoreFocus = null;
let previousOverflow = '';
let currentRequest = null;

const title = computed(() => ({
    login: 'Вход в аккаунт',
    register: 'Регистрация',
    restore: 'Восстановление доступа',
})[mode.value]);

const locationPath = () => window.location.pathname + window.location.search + window.location.hash;
const vkUrl = computed(() => {
    if (!props.options.vk) return null;
    const url = new URL(props.options.vk, window.location.origin);
    url.searchParams.set('redirect_to', locationPath());
    return url.href;
});

function localRedirect(raw) {
    try {
        const url = new URL(String(raw || props.options.account), window.location.origin);
        return url.origin === window.location.origin && /^https?:$/.test(url.protocol)
            ? url.href
            : new URL(props.options.account, window.location.origin).href;
    } catch {
        return new URL(props.options.account, window.location.origin).href;
    }
}

function selectMode(nextMode) {
    mode.value = ['login', 'register', 'restore'].includes(nextMode) ? nextMode : 'login';
    transientMessage.value = false;
    message.value = '';
    errors.value = {};
    nextTick(() => panel.value?.querySelector('input:not([type="hidden"])')?.focus());
}

async function openDialog(nextMode = 'login') {
    if (opened.value) {
        selectMode(nextMode);
        return;
    }
    restoreFocus = document.activeElement;
    previousOverflow = document.body.style.overflow;
    mode.value = ['login', 'register', 'restore'].includes(nextMode) ? nextMode : 'login';
    transientMessage.value = false;
    message.value = '';
    errors.value = {};
    opened.value = true;
    await nextTick();
    if (!panel.value) return;
    // showModal enters the browser's top layer, above all CSS z-index stacks.
    panel.value.showModal();
    document.body.style.overflow = 'hidden';
    panel.value.querySelector('input:not([type="hidden"])')?.focus();
}

function closeDialog() {
    if (busy.value) return;
    if (panel.value?.open) panel.value.close();
    opened.value = false;
    document.body.style.overflow = previousOverflow;
    transientMessage.value = false;
    message.value = '';
    errors.value = {};
    nextTick(() => restoreFocus?.focus?.());
}

function onDialogBackdropClick(event) {
    const dialog = panel.value;
    if (!dialog || event.target !== dialog) return;
    const rect = dialog.getBoundingClientRect();
    if (event.clientX < rect.left || event.clientX > rect.right ||
        event.clientY < rect.top || event.clientY > rect.bottom) closeDialog();
}

function onDocumentClick(event) {
    const button = event.target.closest?.('[data-auth-trigger]');
    if (!button || !document.getElementById('mskba-auth-dialog-root')) return;
    event.preventDefault();
    openDialog(button.dataset.authMode || 'login');
}

function onDialogEvent(event) {
    openDialog(event.detail?.mode || 'login');
}

// Errors caused by one submission belong to that attempt, not to the form
// values. Field-level 422 messages remain in WizardShell until corrected.
function dismissTransientMessage() {
    if (!transientMessage.value) return;
    transientMessage.value = false;
    message.value = '';
}

async function post(endpoint, form) {
    if (busy.value) return;
    busy.value = true;
    transientMessage.value = false;
    message.value = '';
    errors.value = {};
    currentRequest = new AbortController();
    try {
        const response = await fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            signal: currentRequest.signal,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ ...form, redirect_to: locationPath() }),
        });
        let result = {};
        try { result = await response.json(); } catch { /* non-JSON upstream error */ }
        if (response.ok && result.redirect_url) {
            window.location.assign(localRedirect(result.redirect_url));
            return;
        }
        if (response.ok) {
            transientMessage.value = true;
            message.value = 'Ответ получен, но адрес перехода отсутствует. Обновите страницу.';
            return;
        }

        // A 422 with field violations describes the entered values and belongs
        // to WizardShell. Never merge it with a stale submission-level alert.
        const fieldErrors = response.status === 422 && result.errors &&
            typeof result.errors === 'object' && !Array.isArray(result.errors)
            ? result.errors : {};
        if (Object.keys(fieldErrors).length > 0) {
            errors.value = fieldErrors;
            return;
        }

        transientMessage.value = true;
        // Never render an upstream/server exception (including SQLSTATE,
        // stack traces or database details) as user-visible copy.
        message.value = ({
            401: 'Неверный логин, контакт или пароль.',
            403: 'Недостаточно прав для выполнения операции.',
            419: 'Сессия истекла. Обновите страницу и попробуйте снова.',
            422: 'Не удалось проверить данные. Проверьте введённые значения.',
            429: 'Слишком много попыток. Попробуйте позже.',
            503: 'Сервис временно недоступен. Попробуйте позже.',
        })[response.status] || (response.status >= 500
            ? (mode.value === 'register'
                ? 'Не удалось создать аккаунт. Произошла ошибка на сервере. Введённые данные сохранены. Попробуйте ещё раз позже.'
                : 'Произошла ошибка на сервере. Попробуйте ещё раз позже.')
            : 'Не удалось выполнить запрос. Попробуйте ещё раз.');
    } catch (error) {
        if (error.name !== 'AbortError') {
            transientMessage.value = true;
            message.value = 'Не удалось связаться с сервером. Проверьте подключение.';
        }
    } finally {
        busy.value = false;
        currentRequest = null;
    }
}

async function submitLogin() {
    await post(props.options.login, {
        login: loginForm.login, password: loginForm.password,
        remember: loginForm.remember,
    });
}

async function submitRegister(payload) {
    await post(props.options.register, payload);
}

async function submitRestore() {
    await post(props.options.restore, { contact: restoreForm.contact });
}

async function telegramAuth(user) {
    await post(props.options.telegramLogin, { telegram_user: user });
}

function loadTelegramWidget() {
    if (!opened.value || mode.value !== 'login' || !props.options.telegramBot || !telegramContainer.value) return;
    const script = document.createElement('script');
    script.async = true;
    script.src = 'https://telegram.org/js/telegram-widget.js?22';
    script.setAttribute('data-telegram-login', props.options.telegramBot);
    script.setAttribute('data-size', 'large');
    script.setAttribute('data-radius', '10');
    script.setAttribute('data-userpic', 'false');
    script.setAttribute('data-onauth', 'mskbaAppTelegramAuth(user)');
    telegramContainer.value.appendChild(script);
}

watch([opened, mode], async () => {
    await nextTick();
    loadTelegramWidget();
});

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    window.addEventListener('mskba:auth:open', onDialogEvent);
    window.mskbaAppTelegramAuth = telegramAuth;
    const initial = document.querySelector('[data-auth-open-on-load]');
    if (initial) openDialog(initial.dataset.authOpenOnLoad || 'login');
});
onBeforeUnmount(() => {
    currentRequest?.abort();
    document.removeEventListener('click', onDocumentClick);
    window.removeEventListener('mskba:auth:open', onDialogEvent);
    delete window.mskbaAppTelegramAuth;
    if (panel.value?.open) panel.value.close();
    if (opened.value) document.body.style.overflow = previousOverflow;
});
</script>

<template>
    <Teleport to="body">
        <dialog v-if="opened" ref="panel" class="mskba-modal mskba-auth-dialog" :class="{ 'mskba-auth-register': mode === 'register' }"
            aria-labelledby="mskba-auth-title" :aria-busy="busy"
            @cancel.prevent="closeDialog" @click="onDialogBackdropClick">
            <header class="mskba-modal__header mskba-auth-top">
                <h2 id="mskba-auth-title" class="eyebrow accent mskba-auth-heading">{{ title }}</h2>
                <button type="button" class="icon-button mskba-auth-close" aria-label="Закрыть окно"
                    :disabled="busy" @click="closeDialog">
                    <svg aria-hidden="true"><use href="#close" /></svg>
                </button>
            </header>

            <form v-if="mode === 'login'" class="mskba-auth-form" @submit.prevent="submitLogin">
                <div class="mskba-modal__body mskba-scroll mskba-auth-scroll" role="region" aria-label="Поля входа" tabindex="0">
                    <label>Логин или подтверждённый контакт
                        <input v-model.trim="loginForm.login" type="text" name="login" autocomplete="username"
                            required minlength="3" maxlength="255" :disabled="busy" />
                        <span v-if="errors.login" class="mskba-auth-field-error">{{ errors.login[0] }}</span>
                    </label>
                    <label>Пароль
                        <input v-model="loginForm.password" type="password" name="password"
                            autocomplete="current-password" required :disabled="busy" />
                        <span v-if="errors.password" class="mskba-auth-field-error">{{ errors.password[0] }}</span>
                    </label>
                    <div class="mskba-auth-options">
                        <label class="mskba-auth-remember"><input v-model="loginForm.remember" type="checkbox" :disabled="busy" /> Запомнить меня</label>
                        <button type="button" class="mskba-auth-link" :disabled="busy" @click="selectMode('restore')">Забыли пароль?</button>
                    </div>
                    <p v-if="message" role="alert" class="mskba-auth-message">{{ message }}</p>
                    <template v-if="vkUrl || options.telegramBot">
                        <p class="mskba-auth-separator">или быстрый вход через</p>
                        <div class="mskba-auth-providers">
                            <a v-if="vkUrl" class="button secondary" :href="vkUrl">ВКонтакте</a>
                            <div v-if="options.telegramBot" ref="telegramContainer" class="mskba-auth-telegram"></div>
                        </div>
                    </template>
                </div>
                <footer class="mskba-modal__footer mskba-auth-footer-actions">
                    <button class="button primary full" type="submit" :disabled="busy">
                        <span v-if="busy" class="spinner" aria-hidden="true"></span>{{ busy ? 'Входим…' : 'Войти' }}
                        <svg v-if="!busy" aria-hidden="true"><use href="#arrow" /></svg>
                    </button>
                    <p class="mskba-auth-footer">Ещё нет аккаунта?
                        <button type="button" class="mskba-auth-link" :disabled="busy" @click="selectMode('register')">Зарегистрироваться</button>
                    </p>
                </footer>
            </form>

            <RegistrationWizard v-else-if="mode === 'register'"
                :options="options" :busy="busy" :server-errors="errors" :message="message"
                @submit="submitRegister" @login="selectMode('login')"
                @step-change="dismissTransientMessage" />

            <form v-else class="mskba-auth-form" @submit.prevent="submitRestore">
                <div class="mskba-modal__body mskba-scroll mskba-auth-scroll" role="region" aria-label="Восстановление доступа" tabindex="0">
                    <label>Email
                        <input v-model.trim="restoreForm.contact" type="email" autocomplete="email"
                            required :disabled="busy" />
                        <span v-if="errors.contact" class="mskba-auth-field-error">{{ errors.contact[0] }}</span>
                    </label>
                    <p class="mskba-auth-small">Восстановление пока недоступно: сервер предложит обратиться в поддержку.</p>
                    <p v-if="message" role="alert" class="mskba-auth-message">{{ message }}</p>
                </div>
                <footer class="mskba-modal__footer mskba-auth-footer-actions">
                    <button class="button primary full" type="submit" :disabled="busy">
                        <span v-if="busy" class="spinner" aria-hidden="true"></span>{{ busy ? 'Проверяем…' : 'Восстановить доступ' }}
                    </button>
                    <p class="mskba-auth-footer">
                        <button type="button" class="mskba-auth-link" :disabled="busy" @click="selectMode('login')">Вернуться ко входу</button>
                    </p>
                </footer>
            </form>
        </dialog>
    </Teleport>
</template>
