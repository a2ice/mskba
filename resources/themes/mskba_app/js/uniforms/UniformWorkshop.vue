<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import UniformKitPreview from './UniformKitPreview.vue';

const props = defineProps({
  initialId: { type: [String, Number], default: null },
  context: { type: String, default: 'page' },
  teamId: { type: [String, Number], default: null },
});
const sizeOptions = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
const isTeamContext = computed(() => !!props.teamId);
const canEdit = ref(!props.teamId);
const readOnly = computed(() => isTeamContext.value && !canEdit.value);
const missingTeamDesign = ref(false);
const teamName = ref('');
const assignedNumber = ref(null);
const previewNumber = computed(() => isTeamContext.value ? (assignedNumber.value || '01') : form.jersey_number);
const previewNumberEnabled = computed(() => form.number_enabled &&
  (!readOnly.value || !isTeamContext.value || assignedNumber.value !== null));

const form = reactive({
  name: 'Моя форма',
  primary_color: '#DCE7EF',
  secondary_color: '',
  sizes: ['M', 'L'],
  sleeve_style: 'sleeveless',
  logo_source: 'none',
  logo_team_id: '',
  jersey_number: '01',
  number_enabled: true,
  jersey_number_x: 225,
  jersey_number_y: 394,
  jersey_number_scale: 1,
  shorts_number_x: 178,
  shorts_number_y: 755,
  shorts_number_scale: 1,
  number_color: '',
  number_outline: false,
  number_outline_color: '',
  logo_front_position: 'left',
  logo_back_position: 'above',
});
const previewView = ref('front');
const selectedNumberPart = ref('jersey');
const initialNumberPositions = {
  jersey: { x: 225, y: 394, scale: 1 },
  shorts: { x: 178, y: 755, scale: 1 },
};
const selectedScale = computed({
  get: () => Number(form[selectedNumberPart.value + '_number_scale']) || 1,
  set: value => { form[selectedNumberPart.value + '_number_scale'] = Math.min(1.6, Math.max(.4, Number(value))); },
});
function selectNumberPart(part) {
  if (!['jersey', 'shorts'].includes(part)) return;
  selectedNumberPart.value = part;
  if (readOnly.value || part === 'shorts' || ['left', 'right'].includes(previewView.value)) {
    if (!readOnly.value) previewView.value = 'front';
  }
  if (readOnly.value) return;
}
function changeNumberPosition({ part, x, y, scale } = {}) {
  if (readOnly.value) return;
  if (!['jersey', 'shorts'].includes(part)) return;
  if (Number.isFinite(x)) form[part + '_number_x'] = x;
  if (Number.isFinite(y)) form[part + '_number_y'] = y;
  if (Number.isFinite(scale)) form[part + '_number_scale'] = scale;
}
function resetNumberPosition() {
  if (readOnly.value) return;
  const part = selectedNumberPart.value;
  changeNumberPosition({ part, ...initialNumberPositions[part] });
}
const previewSize = ref('M');
const viewOptions = [
  { value: 'front', label: 'Спереди' }, { value: 'left', label: 'Слева' },
  { value: 'right', label: 'Справа' }, { value: 'rear', label: 'Сзади' },
];
const activePreviewSize = computed(() => form.sizes.includes(previewSize.value)
  ? previewSize.value : (form.sizes[0] || 'M'));
function normalizeNumber() {
  const numeric = Number.parseInt(form.jersey_number, 10);
  if (Number.isInteger(numeric) && numeric >= 1 && numeric <= 999)
    form.jersey_number = String(numeric).padStart(2, '0');
}
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
  form.secondary_color = design.secondary_color || '';
  form.jersey_number = String(design.jersey_number || 1).padStart(2, '0');
  form.number_enabled = design.number_enabled !== false;
  for (const part of ['jersey', 'shorts']) {
    const defaults = initialNumberPositions[part];
    for (const name of ['x', 'y', 'scale']) {
      const value = Number(design[part + '_number_' + name]);
      form[part + '_number_' + name] = Number.isFinite(value) && value > 0 ? value : defaults[name];
    }
  }
  form.number_color = design.number_color || '';
  form.number_outline = !!design.number_outline;
  form.number_outline_color = design.number_outline_color || '';
  form.logo_front_position = design.logo_front_position || 'left';
  form.logo_back_position = design.logo_back_position || 'above';
  form.sizes = [...design.sizes];
  form.sleeve_style = 'sleeveless'; // Sleeves are not implemented in the SVG pattern yet.
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
    teams.value = (data.teams || []).filter(team => !isTeamContext.value || String(team.id) === String(props.teamId));
    if (isTeamContext.value) {
      const response = await fetch('/uniforms/teams/' + encodeURIComponent(props.teamId), {
        headers: { Accept: 'application/json' }, credentials: 'same-origin',
      });
      if (!response.ok) throw new Error(response.status === 403
        ? 'Нет доступа к форме этой команды.' : 'Не удалось загрузить форму команды.');
      const data = await response.json();
      canEdit.value = data.can_edit === true;
      teamName.value = data.team?.name || 'Команда';
      assignedNumber.value = data.player_number || null;
      missingTeamDesign.value = !data.design;
      if (data.design) {
        hydrate(data.design);
      } else {
        form.name = 'Форма «' + teamName.value + '»';
      }
    } else if (editId.value) {
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
      jersey_number: Number.parseInt(form.jersey_number, 10), number_enabled: form.number_enabled,
      jersey_number_x: form.jersey_number_x, jersey_number_y: form.jersey_number_y,
      jersey_number_scale: form.jersey_number_scale,
      shorts_number_x: form.shorts_number_x, shorts_number_y: form.shorts_number_y,
      shorts_number_scale: form.shorts_number_scale,
      number_color: form.number_color || null, number_outline: form.number_outline,
      number_outline_color: form.number_outline_color || null,
      logo_front_position: form.logo_front_position, logo_back_position: form.logo_back_position,
      logo_source: form.logo_source, logo_team_id: form.logo_team_id || null, logo_data,
    }));
  } catch {
    throw new Error('Не удалось сохранить черновик в браузере. Попробуйте уменьшить логотип.');
  }
}
async function save() {
  if (readOnly.value) return;
  error.value = '';
  if (!form.name.trim() || form.name.trim().length < 2) {
    error.value = 'Укажите название формы (минимум два символа).';
    return;
  }
  if (!form.sizes.length) { error.value = 'Выберите хотя бы один размер.'; return; }
  const number = Number.parseInt(form.jersey_number, 10);
  if (!isTeamContext.value && (!/^\d{1,3}$/.test(form.jersey_number) || !Number.isInteger(number) || number < 1 || number > 999)) {
    error.value = 'Номер должен быть от 01 до 999.'; return;
  }
  normalizeNumber();
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
    payload.append('sleeve_style', 'sleeveless');
    if (!isTeamContext.value) payload.append('jersey_number', String(Number.parseInt(form.jersey_number, 10)));
    payload.append('number_enabled', form.number_enabled ? '1' : '0');
    for (const part of ['jersey', 'shorts']) {
      for (const field of ['x', 'y', 'scale']) {
        payload.append(part + '_number_' + field, String(form[part + '_number_' + field]));
      }
    }
    payload.append('number_outline', form.number_outline ? '1' : '0');
    if (form.number_color) payload.append('number_color', form.number_color);
    if (form.number_outline_color) payload.append('number_outline_color', form.number_outline_color);
    payload.append('logo_front_position', form.logo_front_position);
    payload.append('logo_back_position', form.logo_back_position);
    form.sizes.forEach((s, i) => payload.append('sizes[' + i + ']', s));
    payload.append('logo_source', form.logo_source);
    if (form.logo_source === 'team') payload.append('logo_team_id', form.logo_team_id);
    if (form.logo_source === 'custom' && logoFile.value) payload.append('logo', logoFile.value);
    const url = isTeamContext.value ? '/uniforms/teams/' + encodeURIComponent(props.teamId)
      : (editId.value ? '/uniforms/projects/' + editId.value : '/uniforms/projects');
    if (!isTeamContext.value && editId.value) payload.append('_method', 'PUT');
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
    if (isTeamContext.value) {
      window.dispatchEvent(new CustomEvent('mskba:uniform:saved', {
        detail: { context: props.context, teamId: props.teamId, design: result.design },
      }));
      return;
    }
    if (props.context === 'player-avatar') {
      window.dispatchEvent(new CustomEvent('mskba:uniform:saved', {
        detail: { context: props.context, design: result.design },
      }));
      return;
    }
    window.location.assign('/account/uniforms?saved=1');
  } catch (e) { error.value = e.message; }
  finally { busy.value = false; }
}
</script>

<template>
  <div class="mskba-uniform-editor">
    <section class="mskba-uniform-editor__preview" aria-label="Предпросмотр формы">
      <div class="mskba-uniform-editor__views" role="group" aria-label="Ракурс формы">
        <button v-for="option in viewOptions" :key="option.value" type="button"
          :class="{ 'is-active': previewView === option.value }" :aria-pressed="previewView === option.value"
          @click="previewView = option.value">{{ option.label }}</button>
      </div>
      <div class="mskba-uniform-editor__stage">
        <UniformKitPreview
          :name="form.name" :primary="form.primary_color" :secondary="form.secondary_color"
          :sleeve-style="form.sleeve_style" :logo="logoUrl" :view="previewView"
          :size="activePreviewSize" :number="previewNumber" :number-color="form.number_color"
          :number-outline="form.number_outline" :number-outline-color="form.number_outline_color"
          :logo-front-position="form.logo_front_position" :logo-back-position="form.logo_back_position"
          :number-enabled="previewNumberEnabled" :jersey-number-x="form.jersey_number_x"
          :jersey-number-y="form.jersey_number_y" :jersey-number-scale="form.jersey_number_scale"
          :shorts-number-x="form.shorts_number_x" :shorts-number-y="form.shorts_number_y"
          :shorts-number-scale="form.shorts_number_scale" :editable="!readOnly && form.number_enabled"
          :selected-part="selectedNumberPart" @select-number="selectNumberPart"
          @change-number="changeNumberPosition" />
      </div>
      <label v-if="!readOnly" class="mskba-uniform-editor__preview-size">
        <span>Размер для просмотра</span>
        <select v-model="previewSize" :disabled="!form.sizes.length">
          <option v-for="size in form.sizes" :key="size" :value="size">{{ size }}</option>
        </select>
      </label>
    </section>
    <section v-if="readOnly" class="mskba-uniform-editor__controls mskba-uniform-editor__readonly"
      aria-label="Описание командной формы">
      <h2>Форма команды</h2>
      <p v-if="loading" role="status">Загружаем форму…</p>
      <p v-else-if="missingTeamDesign">Командная форма ещё не создана. Создать её может владелец команды или участник с соответствующим правом.</p>
      <template v-else>
        <dl class="mskba-uniform-editor__details">
          <div><dt>Название формы</dt><dd>{{ form.name }}</dd></div>
          <div><dt>Основной цвет</dt><dd><span class="mskba-uniform-editor__swatch" :style="{ backgroundColor: form.primary_color }"></span>{{ form.primary_color.toUpperCase() }}</dd></div>
          <div><dt>Дополнительный цвет</dt><dd><span v-if="form.secondary_color" class="mskba-uniform-editor__swatch" :style="{ backgroundColor: form.secondary_color }"></span>{{ form.secondary_color ? form.secondary_color.toUpperCase() : 'Не задан' }}</dd></div>
          <div><dt>Фасон</dt><dd>{{ form.sleeve_style === 'sleeved' ? 'С рукавами' : 'Без рукавов' }}</dd></div>
          <div><dt>Размеры</dt><dd><span v-for="size in form.sizes" :key="size" class="mskba-uniform-editor__size-label">{{ size }}</span></dd></div>
          <div><dt>Игровой номер</dt><dd>{{ form.number_enabled && assignedNumber !== null ? assignedNumber : 'Не назначен' }} <small>Присваивается в составе команды</small></dd></div>
          <div><dt>Цвет номера</dt><dd>{{ form.number_color || 'Автоматический контраст' }}</dd></div>
          <div><dt>Обводка номера</dt><dd>{{ form.number_outline ? (form.number_outline_color || 'Автоматическая') : 'Нет' }}</dd></div>
          <div><dt>Логотип</dt><dd>{{ form.logo_source === 'none' ? 'Без логотипа' : form.logo_source === 'team' ? 'Логотип команды' : 'Собственный' }}</dd></div>
          <div v-if="form.logo_source !== 'none'"><dt>Размещение спереди</dt><dd>{{ { none: 'Не размещать', left: 'Слева', right: 'Справа' }[form.logo_front_position] }}</dd></div>
          <div v-if="form.logo_source !== 'none'"><dt>Размещение сзади</dt><dd>{{ { none: 'Не размещать', above: 'Над номером', below: 'Под номером' }[form.logo_back_position] }}</dd></div>
        </dl>
        <p class="mskba-uniform-editor__readonly-note">Форма доступна только для просмотра. Её параметры и номер игрока здесь не изменяются.</p>
      </template>
      <p v-if="error" class="mskba-uniform-editor__error" role="alert">{{ error }}</p>
    </section>
    <form v-else class="mskba-uniform-editor__controls" @submit.prevent="save" novalidate>
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
        <div class="mskba-uniform-editor__field">
          <label class="mskba-uniform-editor__optional"><input type="checkbox"
            :checked="!!form.secondary_color"
            @change="form.secondary_color = $event.target.checked ? '#F58220' : ''"/>
            <span>Дополнительный цвет</span></label>
          <span v-if="form.secondary_color" class="mskba-uniform-editor__color">
            <input v-model="form.secondary_color" type="color" aria-label="Дополнительный цвет"/>
            <span>{{ form.secondary_color.toUpperCase() }}</span>
          </span>
          <span v-else class="mskba-uniform-editor__hint">Не задан — форма одноцветная</span>
        </div>
      </div>
      <fieldset class="mskba-uniform-editor__cut">
        <legend>Фасон</legend>
        <div class="mskba-uniform-editor__choices">
          <label class="check-label">
            <input v-model="form.sleeve_style" type="radio" name="sleeve-style" value="sleeveless"/>
            <span>Без рукавов</span>
          </label>
          <label class="check-label">
            <input v-model="form.sleeve_style" type="radio" name="sleeve-style" value="sleeved" disabled/>
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
      <fieldset class="mskba-uniform-editor__numbers">
        <legend>Игровой номер</legend>
        <label class="mskba-uniform-editor__optional mskba-uniform-editor__number-toggle">
          <input v-model="form.number_enabled" type="checkbox"/><span>С номером</span>
        </label>
        <div v-if="form.number_enabled" class="mskba-uniform-editor__number-configuration">
        <label v-if="!isTeamContext" class="mskba-uniform-editor__field mskba-uniform-editor__number-field">
          <span>Номер (01–999)</span>
          <input v-model="form.jersey_number" type="text" inputmode="numeric"
            pattern="[0-9]{1,3}" maxlength="3" autocomplete="off"
            aria-label="Игровой номер от 01 до 999" @blur="normalizeNumber"/>
        </label>
        <p v-else class="mskba-uniform-editor__hint">Командный шаблон определяет оформление номера. Сам номер каждому игроку назначается в составе команды и не редактируется здесь.</p>
        <div class="mskba-uniform-editor__number-options">
          <label class="mskba-uniform-editor__optional"><input type="checkbox"
            :checked="!!form.number_color" @change="form.number_color = $event.target.checked ? '#111924' : ''"/>
            <span>Свой цвет номера</span></label>
          <span v-if="form.number_color" class="mskba-uniform-editor__color">
            <input v-model="form.number_color" type="color" aria-label="Цвет игрового номера"/>
            <span>{{ form.number_color.toUpperCase() }}</span>
          </span>
          <label class="mskba-uniform-editor__optional"><input v-model="form.number_outline"
            type="checkbox"/><span>Обводка номера</span></label>
          <label v-if="form.number_outline" class="mskba-uniform-editor__optional">
            <input type="checkbox" :checked="!!form.number_outline_color"
              @change="form.number_outline_color = $event.target.checked ? '#FFFFFF' : ''"/>
            <span>Свой цвет обводки</span>
          </label>
          <span v-if="form.number_outline && form.number_outline_color" class="mskba-uniform-editor__color">
            <input v-model="form.number_outline_color" type="color" aria-label="Цвет обводки номера"/>
            <span>{{ form.number_outline_color.toUpperCase() }}</span>
          </span>
          <small class="mskba-uniform-editor__hint">Без ручных цветов контраст подбирается автоматически по ткани.</small>
        </div>
          <div class="mskba-uniform-editor__number-transform">
            <span class="mskba-uniform-editor__label">Размещение и размер</span>
            <div class="mskba-uniform-editor__number-tabs" role="group" aria-label="Выбор номера для редактирования">
              <button type="button" :class="{ 'is-active': selectedNumberPart === 'jersey' }"
                :aria-pressed="selectedNumberPart === 'jersey'" @click="selectNumberPart('jersey')">На майке</button>
              <button type="button" :class="{ 'is-active': selectedNumberPart === 'shorts' }"
                :aria-pressed="selectedNumberPart === 'shorts'" @click="selectNumberPart('shorts')">На шортах</button>
            </div>
            <label class="mskba-uniform-editor__scale-label">
              <span>Масштаб</span>
              <input v-model.number="selectedScale" type="range" min="0.4" max="1.6" step="0.05"/>
              <output>{{ Math.round(selectedScale * 100) }}%</output>
            </label>
            <div class="mskba-uniform-editor__number-edit-hint">
              <small>Нажмите и перетащите номер на форме. Потяните за круглую точку, чтобы изменить размер. Стрелки — точная настройка.</small>
              <button type="button" class="button secondary" @click="resetNumberPosition">Сбросить положение</button>
            </div>
          </div>
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
        <div v-if="form.logo_source !== 'none'" class="mskba-uniform-editor__logo-positions">
          <label class="mskba-uniform-editor__field">
            <span>Спереди (небольшой)</span>
            <select v-model="form.logo_front_position">
              <option value="none">Не размещать</option>
              <option value="left">Слева</option>
              <option value="right">Справа</option>
            </select>
          </label>
          <label class="mskba-uniform-editor__field">
            <span>Сзади</span>
            <select v-model="form.logo_back_position">
              <option value="none">Не размещать</option>
              <option value="above">Над номером</option>
              <option value="below">Под номером</option>
            </select>
          </label>
        </div>
      </fieldset>
      <p v-if="loading" class="mskba-uniform-editor__message" role="status">Загружаем…</p>
      <p v-if="error" class="mskba-uniform-editor__error" role="alert">{{ error }}</p>
      <div class="mskba-uniform-editor__actions">
        <button type="submit" class="button primary" :disabled="busy || loading" :aria-busy="busy">
          {{ busy ? 'Сохраняем…' : isTeamContext ? 'Сохранить форму команды' : editId ? 'Сохранить изменения' : 'Сохранить форму' }}
        </button>
        <a v-if="!isTeamContext && context !== 'player-avatar'" class="button secondary" href="/account/uniforms">Мои формы</a>
      </div>
    </form>
  </div>
</template>
