<script setup>
import { computed, ref, watch } from 'vue';
import UniformKitPreview from './UniformKitPreview.vue';
import { uniformPalettes } from './uniform-options.js';

const props = defineProps({
  initialPalette: { type: String, default: 'mskba' },
  embedded: { type: Boolean, default: false },
});
const emit = defineEmits(['palette-change']);
const paletteId = ref(props.initialPalette);
const number = ref('33');
const playerName = ref('ИГРОК');
const size = ref('L');
const reverse = ref(false);
const status = ref('');
const currentPalette = computed(() => uniformPalettes.find((item) => item.id === paletteId.value) || uniformPalettes[0]);
watch(() => props.initialPalette, (next) => { paletteId.value = next; });
const changePalette = (id) => { paletteId.value = id; emit('palette-change', id); status.value = ''; };
const numberValue = computed(() => String(number.value).replace(/\D/g, '').slice(0, 2) || '0');
const specification = computed(() => [
  'Демонстрационный эскиз MSKBA',
  'Палитра: ' + currentPalette.value.label,
  'Цвет формы: ' + currentPalette.value.base,
  'Отделка: ' + currentPalette.value.trim,
  'Номер: ' + numberValue.value,
  'Надпись: ' + String(playerName.value || 'ИГРОК').trim().slice(0, 12),
  'Размер (ориентировочный): ' + size.value,
  'Эскиз не является производственной документацией.',
].join('\n'));
const copy = async () => {
  try {
    if (!navigator.clipboard?.writeText) throw new Error('Clipboard unavailable');
    await navigator.clipboard.writeText(specification.value);
    status.value = 'Параметры скопированы. Сохраните их для будущего заказа.';
  } catch {
    status.value = 'Копирование недоступно в браузере. Параметры можно записать вручную.';
  }
};
</script>

<template>
  <div class="mskba-workshop" :class="{ 'mskba-workshop--embedded': embedded }">
    <div class="mskba-workshop__visual">
      <div class="mskba-workshop__visual-header">
        <span class="mskba-workshop__kicker">01 / ЭСКИЗ</span>
        <span class="mskba-workshop__draft">DEMO</span>
      </div>
      <UniformKitPreview :palette-id="paletteId" :number="numberValue" :name="playerName" :reverse="reverse" />
      <div class="mskba-workshop__view-toggle" role="group" aria-label="Ракурс формы">
        <button type="button" :aria-pressed="!reverse" :class="{ 'is-active': !reverse }" @click="reverse = false">Спереди</button>
        <button type="button" :aria-pressed="reverse" :class="{ 'is-active': reverse }" @click="reverse = true">Сзади</button>
      </div>
    </div>

    <div class="mskba-workshop__controls">
      <div class="mskba-workshop__step">
        <span class="mskba-workshop__kicker">02 / ПЕРСОНАЛИЗАЦИЯ</span>
        <h3>Ваш стиль начинается здесь</h3>
        <p>Попробуйте сочетания цветов, игровой номер и надпись. Это первый демонстрационный этап будущего конструктора.</p>
      </div>

      <fieldset class="mskba-workshop__fieldset">
        <legend>Цветовая схема <span>{{ currentPalette.label }}</span></legend>
        <div class="mskba-workshop__palettes">
          <button v-for="option in uniformPalettes" :key="option.id" type="button"
            class="mskba-workshop__swatch" :class="{ 'is-selected': paletteId === option.id }"
            :aria-label="option.label" :aria-pressed="paletteId === option.id" :title="option.label"
            @click="changePalette(option.id)">
            <span :style="{ '--kit-base': option.base, '--kit-trim': option.trim }" aria-hidden="true"></span>
          </button>
        </div>
      </fieldset>

      <div class="mskba-workshop__fields">
        <label>Номер игрока
          <input v-model="number" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" placeholder="33" autocomplete="off">
        </label>
        <label>Надпись на спине
          <input v-model="playerName" type="text" maxlength="12" placeholder="ИГРОК" autocomplete="off">
        </label>
        <label class="mskba-workshop__size">Ориентировочный размер
          <select v-model="size">
            <option value="XS">XS</option><option value="S">S</option><option value="M">M</option>
            <option value="L">L</option><option value="XL">XL</option><option value="XXL">XXL</option>
          </select>
        </label>
      </div>

      <div class="mskba-workshop__notice">
        <strong>Следующий этап — производство.</strong>
        <span>Сохранение проекта, размерная сетка, логотипы, выбор ткани, состав команды и заказ партии появятся при развитии мастерской.</span>
      </div>
      <div class="mskba-workshop__actions">
        <button type="button" class="mskba-uniform-button mskba-uniform-button--primary" @click="copy">Скопировать параметры <span aria-hidden="true">↗</span></button>
        <a class="mskba-uniform-button mskba-uniform-button--ghost" href="/faq/faq-uniform-production">Как заказать изготовление</a>
      </div>
      <p class="mskba-workshop__status" role="status" aria-live="polite">{{ status }}</p>
    </div>
  </div>
</template>
