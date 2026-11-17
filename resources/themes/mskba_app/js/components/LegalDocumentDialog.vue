<script setup>
import { nextTick, onBeforeUnmount, ref } from 'vue';

const props = defineProps({
    options: { type: Object, required: true },
});

const panel = ref(null);
const opened = ref(false);
const selected = ref('consent');
const content = ref('');
const loading = ref(false);
const failed = ref(false);
const documents = {
    consent: 'Согласие на обработку персональных данных',
    privacy: 'Политика конфиденциальности',
};
const cache = new Map();
let request = null;
let originalFocus = null;

async function loadDocument(kind) {
    request?.abort();
    content.value = '';
    failed.value = false;

    if (cache.has(kind)) {
        loading.value = false;
        content.value = cache.get(kind);
        return;
    }

    const endpoint = props.options.legalFragments?.[kind];
    if (!endpoint) {
        loading.value = false;
        failed.value = true;
        return;
    }

    const controller = new AbortController();
    request = controller;
    loading.value = true;
    try {
        // Endpoints are a fixed two-document allowlist passed by Laravel.
        // HTML is exclusively trusted server-rendered Blade, never user input.
        const response = await fetch(endpoint, {
            credentials: 'same-origin',
            headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal,
        });
        if (!response.ok || !response.headers.get('Content-Type')?.includes('text/html')) {
            throw new Error('Document unavailable');
        }
        const html = await response.text();
        if (controller.signal.aborted) return;
        cache.set(kind, html);
        content.value = html;
    } catch (error) {
        if (error.name !== 'AbortError') failed.value = true;
    } finally {
        if (request === controller) {
            loading.value = false;
            request = null;
        }
    }
}

async function openDocument(kind, trigger) {
    if (!Object.hasOwn(documents, kind)) return;
    if (!opened.value) {
        originalFocus = trigger || document.activeElement;
        opened.value = true;
        await nextTick();
        if (!opened.value || !panel.value) return;
        panel.value.showModal(); // native top layer ABOVE the registration dialog
        panel.value.querySelector('[data-legal-close]')?.focus();
    }
    selected.value = kind;
    panel.value?.querySelector('.mskba-modal__body')?.scrollTo(0, 0);
    await loadDocument(kind);
}

function closeDocument() {
    request?.abort();
    request = null;
    if (panel.value?.open) panel.value.close();
    opened.value = false;
    loading.value = false;
    content.value = '';
    failed.value = false;
    const focusTarget = originalFocus;
    originalFocus = null;
    nextTick(() => focusTarget?.isConnected && focusTarget.focus?.());
}

function onBackdropClick(event) {
    const dialog = panel.value;
    if (!dialog || event.target !== dialog) return;
    const rect = dialog.getBoundingClientRect();
    if (event.clientX < rect.left || event.clientX > rect.right ||
        event.clientY < rect.top || event.clientY > rect.bottom) closeDocument();
}

function onContentClick(event) {
    const link = event.target.closest?.('a[href]');
    if (!link) return;
    const url = new URL(link.href, window.location.origin);
    if (url.origin !== window.location.origin) return;

    // Fragment-only TOC links must scroll the policy inside the dialog,
    // never update the underlying /register URL or background scroll.
    if (link.getAttribute('href')?.startsWith('#') && selected.value === 'privacy') {
        event.preventDefault();
        const id = decodeURIComponent(url.hash.slice(1));
        const target = [...panel.value.querySelectorAll('[id]')].find(node => node.id === id);
        target?.scrollIntoView({ block: 'start', behavior: 'auto' });
        return;
    }

    if (url.pathname === props.options.consent || url.pathname === props.options.privacyPolicy) {
        event.preventDefault();
        const next = url.pathname === props.options.consent ? 'consent' : 'privacy';
        if (next !== selected.value) openDocument(next);
    }
}

onBeforeUnmount(() => {
    request?.abort();
    if (panel.value?.open) panel.value.close();
});

defineExpose({ openDocument, closeDocument });
</script>

<template>
    <Teleport to="body">
        <dialog v-if="opened" ref="panel" class="mskba-modal mskba-legal-dialog"
            aria-labelledby="mskba-legal-dialog-title"
            @cancel.prevent="closeDocument" @click="onBackdropClick">
            <header class="mskba-modal__header mskba-legal-dialog__header">
                <h2 id="mskba-legal-dialog-title">{{ documents[selected] }}</h2>
                <button type="button" class="icon-button" data-legal-close
                    aria-label="Закрыть документ и вернуться к регистрации" @click="closeDocument">
                    <svg aria-hidden="true"><use href="#close" /></svg>
                </button>
            </header>
            <div class="mskba-modal__body mskba-scroll mskba-legal-dialog__body"
                role="region" :aria-label="documents[selected]" tabindex="0"
                @click="onContentClick">
                <p v-if="loading" class="mskba-legal-dialog__notice" role="status">
                    Загружаем документ…
                </p>
                <div v-else-if="failed" class="mskba-legal-dialog__notice" role="alert">
                    <p>Не удалось загрузить документ. Попробуйте снова или откройте полную страницу.</p>
                    <button class="button secondary" type="button" @click="loadDocument(selected)">
                        Повторить
                    </button>
                    <a :href="selected === 'consent' ? options.consent : options.privacyPolicy"
                        target="_blank" rel="noopener noreferrer">Открыть документ отдельно</a>
                </div>
                <!-- Trusted Blade fragment from fixed same-origin route, not arbitrary HTML. -->
                <div v-else class="mskba-legal-dialog__content" v-html="content"></div>
            </div>
            <footer class="mskba-modal__footer mskba-legal-dialog__footer">
                <button type="button" class="button secondary" @click="closeDocument">
                    Вернуться к регистрации
                </button>
            </footer>
        </dialog>
    </Teleport>
</template>
