<script setup>
import { nextTick, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import UniformWorkshop from './UniformWorkshop.vue';

const props = defineProps({ state: { type: Object, required: true } });
const dialog = ref(null);
function close(saved = false) {
  const context = props.state.context;
  if (!props.state.open) return;
  props.state.open = false;
  if (dialog.value?.open) dialog.value.close();
  if (context === 'player-avatar') {
    window.dispatchEvent(new CustomEvent('mskba:uniform:closed', {
      detail: { context, saved },
    }));
  }
}
function onSaved(event) {
  if (event.detail?.context === 'player-avatar' && props.state.context === 'player-avatar') {
    close(true);
  }
}
onMounted(() => window.addEventListener('mskba:uniform:saved', onSaved));
onBeforeUnmount(() => window.removeEventListener('mskba:uniform:saved', onSaved));
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
    @click="backdrop" @close="close(false)">
    <header class="mskba-modal__header app-profile__request-header">
      <h2 id="mskba-uniform-modal-title" tabindex="-1">Мастерская формы</h2>
      <button type="button" class="app-profile__dialog-close" aria-label="Закрыть" @click="close(false)">×</button>
    </header>
    <div class="mskba-modal__body mskba-scroll mskba-uniform-dialog__body">
      <UniformWorkshop v-if="state.open" :key="state.seq" :initial-id="state.projectId" :context="state.context"/>
    </div>
  </dialog>
</template>
