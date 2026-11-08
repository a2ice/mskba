<script setup>
import { computed, getCurrentInstance } from 'vue';

const props = defineProps({
  name: { type: String, default: 'Моя форма' },
  primary: { type: String, default: '#DCE7EF' },
  secondary: { type: String, default: '' },
  logo: { type: String, default: '' },
  view: { type: String, default: 'front' },
  size: { type: String, default: 'M' },
  number: { type: [String, Number], default: '01' },
  numberColor: { type: String, default: '' },
  numberOutline: { type: Boolean, default: false },
  numberOutlineColor: { type: String, default: '' },
  logoFrontPosition: { type: String, default: 'left' },
  logoBackPosition: { type: String, default: 'above' },
  sleeveStyle: { type: String, default: 'sleeveless' },
});
const id = 'mskba-uniform-svg-' + getCurrentInstance().uid;
const sizes = { XS: 0.87, S: 0.94, M: 1, L: 1.06, XL: 1.12, XXL: 1.18 };
const scale = computed(() => sizes[props.size] || 1);
const label = computed(() => ({ front: 'спереди', left: 'слева', right: 'справа', rear: 'сзади' })[props.view] || 'спереди');
const formattedNumber = computed(() => String(Math.min(999, Math.max(1, Number.parseInt(props.number, 10) || 1))).padStart(2, '0'));
const isDark = computed(() => {
  const hex = /^#[\da-f]{6}$/i.test(props.primary) ? props.primary.slice(1) : 'DCE7EF';
  const [r, g, b] = [0, 2, 4].map(i => {
    const n = parseInt(hex.slice(i, i + 2), 16) / 255;
    return n <= 0.04045 ? n / 12.92 : ((n + 0.055) / 1.055) ** 2.4;
  });
  return r * 0.2126 + g * 0.7152 + b * 0.0722 < 0.38;
});
const ink = computed(() => props.numberColor || (props.secondary || (props.numberOutline
  ? (isDark.value ? '#111924' : '#FFFFFF')
  : (isDark.value ? '#FFFFFF' : '#111924'))));
const outlineInk = computed(() => props.numberOutlineColor || (props.secondary
  ? props.primary
  : (isDark.value ? '#FFFFFF' : '#111924')));
const accent = computed(() => props.secondary || props.primary);
const seam = computed(() => isDark.value ? '#F5F9FC' : '#60798E');
const lettering = computed(() => ({
  fill: ink.value,
  stroke: props.numberOutline ? outlineInk.value : 'none',
  strokeWidth: props.numberOutline ? 5 : 0,
  paintOrder: 'stroke',
}));
const hasFrontLogo = computed(() => !!props.logo && props.logoFrontPosition !== 'none');
const hasBackLogo = computed(() => !!props.logo && props.logoBackPosition !== 'none');
</script>

<template>
  <div class="mskba-kit-preview" :style="{ '--kit-primary': primary, '--kit-secondary': accent }">
    <svg class="mskba-kit-preview__drawing" xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 460 550" role="img"
      :aria-label="'Баскетбольная форма «' + name + '», ' + label + ', размер ' + size">
      <defs>
        <linearGradient :id="id + '-sheen'" x1="0" y1="0" x2="1" y2=".17">
          <stop offset="0" stop-color="#ffffff" stop-opacity=".26"/>
          <stop offset=".26" stop-color="#ffffff" stop-opacity="0"/>
          <stop offset=".77" stop-color="#172C3D" stop-opacity="0"/>
          <stop offset="1" stop-color="#172C3D" stop-opacity=".18"/>
        </linearGradient>
        <linearGradient :id="id + '-hem'" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stop-color="#101B26" stop-opacity="0"/>
          <stop offset="1" stop-color="#101B26" stop-opacity=".16"/>
        </linearGradient>
      </defs>
      <g :transform="'translate(230 276) scale(' + scale + ') translate(-230 -276)'">
        <ellipse cx="230" cy="522" rx="103" ry="7" fill="#58738A" opacity=".13"/>

        <!-- Flat pattern-inspired front / back: narrow shoulders, deep arm openings,
             slightly tapered torso, natural waistband and separately hemmed legs. -->
        <g v-if="view === 'front' || view === 'rear'" class="mskba-kit-preview__garment">
          <!-- Jersey -->
          <path d="M165 67 194 56Q205 87 230 88Q255 87 266 56L295 67
              Q303 89 310 127L293 158 285 151
              281 292Q230 299 179 292L175 151 167 158 150 127Q157 89 165 67Z"
            fill="var(--kit-primary)" stroke="#324758" stroke-opacity=".53" stroke-width="1.7" stroke-linejoin="round"/>
          <path d="M165 67 194 56Q205 87 230 88Q255 87 266 56L295 67
              Q303 89 310 127L293 158 285 151
              281 292Q230 299 179 292L175 151 167 158 150 127Q157 89 165 67Z"
            :fill="'url(#' + id + '-sheen)'"/>
          <!-- Rib-knit double collar, armhole piping and neck depth -->
          <path d="M194 56Q207 91 230 92Q253 91 266 56" fill="none"
            :stroke="accent" stroke-width="9" stroke-linecap="round"/>
          <path d="M194 56Q207 88 230 89Q253 88 266 56" fill="none"
            :stroke="seam" stroke-opacity=".58" stroke-width="1.7"/>
          <path d="M166 69Q157 96 151 127L167 158 175 151M294 69Q303 96 309 127L293 158 285 151"
            fill="none" :stroke="accent" stroke-width="6.5" stroke-linejoin="round"/>
          <path d="M166 69Q157 96 151 127L167 158 175 151M294 69Q303 96 309 127L293 158 285 151"
            fill="none" :stroke="seam" stroke-opacity=".35" stroke-width="1.3"/>
          <!-- Reference: narrow contrasting side inserts, not thick straight cartoon bars -->
          <path d="M175 151Q181 155 185 155L190 292 179 292Z
                   M285 151Q279 155 275 155L270 292 281 292Z"
            :fill="accent" :opacity="secondary ? 1 : .16"/>
          <path d="M188 155 193 291M272 155 267 291" stroke="#172C3D" stroke-opacity=".12" stroke-width="2"/>
          <path d="M181 284Q230 291 279 284M181 291Q230 298 279 291"
            fill="none" :stroke="seam" stroke-opacity=".55" stroke-width="1.25"/>
          <path d="M198 139Q192 184 198 231M263 142Q267 188 263 230M213 280Q229 282 246 280"
            fill="none" :stroke="seam" stroke-opacity=".12" stroke-width="1.5"/>

          <!-- Jersey number, centered; no hard-coded club lettering. -->
          <text x="230" :y="view === 'rear' ? 205 : 210" text-anchor="middle"
            font-family="Arial, Helvetica, sans-serif" font-weight="800"
            :font-size="formattedNumber.length === 3 ? 59 : 71"
            :letter-spacing="formattedNumber.length === 3 ? '-3' : '-2'"
            :style="lettering">{{ formattedNumber }}</text>
          <image v-if="view === 'front' && hasFrontLogo" :href="logo"
            :x="logoFrontPosition === 'left' ? 258 : 180" y="115"
            width="31" height="31" preserveAspectRatio="xMidYMid meet"/>
          <image v-if="view === 'rear' && hasBackLogo" :href="logo"
            x="208" :y="logoBackPosition === 'below' ? 224 : 107"
            width="44" height="44" preserveAspectRatio="xMidYMid meet"/>

          <!-- Elastic waistband and shorts, with a believable fork and two leg openings -->
          <path d="M166 316Q230 310 294 316L300 340 323 486
                   Q284 496 246 491L230 411 214 491Q176 496 137 486L160 340Z"
            fill="var(--kit-primary)" stroke="#324758" stroke-opacity=".5" stroke-width="2" stroke-linejoin="round"/>
          <path d="M166 316Q230 310 294 316L300 340 323 486
                   Q284 496 246 491L230 411 214 491Q176 496 137 486L160 340Z"
            :fill="'url(#' + id + '-sheen)'"/>
          <path d="M166 316Q230 310 294 316L300 340 323 486
                   Q284 496 246 491L230 411 214 491Q176 496 137 486L160 340Z"
            :fill="'url(#' + id + '-hem)'"/>
          <path d="M165 317Q230 312 295 317L298 336Q230 330 162 336Z"
            :fill="accent" :opacity="secondary ? 1 : .22"/>
          <path d="M163 337Q230 332 297 337" fill="none" :stroke="seam" stroke-opacity=".57" stroke-width="1.8"/>
          <path d="M160 340 137 486Q148 491 163 491L179 343Z
                   M300 340 323 486Q312 491 297 491L281 343Z"
            :fill="accent" :opacity="secondary ? 1 : .16"/>
          <path d="M153 464 137 486Q176 496 214 491M307 464 323 486Q284 496 246 491"
            fill="none" :stroke="seam" stroke-width="2" stroke-opacity=".55"/>
          <path d="M230 345 230 411M221 386 230 411 239 386"
            fill="none" :stroke="seam" stroke-opacity=".26" stroke-width="1.4"/>
          <path d="M184 353Q179 413 172 460M276 353Q281 413 288 460"
            fill="none" :stroke="seam" stroke-opacity=".13" stroke-width="2"/>
          <text v-if="view === 'front'" x="187" y="447" text-anchor="middle"
            font-family="Arial, Helvetica, sans-serif" font-weight="800"
            :font-size="formattedNumber.length === 3 ? 22 : 29"
            :style="lettering">{{ formattedNumber }}</text>
        </g>

        <!-- Profile pattern: both real side views share one mirrored drafting geometry.
             Front of garment faces left in the left-side view. -->
        <g v-else :transform="view === 'right' ? 'translate(460 0) scale(-1 1)' : undefined"
          class="mskba-kit-preview__garment">
          <path d="M224 58Q237 58 250 67Q263 91 265 128L257 157
                   258 291Q232 296 209 291L213 157Q205 146 200 127
                   Q201 93 214 70Z"
            fill="var(--kit-primary)" stroke="#324758" stroke-opacity=".5" stroke-width="1.8"/>
          <path d="M224 58Q237 58 250 67Q263 91 265 128L257 157
                   258 291Q232 296 209 291L213 157Q205 146 200 127
                   Q201 93 214 70Z" :fill="'url(#' + id + '-sheen)'"/>
          <path d="M214 70Q224 98 238 96Q246 93 250 67" fill="none"
            :stroke="accent" stroke-width="7"/>
          <path d="M249 68Q260 94 263 127L257 157 250 150" fill="none"
            :stroke="accent" stroke-width="5"/>
          <path d="M218 154Q232 159 246 154L245 290Q232 295 218 290Z"
            :fill="accent" :opacity="secondary ? 1 : .17"/>
          <path d="M221 162 221 285M240 160 240 287"
            :stroke="seam" stroke-width="1.4" stroke-dasharray="4 5" stroke-opacity=".55"/>
          <path d="M211 284Q235 289 256 284M210 291Q235 296 258 291" fill="none"
            :stroke="seam" stroke-width="1.3" stroke-opacity=".5"/>
          <path d="M210 317Q232 312 255 316L260 337 279 489
                   Q256 497 234 491L226 408 218 491Q197 496 179 487L199 337Z"
            fill="var(--kit-primary)" stroke="#324758" stroke-opacity=".5" stroke-width="1.8"/>
          <path d="M210 317Q232 312 255 316L260 337 279 489
                   Q256 497 234 491L226 408 218 491Q197 496 179 487L199 337Z"
            :fill="'url(#' + id + '-sheen)'"/>
          <path d="M210 317Q232 312 255 316L260 337 199 337Z"
            :fill="accent" :opacity="secondary ? 1 : .19"/>
          <path d="M217 340 239 339 258 487Q244 494 234 491L226 408 218 491Q208 493 196 490Z"
            :fill="accent" :opacity="secondary ? 1 : .15"/>
          <path d="M226 340 226 407M203 477Q218 495 234 491M241 478Q258 497 279 489"
            fill="none" :stroke="seam" stroke-width="1.5" stroke-opacity=".55"/>
        </g>
      </g>
    </svg>
  </div>
</template>
