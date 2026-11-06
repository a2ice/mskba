<script setup>
import { computed } from 'vue';

const props = defineProps({
  name: { type: String, default: 'Моя форма' },
  primary: { type: String, default: '#111924' },
  secondary: { type: String, default: '#F58220' },
  logo: { type: String, default: '' },
  sleeveStyle: { type: String, default: 'sleeveless' },
});
const title = computed(() => props.name.trim() || 'Форма');
</script>

<template>
  <div class="mskba-kit-preview" :style="{ '--kit-primary': primary, '--kit-secondary': secondary }">
    <svg class="mskba-kit-preview__drawing" viewBox="0 0 520 480" xmlns="http://www.w3.org/2000/svg"
      role="img" :aria-label="'Баскетбольная форма «' + title + '», ' + (sleeveStyle === 'sleeved' ? 'с рукавами' : 'без рукавов')">
      <ellipse cx="260" cy="440" rx="135" ry="12" fill="#4f6880" opacity=".16" />
      <!-- The art is a garment illustration, not a UI icon. Both cuts use identical
           preview coordinates so switching sleeve type never shifts the kit. -->
      <g v-if="sleeveStyle === 'sleeved'">
        <path d="M143 49 186 32Q223 56 260 56Q297 56 334 32L377 49 427 133 370 161 351 126 351 285 169 285 169 126 150 161 93 133Z"
          fill="var(--kit-primary)" stroke="#496277" stroke-opacity=".38" stroke-width="2.5" stroke-linejoin="round"/>
        <path d="M136 63 151 96 115 136 94 133ZM384 63 369 96 405 136 426 133Z" fill="var(--kit-secondary)"/>
        <path d="M169 107V285M351 107V285" fill="none" stroke="var(--kit-secondary)" stroke-width="7"/>
      </g>
      <g v-else>
        <path d="M188 31 211 42Q236 56 260 56Q284 56 309 42L332 31 363 96 344 127 329 114 329 285 191 285 191 114 176 127 157 96Z"
          fill="var(--kit-primary)" stroke="#496277" stroke-opacity=".38" stroke-width="2.5" stroke-linejoin="round"/>
        <path d="M188 31 172 95 176 107 194 87 211 42ZM332 31 348 95 344 107 326 87 309 42Z" fill="var(--kit-secondary)"/>
        <path d="M191 125V285M329 125V285" fill="none" stroke="var(--kit-secondary)" stroke-width="7"/>
      </g>
      <path d="M186 32Q223 56 260 56Q297 56 334 32L319 56Q260 110 201 56Z" fill="var(--kit-secondary)"/>
      <path d="M191 273H329V285H191Z" fill="var(--kit-secondary)"/>
      <text x="260" y="159" font-family="Arial,sans-serif" font-size="25" font-weight="800"
        text-anchor="middle" fill="#fff" stroke="#142637" stroke-width="1.5" paint-order="stroke" letter-spacing="1">MSKBA</text>
      <text x="260" y="225" font-family="Arial,sans-serif" font-size="63" font-weight="900"
        text-anchor="middle" fill="#fff" stroke="#142637" stroke-width="2" paint-order="stroke">33</text>
      <image v-if="logo" :href="logo" x="238" y="81" width="44" height="44" preserveAspectRatio="xMidYMid meet"/>
      <g>
        <path d="M172 307H348L371 430H279L260 366 241 430H149Z"
          fill="var(--kit-primary)" stroke="#496277" stroke-opacity=".38" stroke-width="2.5" stroke-linejoin="round"/>
        <path d="M172 307H348V321H172ZM149 415H241V430H149ZM279 415H371V430H279Z" fill="var(--kit-secondary)"/>
        <path d="M169 328 152 416M351 328 368 416" stroke="var(--kit-secondary)" stroke-width="8" fill="none"/>
        <text x="329" y="394" font-family="Arial,sans-serif" font-size="28" font-weight="800"
          text-anchor="middle" fill="#fff" stroke="#142637" stroke-width="1.5" paint-order="stroke">33</text>
      </g>
    </svg>
  </div>
</template>
