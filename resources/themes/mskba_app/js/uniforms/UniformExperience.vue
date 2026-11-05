<script setup>
import { computed, ref, watch, nextTick } from 'vue';
import UniformKitPreview from './UniformKitPreview.vue';
import UniformWorkshop from './UniformWorkshop.vue';
import UniformDialog from './UniformDialog.vue';
import { uniformFaq, uniformPalettes } from './uniform-options.js';

const route = ref('ready');
const palette = ref('mskba');
const modal = ref('');
const article = ref(null);
const loading = ref(false);
const error = ref(false);
let requestIndex = 0;
const activeFaq = computed(() => uniformFaq[modal.value] || null);

const open = async (kind) => {
  modal.value = kind;
  if (kind === 'designer') return;
  const faq = uniformFaq[kind];
  if (!faq) return;
  const ownRequest = ++requestIndex;
  article.value = null;
  error.value = false;
  loading.value = true;
  try {
    const query = new URLSearchParams({ section: faq.section, article: faq.article });
    const response = await fetch('/faq/help-content?' + query.toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!response.ok) throw new Error('FAQ unavailable');
    const data = await response.json();
    if (ownRequest === requestIndex) article.value = data.article || null;
    if (!data.article) throw new Error('Article not available');
  } catch {
    if (ownRequest === requestIndex) error.value = true;
  } finally {
    if (ownRequest === requestIndex) loading.value = false;
  }
};
const close = () => { modal.value = ''; requestIndex += 1; };
watch(route, async () => { await nextTick(); });
</script>

<template>
  <div class="mskba-uniform-story">
    <p class="mskba-uniform-story__lead">У каждой команды свой характер. Подчеркните его на площадке: выберите готовую экипировку или создайте собственный стиль.</p>
    <div class="mskba-uniform-story__switch" role="group" aria-label="Способ выбора формы">
      <button type="button" :aria-pressed="route === 'ready'" :class="{ 'is-active': route === 'ready' }" @click="route = 'ready'">Готовая форма</button>
      <button type="button" :aria-pressed="route === 'custom'" :class="{ 'is-active': route === 'custom' }" @click="route = 'custom'">Своя форма <span aria-hidden="true">✦</span></button>
    </div>

    <div v-if="route === 'ready'" class="mskba-uniform-story__panel">
      <span class="mskba-uniform-story__eyebrow">01 · ВЫБРАТЬ И ЭКИПИРОВАТЬСЯ</span>
      <h3>Играйте в своём стиле</h3>
      <p>Форма для тренировок, командных игр и турниров. Готовим каталог предложений продавцов и производителей баскетбольной экипировки.</p>
      <div class="mskba-uniform-story__marketplaces" aria-label="Продавцам маркетплейсов">
        <span>Ozon</span><span>Wildberries</span><span>Яндекс Маркет</span>
      </div>
      <p class="mskba-uniform-story__fine">Работаете на этих площадках? MSKBA открыт к предложениям. Указанные маркетплейсы не являются объявленными партнёрами MSKBA.</p>
      <div class="mskba-uniform-story__actions">
        <button type="button" class="mskba-uniform-button mskba-uniform-button--primary" @click="open('selection')">Подробнее <span aria-hidden="true">↗</span></button>
        <button type="button" class="mskba-uniform-button mskba-uniform-button--text" @click="open('partners')">Как стать партнёром <span aria-hidden="true">→</span></button>
      </div>
    </div>

    <div v-else class="mskba-uniform-story__panel mskba-uniform-story__panel--custom">
      <span class="mskba-uniform-story__eyebrow">02 · ПРИДУМАТЬ И СОЗДАТЬ</span>
      <h3>Не нашли свой стиль? Создайте его.</h3>
      <div class="mskba-uniform-story__designer">
        <UniformKitPreview :palette-id="palette" number="33" name="MSKBA" />
        <div class="mskba-uniform-story__designer-controls">
          <p>Примерьте палитру и откройте мастерскую для персонализации.</p>
          <div class="mskba-uniform-story__swatches" role="group" aria-label="Выбор расцветки">
            <button v-for="option in uniformPalettes" :key="option.id" type="button"
              :title="option.label" :aria-label="option.label" :aria-pressed="palette === option.id"
              :class="{ 'is-selected': palette === option.id }"
              :style="{ '--kit-base': option.base, '--kit-trim': option.trim }"
              @click="palette = option.id"><span /></button>
          </div>
        </div>
      </div>
      <div class="mskba-uniform-story__actions">
        <button type="button" class="mskba-uniform-button mskba-uniform-button--primary" @click="open('designer')">Открыть мастерскую <span aria-hidden="true">↗</span></button>
        <button type="button" class="mskba-uniform-button mskba-uniform-button--text" @click="open('workshop')">Как это работает <span aria-hidden="true">→</span></button>
      </div>
    </div>
    <p class="mskba-uniform-story__bridge">Стиль замечают. Результаты помнят. <span aria-hidden="true">↓</span></p>
    <UniformDialog :open="modal === 'designer'" title="Мастерская формы" wide @close="close">
      <UniformWorkshop :initial-palette="palette" embedded @palette-change="palette = $event" />
      <div class="mskba-uniform-story__dialog-footer">
        <a href="/uniforms/workshop">Открыть мастерскую на отдельной странице <span aria-hidden="true">↗</span></a>
      </div>
    </UniformDialog>
    <UniformDialog :open="!!activeFaq" :title="activeFaq?.title || 'Информация'" @close="close">
      <p v-if="loading" class="mskba-uniform-story__loading" role="status">Загружаем материал FAQ…</p>
      <div v-else-if="article" class="mskba-uniform-story__faq" v-html="article.html"></div>
      <p v-else class="mskba-uniform-story__loading" role="status">Материал временно недоступен. Его можно открыть в FAQ.</p>
      <div class="mskba-uniform-story__dialog-footer">
        <a :href="activeFaq?.url">Читать в FAQ <span aria-hidden="true">↗</span></a>
        <a v-if="modal === 'partners'" href="mailto:support@mskba.ru?subject=MSKBA%20-%20%D1%81%D0%BE%D1%82%D1%80%D1%83%D0%B4%D0%BD%D0%B8%D1%87%D0%B5%D1%81%D1%82%D0%B2%D0%BE">Обсудить сотрудничество <span aria-hidden="true">↗</span></a>
      </div>
    </UniformDialog>
  </div>
</template>
