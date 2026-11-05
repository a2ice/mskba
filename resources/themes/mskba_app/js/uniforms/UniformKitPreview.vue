<script setup>
import { computed } from 'vue';
const props = defineProps({
  name: { type: String, default: 'MSKBA' },
  primary: { type: String, default: '#111924' },
  secondary: { type: String, default: '#F58220' },
  logo: { type: String, default: '' },
});
const title = computed(() => props.name.trim().slice(0, 14) || 'MSKBA');
</script>

<template>
  <div class="mskba-kit-preview" :style="{ '--kit-primary': primary, '--kit-secondary': secondary }">
    <div class="mskba-kit-preview__top"><span>MSKBA STUDIO</span><span>01 / PREVIEW</span></div>
    <svg class="mskba-kit-preview__drawing" viewBox="0 0 520 480" xmlns="http://www.w3.org/2000/svg" role="img" :aria-label="'Эскиз баскетбольной формы: ' + title">
      <ellipse cx="260" cy="441" rx="163" ry="19" fill="#000" opacity=".17"/>
      <g class="mskba-kit-preview__jersey">
        <path d="M143 49 186 32Q223 56 260 56Q297 56 334 32L377 49 427 133 370 161 351 126 351 285 169 285 169 126 150 161 93 133Z" fill="var(--kit-primary)" stroke="#ffffff" stroke-opacity=".32" stroke-width="3" stroke-linejoin="round"/>
        <path d="M186 32Q223 56 260 56Q297 56 334 32L320 56Q260 111 200 56Z" fill="var(--kit-secondary)"/>
        <path d="M136 63 151 96 115 136 94 133ZM384 63 369 96 405 136 426 133Z" fill="var(--kit-secondary)"/>
        <path d="M169 269 351 269 351 285 169 285Z" fill="var(--kit-secondary)" />
        <path d="M169 102V285M351 102V285" stroke="var(--kit-secondary)" stroke-width="8"/>
        <text x="260" y="153" font-family="Arial,sans-serif" font-size="25" font-weight="800" text-anchor="middle" fill="white" letter-spacing="1.5">MSKBA</text>
        <text x="260" y="220" font-family="Arial,sans-serif" font-size="63" font-weight="900" text-anchor="middle" fill="white">33</text>
        <image v-if="logo" :href="logo" x="239" y="83" width="42" height="42" preserveAspectRatio="xMidYMid meet"/>
      </g>
      <g class="mskba-kit-preview__shorts">
        <path d="M172 307H348L371 430H279L260 366 241 430H149Z" fill="var(--kit-primary)" stroke="#ffffff" stroke-opacity=".32" stroke-width="3" stroke-linejoin="round"/>
        <path d="M172 308H348V323H172Z" fill="var(--kit-secondary)"/>
        <path d="M149 414H241V430H149ZM279 414H371V430H279Z" fill="var(--kit-secondary)"/>
        <path d="M169 328 152 416M351 328 368 416" stroke="var(--kit-secondary)" stroke-width="9"/>
        <text x="330" y="391" font-family="Arial,sans-serif" font-size="28" font-weight="800" text-anchor="middle" fill="white">33</text>
      </g>
    </svg>
    <div class="mskba-kit-preview__caption">
      <span>{{ title }}</span><span>Демонстрационный эскиз · не лекало</span>
    </div>
  </div>
</template>
