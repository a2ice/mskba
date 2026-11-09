<script setup>
import { onMounted, ref } from 'vue';
import UniformKitPreview from './UniformKitPreview.vue';

const projects = ref([]);
const busy = ref(false);
const loading = ref(true);
const error = ref('');
const dialog = ref(null);
const action = ref('');
const selected = ref(null);
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

async function load() {
  try {
    const res = await fetch('/uniforms/projects', {
      credentials: 'same-origin', headers: { Accept: 'application/json' },
    });
    if (!res.ok) throw new Error('Не удалось загрузить формы.');
    projects.value = (await res.json()).designs || [];
  } catch (e) { error.value = e.message; }
  finally { loading.value = false; }
}
onMounted(load);
function open(kind, design) {
  selected.value = design;
  action.value = kind;
  dialog.value?.showModal();
}
function close() { dialog.value?.close(); }
function backdrop(event) {
  if (event.target !== dialog.value) return;
  const rect = dialog.value.getBoundingClientRect();
  if (event.clientX < rect.left || event.clientX > rect.right ||
    event.clientY < rect.top || event.clientY > rect.bottom) close();
}
async function remove() {
  if (!selected.value || busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    const res = await fetch('/uniforms/projects/' + selected.value.id, {
      method: 'DELETE', credentials: 'same-origin',
      headers: {
        Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrf(),
      },
    });
    if (!res.ok) throw new Error('Не удалось удалить форму.');
    projects.value = projects.value.filter(item => item.id !== selected.value.id);
    close();
  } catch (e) { error.value = e.message; }
  finally { busy.value = false; }
}
</script>

<template>
  <div class="mskba-uniform-library">
    <div class="mskba-uniform-library__toolbar">
      <span>{{ projects.length }} {{ projects.length === 1 ? 'форма' : 'форм' }}</span>
      <a href="/uniforms/workshop" class="button primary">Создать форму</a>
    </div>
    <p v-if="loading" role="status">Загружаем…</p>
    <p v-if="error" class="mskba-uniform-editor__error" role="alert">{{ error }}</p>
    <div v-if="!loading && !projects.length" class="mskba-uniform-library__empty">
      <h2>Моих форм пока нет</h2>
      <a href="/uniforms/workshop" class="button primary">Создать форму</a>
    </div>
    <div v-if="projects.length" class="mskba-uniform-library__grid">
      <article v-for="item in projects" :key="item.id" class="mskba-uniform-library__card">
        <div class="mskba-uniform-library__preview">
          <UniformKitPreview :name="item.name" :primary="item.primary_color"
            :secondary="item.secondary_color || ''" :sleeve-style="item.sleeve_style || 'sleeveless'"
            :logo="item.logo_url || ''" :number="item.jersey_number || 1"
            :number-color="item.number_color || ''" :number-outline="!!item.number_outline"
            :number-outline-color="item.number_outline_color || ''"
            :logo-front-position="item.logo_front_position || 'left'"
            :logo-back-position="item.logo_back_position || 'above'"
            :number-enabled="item.number_enabled !== false"
            :jersey-back-number-x="item.jersey_back_number_x ?? 735"
            :jersey-back-number-y="item.jersey_back_number_y ?? 371"
            :jersey-back-number-scale="item.jersey_back_number_scale ?? 1"
            :logo-front-enabled="item.logo_front_enabled ?? (item.logo_front_position !== 'none')"
            :logo-back-enabled="item.logo_back_enabled ?? (item.logo_back_position !== 'none')"
            :logo-shorts-enabled="item.logo_shorts_enabled ?? false"
            :logo-front-x="item.logo_front_x ?? 273" :logo-front-y="item.logo_front_y ?? 256"
            :logo-front-scale="item.logo_front_scale ?? 1"
            :logo-back-x="item.logo_back_x ?? 735" :logo-back-y="item.logo_back_y ?? 245"
            :logo-back-scale="item.logo_back_scale ?? 1"
            :logo-shorts-x="item.logo_shorts_x ?? 275" :logo-shorts-y="item.logo_shorts_y ?? 686"
            :logo-shorts-scale="item.logo_shorts_scale ?? 1"
            :jersey-number-x="item.jersey_number_x ?? 225"
            :jersey-number-y="item.jersey_number_y ?? 394"
            :jersey-number-scale="item.jersey_number_scale ?? 1"
            :shorts-number-x="item.shorts_number_x ?? 178"
            :shorts-number-y="item.shorts_number_y ?? 755"
            :shorts-number-scale="item.shorts_number_scale ?? 1"/>
        </div>
        <div class="mskba-uniform-library__card-info">
          <h2>{{ item.name }}</h2>
          <p>{{ item.number_enabled === false ? 'Без номера' : '№ ' + String(item.jersey_number || 1).padStart(2, '0') }} · {{ item.sizes.join(' · ') }}</p>
          <div class="mskba-uniform-library__actions">
            <a :href="'/uniforms/workshop?project=' + item.id" class="button secondary">Редактировать</a>
            <button type="button" class="button secondary" @click="open('production', item)">В производство</button>
            <button type="button" class="button danger" @click="open('delete', item)">Удалить</button>
          </div>
        </div>
      </article>
    </div>
    <dialog ref="dialog" class="mskba-modal mskba-uniform-action-dialog"
      aria-labelledby="mskba-uniform-action-title" @click="backdrop">
      <header class="mskba-modal__header app-profile__request-header">
        <h2 id="mskba-uniform-action-title" tabindex="-1">
          {{ action === 'delete' ? 'Удалить форму?' : 'Изготовление формы' }}
        </h2>
        <button type="button" class="app-profile__dialog-close" aria-label="Закрыть" @click="close">×</button>
      </header>
      <div class="mskba-modal__body app-profile__request-body mskba-uniform-action-dialog__body">
        <template v-if="action === 'delete'">
          <p>Удалить форму «{{ selected?.name }}»?</p>
          <button type="button" class="button danger" :disabled="busy" :aria-busy="busy" @click="remove">
            {{ busy ? 'Удаляем…' : 'Удалить' }}
          </button>
        </template>
        <template v-else>
          <p>Отправка формы на производство пока недоступна.</p>
          <button type="button" class="button primary" @click="close">Понятно</button>
        </template>
      </div>
    </dialog>
  </div>
</template>
