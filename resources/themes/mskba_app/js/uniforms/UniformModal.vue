<script setup>
import { nextTick, ref, watch } from 'vue';
import UniformWorkshop from './UniformWorkshop.vue';

const props = defineProps({ state: { type: Object, required: true } });
const dialog = ref(null);
function close() {
  props.state.open = false;
  dialog.value?.close();
}
watch(() => props.state.open, async active => {
  if (active) {
    await nextTick();
    if (!dialog.value?.open) dialog.value?.showModal();
  } else if (dialog.value?.open) dialog.value.close();
});
function backdrop(event) {
  if (event.target !== dialog.value) return;
  const rect = dialog.value.getBoundingClientRect();
  if (event.clientX < rect.left || event.clientX > rect.right ||
    event.clientY < rect.top || event.clientY > rect.bottom) close();
}
</script>

<template>
  <dialog ref="dialog" class="mskba-modal mskba-uniform-dialog" aria-labelledby="mskba-uniform-modal-title"
    @click="backdrop" @close="state.open = false">
    <header class="mskba-modal__header app-profile__request-header">
      <h2 id="mskba-uniform-modal-title" tabindex="-1">Мастерская формы</h2>
      <button type="button" class="app-profile__dialog-close" aria-label="Закрыть" @click="close">×</button>
    </header>
    <div class="mskba-modal__body mskba-scroll mskba-uniform-dialog__body">
      <UniformWorkshop v-if="state.open" :key="state.seq" :initial-id="state.projectId" :context="state.context"/>
    </div>
  </dialog>
</template>
