<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import UniformKitPreview from './UniformKitPreview.vue';

const props = defineProps({
  initialId: { type: [String, Number], default: null },
  context: { type: String, default: 'page' },
});
const sizeOptions = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
const form = reactive({
  name: 'Моя форма',
  primary_color: '#111924',
  secondary_color: '#F58220',
  sizes: ['M', 'L'],
  sleeve_style: 'sleeveless',
  logo_source: 'none',
  logo_team_id: '',
});
const logoFile = ref(null);
const fileUrl = ref('');
const storedLogoUrl = ref('');
const teams = ref([]);
const authenticated = ref(document.querySelector('meta[name="mskba-authenticated"]')?.content === '1');
const busy = ref(false);
const loading = ref(false);
const error = ref('');
const editId = ref(props.initialId || null);
const selectedTeam = computed(() => teams.value.find(t => String(t.id) === String(form.logo_team_id)));
const logoUrl = computed(() => form.logo_source === 'custom'
  ? (fileUrl.value || storedLogoUrl.value)
  : form.logo_source === 'team' ? (selectedTeam.value?.logo_url || '') : '');

function toggleSize(size) {
  const index = form.sizes.indexOf(size);
  if (index >= 0) form.sizes.splice(index, 1);
  else form.sizes.push(size);
}
function onFile(event) {
  error.value = '';
  const file = event.target.files?.[0] || null;
  if (file && (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024)) {
    error.value = 'Выберите PNG, JPG или WebP размером до 2 МБ.';
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
  form.sleeve_style = design.sleeve_style || 'sleeved';
  form.logo_source = design.logo_source;
  form.logo_team_id = design.logo_team_id || '';
  storedLogoUrl.value = design.logo_source === 'custom' ? (design.logo_url || '') : '';
}
onMounted(async () => {
  loading.value = true;
  try {
    const response = await fetch('/uniforms/options', {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });
    if (!response.ok) throw new Error('Не удалось загрузить доступные логотипы.');
    const data = await response.json();
    authenticated.value = !!data.authenticated;
    teams.value = data.teams || [];
    if (editId.value) {
      const result = await fetch('/uniforms/projects/' + encodeURIComponent(editId.value), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });
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
  reader.onerror = () => reject(new Error('Не удалось подготовить логотип.'));
  reader.readAsDataURL(file);
});
async function savePendingDraft() {
  const logo_data = form.logo_source === 'custom' ? await preparePendingFile(logoFile.value) : null;
  try {
    sessionStorage.setItem('mskba.uniform.pending.v1', JSON.stringify({
      name: form.name, primary_color: form.primary_color, secondary_color: form.secondary_color,
      sizes: [...form.sizes], sleeve_style: form.sleeve_style,
      logo_source: form.logo_source, logo_team_id: form.logo_team_id || null, logo_data,
    }));
  } catch {
    throw new Error('Не удалось сохранить черновик в браузере. Попробуйте уменьшить логотип.');
  }
}
async function save() {
  error.value = '';
  if (!form.name.trim() || form.name.trim().length < 2) {
    error.value = 'Укажите название формы (минимум два символа).';
    return;
  }
  if (!form.sizes.length) { error.value = 'Выберите хотя бы один размер.'; return; }
  if (form.logo_source === 'team' && !selectedTeam.value) { error.value = 'Выберите команду.'; return; }
  if (form.logo_source === 'custom' && !logoFile.value && !storedLogoUrl.value) {
    error.value = 'Загрузите логотип.';
    return;
  }
  busy.value = true;
  try {
    if (!authenticated.value) {
      await savePendingDraft();
      window.dispatchEvent(new CustomEvent('mskba:auth:open', { detail: { mode: 'login', stayOnPage: true } }));
      return;
    }
    const payload = new FormData();
    payload.append('name', form.name.trim());
    payload.append('primary_color', form.primary_color);
    payload.append('secondary_color', form.secondary_color);
    payload.append('sleeve_style', form.sleeve_style);
    form.sizes.forEach((s, i) => payload.append('sizes[' + i + ']', s));
    payload.append('logo_source', form.logo_source);
    if (form.logo_source === 'team') payload.append('logo_team_id', form.logo_team_id);
    if (form.logo_source === 'custom' && logoFile.value) payload.append('logo', logoFile.value);
    const url = editId.value ? '/uniforms/projects/' + editId.value : '/uniforms/projects';
    if (editId.value) payload.append('_method', 'PUT');
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
      },
      credentials: 'same-origin',
      body: payload,
    });
    const result = await response.json().catch(() => ({}));
    if (response.status === 401) {
      authenticated.value = false;
      await savePendingDraft();
      window.dispatchEvent(new CustomEvent('mskba:auth:open', { detail: { mode: 'login', stayOnPage: true } }));
      return;
    }
    if (!response.ok) {
      const issues = Object.values(result.errors || {}).flat().filter(Boolean);
      throw new Error(issues.join(' · ') || 'Не удалось сохранить форму.');
    }
    window.location.assign('/account/uniforms?saved=1');
  } catch (e) { error.value = e.message; }
  finally { busy.value = false; }
}
</script>

<template>
  <div class="mskba-uniform-editor">
    <section class="mskba-uniform-editor__preview" aria-label="Предпросмотр формы">
      <div class="mskba-uniform-editor__stage">
        <UniformKitPreview
          :name="form.name" :primary="form.primary_color" :secondary="form.secondary_color"
          :sleeve-style="form.sleeve_style" :logo="logoUrl"/>
      </div>
    </section>
    <form class="mskba-uniform-editor__controls" @submit.prevent="save" novalidate>
      <h2>Настройки формы</h2>
      <label class="mskba-uniform-editor__field">
        <span>Название формы</span>
        <input v-model.trim="form.name" maxlength="100" minlength="2" type="text"
          autocomplete="off" required placeholder="Название формы"/>
      </label>
      <div class="mskba-uniform-editor__colors">
        <label class="mskba-uniform-editor__field">
          <span>Основной цвет</span>
          <span class="mskba-uniform-editor__color">
            <input v-model="form.primary_color" type="color" aria-label="Основной цвет"/>
            <span>{{ form.primary_color.toUpperCase() }}</span>
          </span>
        </label>
        <label class="mskba-uniform-editor__field">
          <span>Вспомогательный цвет</span>
          <span class="mskba-uniform-editor__color">
            <input v-model="form.secondary_color" type="color" aria-label="Вспомогательный цвет"/>
            <span>{{ form.secondary_color.toUpperCase() }}</span>
          </span>
        </label>
      </div>
      <fieldset class="mskba-uniform-editor__cut">
        <legend>Фасон</legend>
        <div class="mskba-uniform-editor__choices">
          <label class="check-label">
            <input v-model="form.sleeve_style" type="radio" name="sleeve-style" value="sleeveless"/>
            <span>Без рукавов</span>
          </label>
          <label class="check-label">
            <input v-model="form.sleeve_style" type="radio" name="sleeve-style" value="sleeved"/>
            <span>С рукавами</span>
          </label>
        </div>
      </fieldset>
      <fieldset class="mskba-uniform-editor__sizes">
        <legend>Размеры</legend>
        <div class="mskba-uniform-editor__size-grid">
          <label v-for="size in sizeOptions" :key="size" class="check-label">
            <input type="checkbox" :checked="form.sizes.includes(size)" @change="toggleSize(size)"/>
            <span>{{ size }}</span>
          </label>
        </div>
      </fieldset>
      <fieldset class="mskba-uniform-editor__logos">
        <legend>Логотип</legend>
        <div class="mskba-uniform-editor__choices mskba-uniform-editor__choices--logos">
          <label class="check-label"><input v-model="form.logo_source" type="radio" name="logo-source" value="none"/><span>Без логотипа</span></label>
          <label class="check-label"><input v-model="form.logo_source" type="radio" name="logo-source" value="team"/><span>Моя команда</span></label>
          <label class="check-label"><input v-model="form.logo_source" type="radio" name="logo-source" value="custom"/><span>Загрузить свой</span></label>
        </div>
        <label v-if="form.logo_source === 'team'" class="mskba-uniform-editor__field">
          <span>Команда</span>
          <span class="select-control">
            <select v-model="form.logo_team_id" :disabled="!teams.length">
              <option value="">Выберите команду</option>
              <option v-for="team in teams" :key="team.id" :value="team.id">{{ team.name }}</option>
            </select>
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><use href="#chevron-down"/></svg>
          </span>
          <small v-if="!teams.length">Команды с логотипами недоступны.</small>
        </label>
        <label v-if="form.logo_source === 'custom'" class="mskba-uniform-editor__upload">
          <span>PNG, JPG или WebP · до 2 МБ</span>
          <input type="file" accept="image/png,image/jpeg,image/webp" @change="onFile"/>
          <small v-if="storedLogoUrl && !logoFile">Логотип загружен</small>
        </label>
      </fieldset>
      <p v-if="loading" class="mskba-uniform-editor__message" role="status">Загружаем…</p>
      <p v-if="error" class="mskba-uniform-editor__error" role="alert">{{ error }}</p>
      <div class="mskba-uniform-editor__actions">
        <button type="submit" class="button primary" :disabled="busy || loading" :aria-busy="busy">
          {{ busy ? 'Сохраняем…' : editId ? 'Сохранить изменения' : 'Сохранить форму' }}
        </button>
        <a class="button secondary" href="/account/uniforms">Мои формы</a>
      </div>
    </form>
  </div>
</template>
