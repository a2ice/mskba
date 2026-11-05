<script setup>
import { onMounted, ref } from 'vue';
import UniformKitPreview from './UniformKitPreview.vue';
const projects = ref([]);
const busy = ref(false);
const loading = ref(true);
const error = ref('');
const dialog = ref(null);
const action = ref('');
const selected = ref(null);
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
async function load() {
  try {
    const res = await fetch('/uniforms/projects', {credentials:'same-origin',headers:{Accept:'application/json'}});
    if (!res.ok) throw new Error('Не удалось загрузить сохранённые формы.');
    projects.value = (await res.json()).designs || [];
  } catch(e){ error.value=e.message; } finally{ loading.value=false; }
}
onMounted(load);
function open(actionType, project) { selected.value=project;action.value=actionType;dialog.value?.showModal(); }
function close() { dialog.value?.close(); }
function backdrop(e) {
  if (e.target !== dialog.value) return;
  const r=dialog.value.getBoundingClientRect();
  if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)close();
}
async function remove() {
  if (!selected.value || busy.value) return;
  busy.value=true;error.value='';
  try{
    const res=await fetch('/uniforms/projects/'+selected.value.id,{
      method:'DELETE',credentials:'same-origin',
      headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf()},
    });
    if(!res.ok)throw new Error('Не удалось удалить форму.');
    projects.value=projects.value.filter(x=>x.id!==selected.value.id);
    close();
  }catch(e){error.value=e.message;}
  finally{busy.value=false;}
}
</script>
<template>
  <div class="mskba-uniform-library">
    <div class="mskba-uniform-library__toolbar"><span>{{ projects.length }} {{ projects.length===1?'проект':'проектов' }}</span><a href="/uniforms/workshop" class="mskba-uniform-editor__save">+ Создать форму</a></div>
    <p v-if="loading" role="status">Загружаем проекты…</p>
    <p v-if="error" class="mskba-uniform-editor__error" role="alert">{{ error }}</p>
    <div v-if="!loading && !projects.length" class="mskba-uniform-library__empty">
      <span class="mskba-uniform-eyebrow">ТВОЯ ПЕРВАЯ ФОРМА</span><h2>Начни со своего стиля</h2>
      <p>Сохрани эскиз в мастерской, чтобы возвращаться к нему и развивать дизайн.</p>
      <a href="/uniforms/workshop" class="mskba-uniform-editor__save">Создать первую форму ↗</a>
    </div>
    <div v-if="projects.length" class="mskba-uniform-library__grid">
      <article v-for="item in projects" :key="item.id" class="mskba-uniform-library__card">
        <UniformKitPreview :name="item.name" :primary="item.primary_color" :secondary="item.secondary_color" :logo="item.logo_url || ''"/>
        <div class="mskba-uniform-library__card-info">
          <h2>{{ item.name }}</h2><p>Размеры: {{ item.sizes.join(' · ') }}</p>
          <div class="mskba-uniform-library__actions">
            <a :href="'/uniforms/workshop?project='+item.id" class="mskba-uniform-library__edit">Редактировать ↗</a>
            <button type="button" @click="open('production',item)">В производство</button>
            <button type="button" @click="open('delete',item)" class="mskba-uniform-library__delete">Удалить</button>
          </div>
        </div>
      </article>
    </div>
    <dialog ref="dialog" class="mskba-modal mskba-uniform-action-dialog" aria-labelledby="mskba-uniform-action-title" @click="backdrop">
      <header class="mskba-modal__header mskba-uniform-dialog__head">
        <h2 id="mskba-uniform-action-title" tabindex="-1">{{ action==='delete'?'Удалить форму?':'Изготовление формы' }}</h2>
        <button type="button" class="mskba-uniform-dialog__close" aria-label="Закрыть" @click="close">×</button>
      </header>
      <div class="mskba-modal__body mskba-uniform-action-dialog__body">
        <template v-if="action==='delete'"><p>Форма «{{ selected?.name }}» будет удалена из «Моих форм». Это действие нельзя отменить.</p>
          <button type="button" class="mskba-uniform-library__danger" :disabled="busy" @click="remove">{{ busy?'Удаляем…':'Удалить форму' }}</button></template>
        <template v-else><span class="mskba-uniform-eyebrow">СКОРО</span><h3>От дизайна — к игре</h3>
          <p>Возможность отправить форму производителю появится позже. Пока ваш эскиз сохранён и доступен для редактирования.</p>
          <button type="button" class="mskba-uniform-editor__save" @click="close">Понятно</button></template>
      </div>
    </dialog>
  </div>
</template>
