<script setup>
import { nextTick, ref, watch } from 'vue';
import UniformWorkshop from './UniformWorkshop.vue';
const props = defineProps({ state: {type:Object,required:true} });
const dialog = ref(null);
const close = () => { props.state.open = false; dialog.value?.close(); };
watch(() => props.state.open, async active => {
  if (active) { await nextTick(); if (!dialog.value?.open) dialog.value?.showModal(); }
  else if (dialog.value?.open) dialog.value.close();
});
function backdrop(event) {
  if (event.target !== dialog.value) return;
  const b = dialog.value.getBoundingClientRect();
  if (event.clientX < b.left || event.clientX > b.right || event.clientY < b.top || event.clientY > b.bottom) close();
}
</script>
<template>
  <dialog ref="dialog" class="mskba-modal mskba-uniform-dialog" aria-labelledby="mskba-uniform-modal-title"
    @click="backdrop" @close="state.open=false">
    <header class="mskba-modal__header mskba-uniform-dialog__head">
      <div><span class="mskba-uniform-eyebrow">MSKBA / ЭКИПИРОВКА</span>
      <h2 id="mskba-uniform-modal-title" tabindex="-1">Мастерская формы</h2></div>
      <button type="button" aria-label="Закрыть" class="mskba-uniform-dialog__close" @click="close">×</button>
    </header>
    <div class="mskba-modal__body mskba-scroll mskba-uniform-dialog__body">
      <UniformWorkshop v-if="state.open" :key="state.seq" :initial-id="state.projectId" :context="state.context"/>
    </div>
    <footer class="mskba-modal__footer mskba-uniform-dialog__foot">
      <a href="/uniforms/workshop">Открыть на отдельной странице ↗</a>
      <span>Полный графический редактор — следующий этап</span>
    </footer>
  </dialog>
</template>
