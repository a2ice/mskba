<script setup>
import { ref, watch, nextTick, onBeforeUnmount } from 'vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, required: true },
  wide: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);
const dialog = ref(null);
const headingId = 'mskba-uniform-dialog-' + Math.random().toString(36).slice(2, 10);
const handleBackdrop = (event) => {
  if (event.target === dialog.value) emit('close');
};
watch(() => props.open, async (isOpen) => {
  await nextTick();
  if (isOpen && dialog.value && !dialog.value.open) dialog.value.showModal();
  if (!isOpen && dialog.value?.open) dialog.value.close();
}, { immediate: true });
onBeforeUnmount(() => { if (dialog.value?.open) dialog.value.close(); });
</script>

<template>
  <Teleport to="body">
    <dialog ref="dialog" class="mskba-uniform-dialog" :class="{ 'mskba-uniform-dialog--wide': wide }"
      :aria-labelledby="headingId" @cancel.prevent="emit('close')" @click="handleBackdrop" @close="emit('close')">
      <div class="mskba-uniform-dialog__panel">
        <header class="mskba-uniform-dialog__header">
          <div>
            <span class="mskba-uniform-dialog__eyebrow">MSKBA · ЭКИПИРОВКА</span>
            <h2 :id="headingId">{{ title }}</h2>
          </div>
          <button class="mskba-uniform-dialog__close" type="button" aria-label="Закрыть окно" @click="emit('close')">×</button>
        </header>
        <div class="mskba-uniform-dialog__body"><slot /></div>
      </div>
    </dialog>
  </Teleport>
</template>
