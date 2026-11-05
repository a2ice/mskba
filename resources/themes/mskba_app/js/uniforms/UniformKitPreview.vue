<script setup>
import { computed, useId } from 'vue';
import { uniformPalettes } from './uniform-options.js';

const svgId = useId().replace(/:/g, '-');
const sheenId = svgId + '-sheen';
const shadowId = svgId + '-shadow';

const props = defineProps({
  paletteId: { type: String, default: 'mskba' },
  number: { type: [String, Number], default: '33' },
  name: { type: String, default: 'MSKBA' },
  reverse: { type: Boolean, default: false },
});
const colors = computed(() => uniformPalettes.find((item) => item.id === props.paletteId) || uniformPalettes[0]);
const safeNumber = computed(() => String(props.number).replace(/\D/g, '').slice(0, 2) || '0');
const safeName = computed(() => String(props.name || 'MSKBA').trim().toUpperCase().slice(0, 12));
</script>

<template>
  <div class="mskba-kit-preview" role="img" :aria-label="'Демонстрационный макет баскетбольной формы: ' + colors.label + ', номер ' + safeNumber">
    <svg class="mskba-kit-preview__svg" viewBox="0 0 300 350" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
      <defs>
        <linearGradient :id="sheenId" x1="0%" x2="95%" y1="0%" y2="100%">
          <stop offset="0" stop-color="#fff" stop-opacity=".19"/>
          <stop offset=".56" stop-color="#fff" stop-opacity="0"/>
          <stop offset="1" stop-color="#000" stop-opacity=".21"/>
        </linearGradient>
        <filter :id="shadowId" x="-30%" y="-30%" width="160%" height="180%">
          <feDropShadow dx="0" dy="15" stdDeviation="11" flood-color="#020912" flood-opacity=".55"/>
        </filter>
      </defs>
      <ellipse cx="151" cy="325" rx="103" ry="12" fill="#030b17" opacity=".45"/>
      <g :filter="'url(#' + shadowId + ')'" stroke-linejoin="round">
        <path d="M83 29 L113 17 Q150 38 187 17 L217 29 L255 69 L232 104 L216 91 L218 191 Q150 203 82 191 L84 91 L68 104 L45 69 Z"
          :fill="colors.base" :stroke="colors.trim" stroke-width="3"/>
        <path d="M112 18 Q150 66 188 18" fill="none" :stroke="colors.trim" stroke-width="9"/>
        <path d="M82 30 Q80 68 61 85 M218 30 Q220 68 239 85" fill="none" :stroke="colors.trim" stroke-width="7"/>
        <path d="M85 71 L84 187 M216 71 L216 187" :stroke="colors.trim" stroke-width="7"/>
        <path d="M83 42 L85 186 M217 42 L215 186" stroke="#ffffff" opacity=".25" stroke-width="2.5"/>
        <path d="M83 29 L113 17 Q150 38 187 17 L217 29 L255 69 L232 104 L216 91 L218 191 Q150 203 82 191 L84 91 L68 104 L45 69 Z"  :fill="'url(#' + sheenId + ')'"/>
        <path d="M89 216 Q150 226 211 216 L227 309 L177 318 L150 271 L123 318 L73 309 Z" :fill="colors.base" :stroke="colors.trim" stroke-width="3"/>
        <path d="M88 230 Q150 241 212 230" :stroke="colors.trim" stroke-width="9" fill="none"/>
        <path d="M79 242 L78 304 M220 242 L223 304" :stroke="colors.trim" stroke-width="8"/>
        <path d="M91 216 L73 309 L123 318 L150 271 L177 318 L227 309 L209 216"  :fill="'url(#' + sheenId + ')'"/>
      </g>
      <template v-if="reverse">
        <text x="150" y="87" text-anchor="middle" :fill="colors.accent" font-size="17" font-weight="850" textLength="125" lengthAdjust="spacingAndGlyphs">{{ safeName }}</text>
        <text x="150" y="158" text-anchor="middle" :fill="colors.accent" font-size="75" font-weight="950" letter-spacing="-4">{{ safeNumber }}</text>
      </template>
      <template v-else>
        <text x="150" y="88" text-anchor="middle" :fill="colors.accent" font-size="19" font-weight="900" letter-spacing="1">MSKBA</text>
        <path d="M113 97 H187" :stroke="colors.trim" stroke-width="2" opacity=".85"/>
        <text x="150" y="159" text-anchor="middle" :fill="colors.accent" font-size="76" font-weight="950" letter-spacing="-4">{{ safeNumber }}</text>
        <text x="105" y="286" text-anchor="middle" :fill="colors.accent" font-size="27" font-weight="900">{{ safeNumber }}</text>
      </template>
    </svg>
    <span class="mskba-kit-preview__caption">ПРЕДВАРИТЕЛЬНЫЙ МАКЕТ · НЕ ЛЕКАЛО</span>
  </div>
</template>
