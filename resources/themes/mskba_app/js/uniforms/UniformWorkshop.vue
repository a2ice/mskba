<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import UniformKitPreview from './UniformKitPreview.vue';

const props = defineProps({
  initialId: { type: [String, Number], default: null },
  context: { type: String, default: 'page' },
});
const sizeOptions = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
const form = reactive({ name: 'Моя форма', primary_color: '#111924', secondary_color: '#F58220', sizes: ['M','L'], logo_source: 'none', logo_team_id: '' });
const logoFile = ref(null);
const fileUrl = ref('');
const storedLogoUrl = ref('');
const teams = ref([]);
const authenticated = ref(document.querySelector('meta[name="mskba-authenticated"]')?.content === '1');
const busy = ref(false);
const loading = ref(false);
const error = ref('');
const notice = ref('');
const editId = ref(props.initialId || null);
const selectedTeam = computed(() => teams.value.find(t => String(t.id) === String(form.logo_team_id)));
const logoUrl = computed(() => form.logo_source === 'custom'
    ? (fileUrl.value || storedLogoUrl.value)
    : form.logo_source === 'team' ? (selectedTeam.value?.logo_url || '') : '');
const subtitle = computed(() => editId.value ? 'Редактирование сохранённой формы' : 'Новый дизайн');
const toggleSize = size => {
  const idx = form.sizes.indexOf(size);
  if (idx >= 0) form.sizes.splice(idx, 1);
  else form.sizes.push(size);
};
function onFile(event) {
  error.value = '';
  const file = event.target.files?.[0] || null;
  if (file && (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024)) {
    error.value = 'Нужен PNG, JPG или WebP размером не более 2 МБ.';
    event.target.value = '';
    return;
  }
  if (fileUrl.value) URL.revokeObjectURL(fileUrl.value);
  logoFile.value = file;
  fileUrl.value = file ? URL.createObjectURL(file) : '';
}
function hydrate(design) {
  editId.value = design.id;
  form.name = design.name;
  form.primary_color = design.primary_color;
  form.secondary_color = design.secondary_color;
  form.sizes = [...design.sizes];
  form.logo_source = design.logo_source;
  form.logo_team_id = design.logo_team_id || '';
  storedLogoUrl.value = design.logo_url || '';
}
onMounted(async () => {
  loading.value = true;
  try {
    const response = await fetch('/uniforms/options', {headers:{Accept:'application/json'},credentials:'same-origin'});
    if (!response.ok) throw new Error('Не удалось получить доступные логотипы.');
    const data = await response.json();
    authenticated.value = !!data.authenticated;
    teams.value = data.teams || [];
    if (editId.value) {
      const result = await fetch('/uniforms/projects/' + encodeURIComponent(editId.value), {headers:{Accept:'application/json'},credentials:'same-origin'});
      if (!result.ok) throw new Error(result.status === 404 ? 'Форма недоступна.' : 'Не удалось загрузить форму.');
      hydrate((await result.json()).design);
    }
  } catch (e) { error.value = e.message; }
  finally { loading.value = false; }
});
onBeforeUnmount(() => { if (fileUrl.value) URL.revokeObjectURL(fileUrl.value); });

const preparePendingFile = file => new Promise((resolve, reject) => {
  if (!file) return resolve(null);
  const reader = new FileReader();
  reader.onload = () => resolve(reader.result);
  reader.onerror = () => reject(new Error('Не удалось подготовить логотип перед входом.'));
  reader.readAsDataURL(file);
});
async function savePendingDraft() {
  const logo_data = form.logo_source === 'custom' ? await preparePendingFile(logoFile.value) : null;
  try {
    sessionStorage.setItem('mskba.uniform.pending.v1', JSON.stringify({
      name:form.name, primary_color:form.primary_color, secondary_color:form.secondary_color,
      sizes:[...form.sizes], logo_source:form.logo_source, logo_team_id:form.logo_team_id || null,
      logo_data,
    }));
  } catch {
    throw new Error('Не хватает памяти браузера для сохранения черновика перед входом. Уменьшите размер логотипа.');
  }
}
async function save() {
  error.value = ''; notice.value = '';
  if (!form.name.trim() || form.name.trim().length < 2) { error.value = 'Укажите название формы (минимум 2 символа).'; return; }
  if (!form.sizes.length) { error.value = 'Выберите хотя бы один размер.'; return; }
  if (form.logo_source === 'team' && !selectedTeam.value) { error.value = 'Выберите команду с логотипом.'; return; }
  if (form.logo_source === 'custom' && !logoFile.value && !storedLogoUrl.value) { error.value = 'Выберите файл логотипа.'; return; }

  busy.value = true;
  try {
    if (!authenticated.value) {
      await savePendingDraft();
      notice.value = 'Черновик подготовлен. После входа MSKBA автоматически сохранит его в «Мои формы».';
      window.dispatchEvent(new CustomEvent('mskba:auth:open', {detail:{mode:'login',stayOnPage:true}}));
      return;
    }
    const payload = new FormData();
    payload.append('name', form.name.trim());
    payload.append('primary_color', form.primary_color);
    payload.append('secondary_color', form.secondary_color);
    form.sizes.forEach((s,i) => payload.append('sizes[' + i + ']',s));
    payload.append('logo_source',form.logo_source);
    if (form.logo_source === 'team') payload.append('logo_team_id', form.logo_team_id);
    if (form.logo_source === 'custom' && logoFile.value) payload.append('logo',logoFile.value);
    const url = editId.value ? '/uniforms/projects/' + editId.value : '/uniforms/projects';
    if (editId.value) payload.append('_method','PUT');
    const response = await fetch(url, {method:'POST',headers:{
      Accept:'application/json',
      'X-Requested-With':'XMLHttpRequest',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
    },credentials:'same-origin',body:payload});
    const result = await response.json().catch(()=>({}));
    if (response.status === 401) {
      authenticated.value = false;
      await savePendingDraft();
      window.dispatchEvent(new CustomEvent('mskba:auth:open',{detail:{mode:'login',stayOnPage:true}}));
      return;
    }
    if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' · ') || result.message || 'Не удалось сохранить форму.');
    window.location.assign('/account/uniforms?saved=1');
  } catch(e) { error.value = e.message; }
  finally { busy.value = false; }
}
</script>

<template>
  <div class="mskba-uniform-editor">
    <div class="mskba-uniform-editor__preview">
      <div class="mskba-uniform-editor__preview-head"><span class="mskba-uniform-eyebrow">01 / ВИЗУАЛИЗАЦИЯ</span><span class="mskba-uniform-editor__draft">DRAFT</span></div>
      <UniformKitPreview :name="form.name" :primary="form.primary_color" :secondary="form.secondary_color" :logo="logoUrl"/>
      <p class="mskba-uniform-editor__preview-note">Цветовая схема и пропорции служат предварительным примером, не производственным лекалом.</p>
    </div>
    <form class="mskba-uniform-editor__controls" @submit.prevent="save" novalidate>
      <span class="mskba-uniform-eyebrow">02 / НАСТРОЙКИ</span>
      <h2>{{ subtitle }}</h2>
      <p class="mskba-uniform-editor__intro">Создайте базовый образ формы. Сохранённый проект можно будет открыть и доработать позднее.</p>
      <label class="mskba-uniform-editor__field">Название формы
        <input v-model.trim="form.name" maxlength="100" minlength="2" type="text" autocomplete="off" required placeholder="Название комплекта"/>
      </label>
      <div class="mskba-uniform-editor__colors">
        <label>Основной цвет <span class="mskba-uniform-editor__color"><input v-model="form.primary_color" type="color" aria-label="Основной цвет"/><span>{{ form.primary_color.toUpperCase() }}</span></span></label>
        <label>Вспомогательный цвет <span class="mskba-uniform-editor__color"><input v-model="form.secondary_color" type="color" aria-label="Вспомогательный цвет"/><span>{{ form.secondary_color.toUpperCase() }}</span></span></label>
      </div>
      <fieldset class="mskba-uniform-editor__sizes">
        <legend>Доступные размеры</legend>
        <div class="mskba-uniform-editor__size-grid">
          <label v-for="size in sizeOptions" :key="size" :class="{ 'is-active': form.sizes.includes(size) }">
            <input type="checkbox" :checked="form.sizes.includes(size)" @change="toggleSize(size)"/><span>{{ size }}</span>
          </label>
        </div>
      </fieldset>
      <fieldset class="mskba-uniform-editor__logos">
        <legend>Логотип</legend>
        <div class="mskba-uniform-editor__logo-options">
          <label><input v-model="form.logo_source" type="radio" value="none"/>Без логотипа</label>
          <label><input v-model="form.logo_source" type="radio" value="team"/>Из моей команды</label>
          <label><input v-model="form.logo_source" type="radio" value="custom"/>Загрузить свой</label>
        </div>
        <label v-if="form.logo_source === 'team'" class="mskba-uniform-editor__field">Выберите команду
          <select v-model="form.logo_team_id" :disabled="!teams.length">
            <option value="">Выберите из доступных</option>
            <option v-for="team in teams" :key="team.id" :value="team.id">{{ team.name }}</option>
          </select>
          <small v-if="!teams.length">Команды с доступными логотипами пока не найдены. Авторизуйтесь, чтобы увидеть свои команды.</small>
        </label>
        <label v-if="form.logo_source === 'custom'" class="mskba-uniform-editor__upload">PNG, JPG или WebP до 2 МБ
          <input type="file" accept="image/png,image/jpeg,image/webp" @change="onFile"/>
          <span v-if="storedLogoUrl && !logoFile">Ранее загруженный логотип сохранён.</span>
        </label>
      </fieldset>
      <p v-if="loading" class="mskba-uniform-editor__message">Загружаем параметры…</p>
      <p v-if="error" class="mskba-uniform-editor__error" role="alert">{{ error }}</p>
      <p v-if="notice" class="mskba-uniform-editor__message" role="status">{{ notice }}</p>
      <div class="mskba-uniform-editor__actions">
        <button type="submit" class="mskba-uniform-editor__save" :disabled="busy || loading">{{ busy ? 'Сохраняем…' : editId ? 'Сохранить изменения' : 'Сохранить форму' }} <span aria-hidden="true">↗</span></button>
        <a href="/account/uniforms">Мои формы</a>
      </div>
      <p v-if="!authenticated" class="mskba-uniform-editor__auth-note">Для сохранения потребуется вход или регистрация. Эскиз продолжит жить после авторизации.</p>
    </form>
  </div>
</template>
