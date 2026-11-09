<script setup>
import { computed, getCurrentInstance, ref } from 'vue';

const props = defineProps({
  name: { type: String, default: 'Моя форма' },
  primary: { type: String, default: '#DCE7EF' },
  secondary: { type: String, default: '' },
  logo: { type: String, default: '' },
  view: { type: String, default: 'front' },
  size: { type: String, default: 'M' },
  number: { type: [String, Number], default: '01' },
  numberEnabled: { type: Boolean, default: true },
  numberColor: { type: String, default: '' },
  numberOutline: { type: Boolean, default: false },
  numberOutlineColor: { type: String, default: '' },
  logoFrontPosition: { type: String, default: 'left' },
  logoBackPosition: { type: String, default: 'above' },
  logoFrontEnabled: { type: Boolean, default: true },
  logoBackEnabled: { type: Boolean, default: true },
  logoShortsEnabled: { type: Boolean, default: false },
  logoFrontX: { type: Number, default: 273 },
  logoFrontY: { type: Number, default: 256 },
  logoFrontScale: { type: Number, default: 1 },
  logoBackX: { type: Number, default: 735 },
  logoBackY: { type: Number, default: 245 },
  logoBackScale: { type: Number, default: 1 },
  logoShortsX: { type: Number, default: 275 },
  logoShortsY: { type: Number, default: 686 },
  logoShortsScale: { type: Number, default: 1 },
  jerseyNumberX: { type: Number, default: 225 },
  jerseyNumberY: { type: Number, default: 394 },
  jerseyNumberScale: { type: Number, default: 1 },
  jerseyBackNumberX: { type: Number, default: 735 },
  jerseyBackNumberY: { type: Number, default: 371 },
  jerseyBackNumberScale: { type: Number, default: 1 },
  shortsNumberX: { type: Number, default: 178 },
  shortsNumberY: { type: Number, default: 755 },
  shortsNumberScale: { type: Number, default: 1 },
  editable: { type: Boolean, default: false },
  selectedPart: { type: String, default: 'jersey' },
});
const emit = defineEmits(['select-element', 'change-element']);
const svg = ref(null);
const garmentCoordinates = ref(null);
const drag = ref(null);
const prefix = 'mskba-kit-' + getCurrentInstance().uid;

// The geometry is re-drafted from the exact silhouette of the supplied
// three-angle photographic reference; right profile is its mirrored left.
const shapes = {
  front: {
    jersey: 'M184 182 Q165 188 149 205 L152 259 Q152 281 130 303 L136 455 L125 503 Q162 529 208 527 Q267 528 324 503 L313 438 L319 302 Q297 282 298 265 L301 208 L265 182 Q251 189 240 189 Q216 194 184 182Z',
    shorts: 'M152 539 Q225 535 296 539 L319 635 L330 765 Q299 774 233 778 L225 747 L216 778 Q149 777 119 765 L131 630Z',
  },
  left: {
    jersey: 'M514 183 Q483 183 464 205 Q441 227 426 284 L431 404 L419 520 Q439 537 482 535 L489 518 L491 534 Q531 536 556 522 L549 500 L541 363 L545 253 Q538 208 514 183Z',
    shorts: 'M431 538 Q487 535 541 539 L556 619 L551 677 L553 763 Q526 771 490 773 L486 760 L483 773 Q448 772 418 761 L424 689 L426 554Z',
  },
  rear: {
    jersey: 'M694 182 Q674 190 659 205 L661 265 Q655 288 640 302 L646 439 L635 503 Q661 526 717 527 Q784 532 834 500 L823 455 L829 303 Q810 282 807 258 L810 205 Q787 187 775 182 Q757 191 750 189 Q724 196 694 182Z',
    shorts: 'M661 539 Q733 535 807 539 L831 635 L841 765 Q810 775 743 778 L735 747 L726 778 Q663 779 629 765 L641 635Z',
  },
};
const lens = { XS: .87, S: .94, M: 1, L: 1.06, XL: 1.12, XXL: 1.18 };
const zoom = computed(() => lens[props.size] || 1);
const viewName = computed(() => ({ front: 'спереди', left: 'слева', right: 'справа', rear: 'сзади' })[props.view] || 'спереди');
const center = computed(() => ({ front: 225, left: 488, right: 488, rear: 735 })[props.view] || 225);
// A common frame fits the entire XXL kit.
const viewBox = computed(() => [center.value - 165, 100, 330, 760].join(' '));
const isSide = computed(() => props.view === 'left' || props.view === 'right');
const shape = computed(() => shapes[isSide.value ? 'left' : props.view] || shapes.front);
const numberLabel = computed(() => {
  const parsed = Number.parseInt(props.number, 10);
  return String(Math.max(0, Math.min(999, Number.isFinite(parsed) ? parsed : 1))).padStart(2, '0');
});
const threeDigits = computed(() => numberLabel.value.length === 3);
const isDark = computed(() => {
  const h = /^#[\da-f]{6}$/i.test(props.primary) ? props.primary.slice(1) : 'DCE7EF';
  const [r, g, b] = [0, 2, 4].map(i => {
    const v = parseInt(h.slice(i, i + 2), 16) / 255;
    return v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4;
  });
  return r * .2126 + g * .7152 + b * .0722 < .38;
});
const thread = computed(() => isDark.value ? '#C4D1D9' : '#6D8395');
const trim = computed(() => props.secondary || props.primary);
const ink = computed(() => props.numberColor || props.secondary || (props.numberOutline
  ? (isDark.value ? '#111924' : '#FFFFFF')
  : (isDark.value ? '#FFFFFF' : '#111924')));
const outlineInk = computed(() => props.numberOutlineColor || (props.secondary
  ? props.primary : (isDark.value ? '#FFFFFF' : '#111924')));
const typeStyle = computed(() => ({
  fill: ink.value, stroke: props.numberOutline ? outlineInk.value : 'none',
  strokeWidth: props.numberOutline ? 5 : 0, strokeLinejoin: 'round', paintOrder: 'stroke',
}));
const jerseyFont = computed(() => props.view === 'rear' ? (threeDigits.value ? 82 : 98) : (threeDigits.value ? 57 : 72));
const shortsFont = computed(() => threeDigits.value ? 34 : 45);
const jerseyPart = computed(() => props.view === 'rear' ? 'jersey_back' : 'jersey');
const jerseyX = computed(() => props.view === 'rear' ? props.jerseyBackNumberX : props.jerseyNumberX);
const jerseyY = computed(() => props.view === 'rear' ? props.jerseyBackNumberY : props.jerseyNumberY);
const jerseyScale = computed(() => props.view === 'rear' ? props.jerseyBackNumberScale : props.jerseyNumberScale);
const partSizes = computed(() => ({
  jersey: { width: jerseyFont.value * (threeDigits.value ? 1.72 : 1.32), height: jerseyFont.value },
  shorts: { width: shortsFont.value * (threeDigits.value ? 1.72 : 1.32), height: shortsFont.value },
}));
const isInteractive = computed(() => props.editable && !isSide.value);
const at = which => {
  const prefix = which.startsWith('logo_') ? which : which + '_number';
  return {
    x: props[prefix === 'jersey_number' ? 'jerseyNumberX' :
      prefix === 'jersey_back_number' ? 'jerseyBackNumberX' :
      prefix === 'shorts_number' ? 'shortsNumberX' :
      prefix === 'logo_front' ? 'logoFrontX' :
      prefix === 'logo_back' ? 'logoBackX' : 'logoShortsX'],
    y: props[prefix === 'jersey_number' ? 'jerseyNumberY' :
      prefix === 'jersey_back_number' ? 'jerseyBackNumberY' :
      prefix === 'shorts_number' ? 'shortsNumberY' :
      prefix === 'logo_front' ? 'logoFrontY' :
      prefix === 'logo_back' ? 'logoBackY' : 'logoShortsY'],
    scale: props[prefix === 'jersey_number' ? 'jerseyNumberScale' :
      prefix === 'jersey_back_number' ? 'jerseyBackNumberScale' :
      prefix === 'shorts_number' ? 'shortsNumberScale' :
      prefix === 'logo_front' ? 'logoFrontScale' :
      prefix === 'logo_back' ? 'logoBackScale' : 'logoShortsScale'],
  };
};
const limits = {
  jersey: { x: [188, 268], y: [316, 450] },
  jersey_back: { x: [700, 780], y: [305, 455] },
  shorts: { x: [148, 302], y: [658, 766] },
  logo_front: { x: [172, 280], y: [252, 480] },
  logo_back: { x: [680, 795], y: [230, 478] },
  logo_shorts: { x: [160, 300], y: [620, 745] },
};
const partLimit = which => limits[which] || limits.jersey;
const clamp = (n, low, high) => Math.max(low, Math.min(high, n));
function svgPoint(event) {
  const matrix = garmentCoordinates.value?.getScreenCTM();
  if (!matrix || !svg.value) return null;
  const point = svg.value.createSVGPoint();
  point.x = event.clientX;
  point.y = event.clientY;
  return point.matrixTransform(matrix.inverse());
}
function startDrag(which, mode, event) {
  if (!isInteractive.value || (event.pointerType === 'mouse' && event.button !== 0)) return;
  const start = svgPoint(event);
  if (!start) return;
  event.preventDefault();
  event.stopPropagation();
  emit('select-element', which);
  const p = at(which);
  drag.value = {
    pointerId: event.pointerId, which, mode,
    originX: start.x, originY: start.y,
    x: p.x, y: p.y, scale: p.scale,
  };
  svg.value.setPointerCapture(event.pointerId);
}
function moveDrag(event) {
  const state = drag.value;
  if (!state || state.pointerId !== event.pointerId) return;
  const p = svgPoint(event);
  if (!p) return;
  if (state.mode === 'scale') {
    emit('change-element', {
      part: state.which,
      scale: clamp(Math.round((state.scale + (p.x - state.originX) / 96) * 100) / 100, .4, 1.6),
    });
    return;
  }
  const range = partLimit(state.which);
  emit('change-element', {
    part: state.which,
    x: clamp(Math.round(state.x + p.x - state.originX), ...range.x),
    y: clamp(Math.round(state.y + p.y - state.originY), ...range.y),
  });
}
function endDrag(event) {
  if (!drag.value || drag.value.pointerId !== event.pointerId) return;
  drag.value = null;
  if (svg.value?.hasPointerCapture?.(event.pointerId)) svg.value.releasePointerCapture(event.pointerId);
}
function clearSelection(event) {
  if (!event.target?.closest?.('[data-editable-piece]')) emit('select-element', null);
}
function moveWithKeys(which, event) {
  if (!isInteractive.value) return;
  const p = at(which);
  const step = event.shiftKey ? 10 : 2;
  const range = partLimit(which);
  let result;
  switch (event.key) {
    case 'ArrowLeft': result = { x: clamp(p.x - step, ...range.x) }; break;
    case 'ArrowRight': result = { x: clamp(p.x + step, ...range.x) }; break;
    case 'ArrowUp': result = { y: clamp(p.y - step, ...range.y) }; break;
    case 'ArrowDown': result = { y: clamp(p.y + step, ...range.y) }; break;
    case '+': case '=': result = { scale: clamp(p.scale + .05, .4, 1.6) }; break;
    case '-': result = { scale: clamp(p.scale - .05, .4, 1.6) }; break;
    default: return;
  }
  event.preventDefault();
  emit('select-element', which);
  emit('change-element', { part: which, ...result });
}
</script>

<template>
  <div class="mskba-kit-preview" :style="{ '--kit-primary': primary, '--kit-trim': trim }">
    <svg ref="svg" class="mskba-kit-preview__drawing" :class="{ 'is-number-editable': isInteractive }"
      :viewBox="viewBox" role="img" xmlns="http://www.w3.org/2000/svg"
      :aria-label="'Баскетбольная форма «' + name + '», ' + viewName + ', размер ' + size"
      @pointerdown="clearSelection" @pointermove="moveDrag" @pointerup="endDrag" @pointercancel="endDrag"
      @lostpointercapture="drag = null">
      <defs>
        <linearGradient :id="prefix + '-sheen'" x1="0" y1="0" x2="1" y2=".12">
          <stop offset="0" stop-color="#FFFFFF" stop-opacity=".06"/>
          <stop offset=".3" stop-color="#FFFFFF" stop-opacity=".015"/>
          <stop offset=".78" stop-color="#101A20" stop-opacity=".025"/>
          <stop offset="1" stop-color="#101A20" stop-opacity=".08"/>
        </linearGradient>
        <mask :id="prefix + '-side-hole'" maskUnits="userSpaceOnUse" x="390" y="170" width="175" height="370">
          <rect x="390" y="170" width="175" height="370" fill="#FFFFFF"/>
          <ellipse cx="486" cy="263" rx="25" ry="45" transform="rotate(1 486 263)" fill="#000000"/>
        </mask>
      </defs>
      <g ref="garmentCoordinates"
        :transform="'translate(' + center + ' 480) scale(' + zoom + ') translate(' + (-center) + ' -480)'">
        <g :transform="view === 'right' ? 'translate(976 0) scale(-1 1)' : undefined">
          <!-- Outer garment contours follow the reference, not an invented t-shirt draft. -->
          <path :d="shape.jersey" fill="var(--kit-primary)" :stroke="thread"
            stroke-width="1.8" stroke-opacity=".55" stroke-linejoin="round"
            :mask="isSide ? 'url(#' + prefix + '-side-hole)' : undefined"/>
          <path :d="shape.jersey" :fill="'url(#' + prefix + '-sheen)'"
            :mask="isSide ? 'url(#' + prefix + '-side-hole)' : undefined"/>

          <template v-if="view === 'front'">
            <!-- True V-neck and sleeveless deep arm openings. No sleeves at any angle. -->
            <path d="M182 185Q188 207 225 236Q262 208 268 185"
              fill="none" stroke="var(--kit-trim)" stroke-width="7" stroke-linejoin="round"/>
            <path d="M184 186Q192 207 225 233Q260 208 266 185"
              fill="none" :stroke="thread" stroke-width=".9" stroke-opacity=".5"/>
            <path d="M151 204Q158 277 131 302L134 322M298 204Q287 275 317 303L314 322"
              fill="none" stroke="var(--kit-trim)" stroke-width="5.5"/>
            <path d="M146 307Q150 407 145 478L139 505M304 305Q301 411 306 476L313 505"
              stroke="var(--kit-trim)" stroke-width="5.5" fill="none"/>
            <path d="M130 493L146 475Q152 492 150 513L126 503ZM304 474L313 471L324 503L300 517Q297 489 304 474Z"
              fill="var(--kit-trim)"/>
            <path d="M146 320L146 473M303 318L303 471"
              :stroke="thread" stroke-opacity=".48" stroke-width="1.5" stroke-dasharray="17 9"/>
          </template>
          <template v-else-if="view === 'rear'">
            <path d="M694 185Q729 203 775 185"
              fill="none" stroke="var(--kit-trim)" stroke-width="6.5"/>
            <path d="M659 207Q670 281 641 302L645 322M810 207Q798 279 828 304L825 322"
              fill="none" stroke="var(--kit-trim)" stroke-width="5.5"/>
            <path d="M655 307L658 477L643 506M814 306L810 474L829 504"
              fill="none" stroke="var(--kit-trim)" stroke-width="6"/>
            <path d="M638 500L646 473L659 478L659 511Q643 511 635 503ZM810 474L823 473L834 500Q824 512 809 513Z"
              fill="var(--kit-trim)"/>
            <path d="M657 332V468M812 332V468" :stroke="thread"
              stroke-opacity=".46" stroke-width="1.5" stroke-dasharray="17 9"/>
          </template>
          <template v-else>
            <path d="M512 186Q463 195 433 271" stroke="var(--kit-trim)"
              fill="none" stroke-width="5"/>
            <ellipse cx="486" cy="263" rx="27" ry="47" fill="none"
              stroke="var(--kit-trim)" stroke-width="5"/>
            <path d="M474 307V531M500 308V531" fill="none"
              stroke="var(--kit-trim)" stroke-width="6"/>
            <path d="M486 320V470" :stroke="thread" stroke-width="4"
              stroke-dasharray="9 20" opacity=".65"/>
            <path d="M477 496L488 479L497 496V531H477Z" fill="var(--kit-trim)"/>
          </template>

          <!-- The waistband and straight leg hems are true to the reference's
               shallow crotch cut rather than a long triangular split. -->
          <path :d="shape.shorts" fill="var(--kit-primary)" :stroke="thread"
            stroke-width="1.8" stroke-opacity=".55" stroke-linejoin="round"/>
          <path :d="shape.shorts" :fill="'url(#' + prefix + '-sheen)'"/>
          <template v-if="view === 'front'">
            <path d="M152 539Q225 536 296 539L299 556Q225 560 149 556Z"
              fill="var(--kit-primary)" :stroke="thread" stroke-opacity=".55" stroke-width="1.5"/>
            <path d="M151 542Q225 541 296 542M151 551Q225 550 298 551"
              :stroke="thread" stroke-width="2" opacity=".6"/>
            <path d="M148 557L133 627L119 765L135 769L154 556Z
                     M298 557L313 626L330 765L314 769L296 557Z"
              fill="var(--kit-trim)"/>
            <path d="M137 633L132 748M312 636L318 751"
              stroke="var(--kit-primary)" stroke-width="5" stroke-dasharray="25 16" fill="none"/>
            <path d="M225 729L225 748M126 763Q162 775 216 778M233 778Q295 775 325 765"
              fill="none" :stroke="thread" stroke-width="1.6" stroke-opacity=".45"/>
          </template>
          <template v-else-if="view === 'rear'">
            <path d="M661 539Q735 536 807 539L810 556Q735 560 658 556Z"
              fill="var(--kit-primary)" :stroke="thread" stroke-opacity=".55" stroke-width="1.5"/>
            <path d="M659 542Q735 541 808 542M660 551Q735 550 808 551"
              :stroke="thread" stroke-width="2" opacity=".6"/>
            <path d="M658 558L641 628L629 765L646 769L663 555Z
                     M810 557L826 628L841 765L824 769L806 556Z"
              fill="var(--kit-trim)"/>
            <path d="M648 635L642 753M821 636L828 751"
              stroke="var(--kit-primary)" stroke-width="5" stroke-dasharray="25 16" fill="none"/>
            <path d="M735 730L735 746M636 763Q672 778 726 778M743 778Q808 776 836 765"
              fill="none" :stroke="thread" stroke-width="1.6" stroke-opacity=".45"/>
          </template>
          <template v-else>
            <path d="M431 539Q487 538 541 539L543 557Q487 560 427 556Z"
              fill="var(--kit-primary)" :stroke="thread" stroke-width="1.5" stroke-opacity=".5"/>
            <path d="M428 543H541M427 552H543" stroke="var(--kit-trim)" stroke-width="2"/>
            <path d="M473 561H501V771H473Z" fill="var(--kit-trim)"/>
            <path d="M487 622V751" :stroke="thread" stroke-width="4" stroke-dasharray="13 18"/>
            <path d="M425 752Q450 770 479 771M494 770Q532 773 551 763"
              stroke="var(--kit-primary)" fill="none" stroke-width="1.8"/>
          </template>

          <!-- Two independent jersey positions: front and rear no longer share a transform. -->
          <g v-if="numberEnabled && (view === 'front' || view === 'rear')"
            data-editable-piece :data-piece="jerseyPart"
            :transform="'translate(' + jerseyX + ' ' + jerseyY + ') scale(' + jerseyScale + ')'"
            class="mskba-kit-preview__piece"
            :tabindex="isInteractive ? 0 : undefined"
            role="group" :aria-label="'Номер на майке ' + numberLabel"
            @keydown="moveWithKeys(jerseyPart, $event)"
            @pointerdown="startDrag(jerseyPart, 'move', $event)">
            <text x="0" y="0" text-anchor="middle" font-family="Arial, Helvetica, sans-serif"
              font-weight="800" :font-size="jerseyFont" :style="typeStyle">{{ numberLabel }}</text>
            <template v-if="isInteractive">
              <rect :x="-partSizes.jersey.width / 2 - 6" :y="-partSizes.jersey.height + 5"
                :width="partSizes.jersey.width + 12" :height="partSizes.jersey.height + 8"
                class="mskba-kit-preview__drag-target"/>
              <rect v-if="selectedPart === jerseyPart" :x="-partSizes.jersey.width / 2 - 6"
                :y="-partSizes.jersey.height + 5" :width="partSizes.jersey.width + 12"
                :height="partSizes.jersey.height + 8" class="mskba-kit-preview__selection"/>
              <circle v-if="selectedPart === jerseyPart" :cx="partSizes.jersey.width / 2 + 6"
                :cy="12" r="12" class="mskba-kit-preview__resize" role="button"
                :aria-label="'Изменить масштаб номера ' + (view === 'rear' ? 'сзади' : 'спереди')"
                @pointerdown.stop="startDrag(jerseyPart, 'scale', $event)"/>
            </template>
          </g>
          <g v-if="numberEnabled && view === 'front'"
            data-editable-piece data-piece="shorts"
            :transform="'translate(' + shortsNumberX + ' ' + shortsNumberY + ') scale(' + shortsNumberScale + ')'"
            class="mskba-kit-preview__piece"
            :tabindex="isInteractive ? 0 : undefined" role="group"
            :aria-label="'Номер на шортах ' + numberLabel"
            @keydown="moveWithKeys('shorts', $event)"
            @pointerdown="startDrag('shorts', 'move', $event)">
            <text x="0" y="0" text-anchor="middle" font-family="Arial, Helvetica, sans-serif"
              font-weight="800" :font-size="shortsFont" :style="typeStyle">{{ numberLabel }}</text>
            <template v-if="isInteractive">
              <rect :x="-partSizes.shorts.width / 2 - 7" :y="-partSizes.shorts.height + 5"
                :width="partSizes.shorts.width + 14" :height="partSizes.shorts.height + 10"
                class="mskba-kit-preview__drag-target"/>
              <rect v-if="selectedPart === 'shorts'" :x="-partSizes.shorts.width / 2 - 7"
                :y="-partSizes.shorts.height + 5" :width="partSizes.shorts.width + 14"
                :height="partSizes.shorts.height + 10" class="mskba-kit-preview__selection"/>
              <circle v-if="selectedPart === 'shorts'" :cx="partSizes.shorts.width / 2 + 7"
                :cy="15" r="12" class="mskba-kit-preview__resize"
                @pointerdown.stop="startDrag('shorts', 'scale', $event)"/>
            </template>
          </g>

          <!-- All logo placements have independent x/y/scale; no dependency on numbering. -->
          <g v-if="logo && logoFrontEnabled && view === 'front'" data-editable-piece
            data-piece="logo_front" class="mskba-kit-preview__piece"
            :transform="'translate(' + logoFrontX + ' ' + logoFrontY + ') scale(' + logoFrontScale + ')'"
            :tabindex="isInteractive ? 0 : undefined" role="group" aria-label="Логотип спереди"
            @keydown="moveWithKeys('logo_front', $event)"
            @pointerdown="startDrag('logo_front', 'move', $event)">
            <image :href="logo" x="-15" y="-15" width="30" height="30" preserveAspectRatio="xMidYMid meet"/>
            <rect v-if="isInteractive" x="-17" y="-17" width="34" height="34"
              class="mskba-kit-preview__drag-target"/>
            <rect v-if="isInteractive && selectedPart === 'logo_front'"
              x="-17" y="-17" width="34" height="34" class="mskba-kit-preview__selection"/>
            <circle v-if="isInteractive && selectedPart === 'logo_front'" cx="22" cy="22" r="12"
              class="mskba-kit-preview__resize" @pointerdown.stop="startDrag('logo_front', 'scale', $event)"/>
          </g>
          <g v-if="logo && logoBackEnabled && view === 'rear'" data-editable-piece
            data-piece="logo_back" class="mskba-kit-preview__piece"
            :transform="'translate(' + logoBackX + ' ' + logoBackY + ') scale(' + logoBackScale + ')'"
            :tabindex="isInteractive ? 0 : undefined" role="group" aria-label="Логотип сзади"
            @keydown="moveWithKeys('logo_back', $event)"
            @pointerdown="startDrag('logo_back', 'move', $event)">
            <image :href="logo" x="-17" y="-17" width="34" height="34" preserveAspectRatio="xMidYMid meet"/>
            <rect v-if="isInteractive" x="-19" y="-19" width="38" height="38"
              class="mskba-kit-preview__drag-target"/>
            <rect v-if="isInteractive && selectedPart === 'logo_back'"
              x="-19" y="-19" width="38" height="38" class="mskba-kit-preview__selection"/>
            <circle v-if="isInteractive && selectedPart === 'logo_back'" cx="24" cy="24" r="12"
              class="mskba-kit-preview__resize" @pointerdown.stop="startDrag('logo_back', 'scale', $event)"/>
          </g>
          <g v-if="logo && logoShortsEnabled && view === 'front'" data-editable-piece
            data-piece="logo_shorts" class="mskba-kit-preview__piece"
            :transform="'translate(' + logoShortsX + ' ' + logoShortsY + ') scale(' + logoShortsScale + ')'"
            :tabindex="isInteractive ? 0 : undefined" role="group" aria-label="Логотип на шортах"
            @keydown="moveWithKeys('logo_shorts', $event)"
            @pointerdown="startDrag('logo_shorts', 'move', $event)">
            <image :href="logo" x="-15" y="-15" width="30" height="30" preserveAspectRatio="xMidYMid meet"/>
            <rect v-if="isInteractive" x="-17" y="-17" width="34" height="34"
              class="mskba-kit-preview__drag-target"/>
            <rect v-if="isInteractive && selectedPart === 'logo_shorts'"
              x="-17" y="-17" width="34" height="34" class="mskba-kit-preview__selection"/>
            <circle v-if="isInteractive && selectedPart === 'logo_shorts'" cx="22" cy="22" r="12"
              class="mskba-kit-preview__resize" @pointerdown.stop="startDrag('logo_shorts', 'scale', $event)"/>
          </g>
        </g>
      </g>
    </svg>
  </div>
</template>
