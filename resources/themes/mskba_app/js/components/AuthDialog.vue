<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    options: { type: Object, required: true },
});

const opened = ref(false);
const mode = ref('login');
const panel = ref(null);
const telegramContainer = ref(null);
const busy = ref(false);
const message = ref('');
const errors = ref({});
const loginForm = reactive({ login: '', password: '', remember: false });
const registerForm = reactive({
    username: '', password: '', password_confirmation: '', role: '', privacy_consent: false,
});
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
    message.value = '';
    errors.value = {};
    nextTick(() => panel.value?.querySelector('input:not([type="hidden"])')?.focus());
}

function openDialog(nextMode = 'login') {
    if (opened.value) {
        selectMode(nextMode);
        return;
    }
    restoreFocus = document.activeElement;
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    opened.value = true;
    selectMode(nextMode);
}

function closeDialog() {
    if (busy.value) return;
    opened.value = false;
    document.body.style.overflow = previousOverflow;
    message.value = '';
    errors.value = {};
    nextTick(() => restoreFocus?.focus?.());
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

function onDocumentKeydown(event) {
    if (!opened.value) return;
    if (event.key === 'Escape') {
        event.preventDefault();
        closeDialog();
        return;
    }
    if (event.key !== 'Tab' || !panel.value) return;
    const focusable = [...panel.value.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled])')]
        .filter(el => el.getClientRects().length > 0);
    if (!focusable.length) {
        event.preventDefault();
        panel.value.focus();
        return;
    }
    const first = focusable[0];
    const last = focusable.at(-1);
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

async function post(endpoint, form) {
    if (busy.value) return;
    busy.value = true;
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
            message.value = 'Ответ получен, но адрес перехода отсутствует. Обновите страницу.';
            return;
        }
        errors.value = result.errors || {};
        message.value = result.message || ({
            419: 'Сессия истекла. Обновите страницу и попробуйте снова.',
            429: 'Слишком много попыток. Попробуйте позже.',
            503: 'Функция пока недоступна. Обратитесь в поддержку.',
        }[response.status] || 'Не удалось выполнить запрос. Попробуйте ещё раз.');
    } catch (error) {
        if (error.name !== 'AbortError') {
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

async function submitRegister() {
    await post(props.options.register, {
        ...registerForm, privacy_consent: registerForm.privacy_consent ? '1' : '0',
    });
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
    document.addEventListener('keydown', onDocumentKeydown);
    window.addEventListener('mskba:auth:open', onDialogEvent);
    window.mskbaAppTelegramAuth = telegramAuth;
    const initial = document.querySelector('[data-auth-open-on-load]');
    if (initial) openDialog(initial.dataset.authOpenOnLoad || 'login');
});
onBeforeUnmount(() => {
    currentRequest?.abort();
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onDocumentKeydown);
    window.removeEventListener('mskba:auth:open', onDialogEvent);
    delete window.mskbaAppTelegramAuth;
    document.body.style.overflow = previousOverflow;
});
</script>

<template>
    <Teleport to="body">
        <div v-if="opened" class="mskba-auth-overlay" @pointerdown.self="closeDialog">
            <section ref="panel" class="mskba-auth-dialog" role="dialog" aria-modal="true"
                aria-labelledby="mskba-auth-title" :aria-busy="busy" tabindex="-1">
                <header class="mskba-auth-top">
                    <h2 id="mskba-auth-title" class="eyebrow accent mskba-auth-heading">{{ title }}</h2>
                    <button type="button" class="icon-button mskba-auth-close" aria-label="Закрыть окно"
                        :disabled="busy" @click="closeDialog">
                        <svg aria-hidden="true"><use href="#close" /></svg>
                    </button>
                </header>

                <form v-if="mode === 'login'" class="mskba-auth-form" @submit.prevent="submitLogin">
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
                    <button class="button primary full" type="submit" :disabled="busy">
                        <span v-if="busy" class="spinner" aria-hidden="true"></span>{{ busy ? 'Входим…' : 'Войти' }}
                        <svg v-if="!busy" aria-hidden="true"><use href="#arrow" /></svg>
                    </button>
                    <template v-if="vkUrl || options.telegramBot">
                        <p class="mskba-auth-separator">или быстрый вход через</p>
                        <div class="mskba-auth-providers">
                            <a v-if="vkUrl" class="button secondary" :href="vkUrl">ВКонтакте</a>
                            <div v-if="options.telegramBot" ref="telegramContainer" class="mskba-auth-telegram"></div>
                        </div>
                    </template>
                    <p class="mskba-auth-footer">Ещё нет аккаунта?
                        <button type="button" class="mskba-auth-link" :disabled="busy" @click="selectMode('register')">Зарегистрироваться</button>
                    </p>
                </form>

                <form v-else-if="mode === 'register'" class="mskba-auth-form" @submit.prevent="submitRegister">
                    <label>Логин
                        <input v-model.trim="registerForm.username" type="text" name="username"
                            autocomplete="username" required :disabled="busy" />
                        <span v-if="errors.username" class="mskba-auth-field-error">{{ errors.username[0] }}</span>
                    </label>
                    <label>Пароль
                        <input v-model="registerForm.password" type="password" name="password"
                            autocomplete="new-password" required :disabled="busy" />
                        <span v-if="errors.password" class="mskba-auth-field-error">{{ errors.password[0] }}</span>
                    </label>
                    <label>Подтвердите пароль
                        <input v-model="registerForm.password_confirmation" type="password"
                            name="password_confirmation" autocomplete="new-password" required :disabled="busy" />
                    </label>
                    <label>Роль в баскетболе
                        <span class="select-control">
                            <select v-model="registerForm.role" name="role" :disabled="busy">
                                <option value="">Выбрать позже</option>
                                <option v-for="role in options.roles" :key="role.value" :value="role.value">{{ role.label }}</option>
                            </select>
                            <svg aria-hidden="true" focusable="false"><use href="#chevron-down" /></svg>
                        </span>
                    </label>
                    <label class="mskba-auth-consent">
                        <input v-model="registerForm.privacy_consent" type="checkbox" required :disabled="busy" />
                        <span>Я даю <a :href="options.consent" target="_blank" rel="noopener">согласие на обработку персональных данных</a>.</span>
                    </label>
                    <p class="mskba-auth-small">Подробнее в <a :href="options.privacyPolicy" target="_blank" rel="noopener">политике конфиденциальности</a>.</p>
                    <p v-if="message" role="alert" class="mskba-auth-message">{{ message }}</p>
                    <button class="button primary full" type="submit" :disabled="busy">
                        <span v-if="busy" class="spinner" aria-hidden="true"></span>{{ busy ? 'Регистрируем…' : 'Создать аккаунт' }}
                    </button>
                    <p class="mskba-auth-footer">Уже есть аккаунт?
                        <button type="button" class="mskba-auth-link" :disabled="busy" @click="selectMode('login')">Войти</button>
                    </p>
                </form>

                <form v-else class="mskba-auth-form" @submit.prevent="submitRestore">
                    <label>Email
                        <input v-model.trim="restoreForm.contact" type="email" autocomplete="email"
                            required :disabled="busy" />
                        <span v-if="errors.contact" class="mskba-auth-field-error">{{ errors.contact[0] }}</span>
                    </label>
                    <p class="mskba-auth-small">Восстановление пока недоступно: сервер предложит обратиться в поддержку.</p>
                    <p v-if="message" role="alert" class="mskba-auth-message">{{ message }}</p>
                    <button class="button primary full" type="submit" :disabled="busy">
                        <span v-if="busy" class="spinner" aria-hidden="true"></span>{{ busy ? 'Проверяем…' : 'Восстановить доступ' }}
                    </button>
                    <p class="mskba-auth-footer">
                        <button type="button" class="mskba-auth-link" :disabled="busy" @click="selectMode('login')">Вернуться ко входу</button>
                    </p>
                </form>
            </section>
        </div>
    </Teleport>
</template>
