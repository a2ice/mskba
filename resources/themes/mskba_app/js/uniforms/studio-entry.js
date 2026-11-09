import { createApp, reactive } from 'vue';
import UniformWorkshop from './UniformWorkshop.vue';
import UniformModal from './UniformModal.vue';
import MyUniforms from './MyUniforms.vue';
import '../../css/uniform-studio.css';

const modal = reactive({open:false,seq:0,context:'home',projectId:null,teamId:null});
let mounted = false;
export function openUniformStudio({context='home',projectId=null,teamId=null} = {}) {
  if (!mounted) {
    const root = document.createElement('div');
    root.dataset.mskbaUniformDialogRoot = '';
    document.body.appendChild(root);
    createApp(UniformModal,{state:modal}).mount(root);
    mounted = true;
  }
  modal.context = context;
  modal.projectId = projectId;
  modal.teamId = teamId;
  modal.seq += 1;
  modal.open = true;
}
const editor = document.querySelector('[data-mskba-uniform-page]');
if (editor) {
  const projectId = new URL(location.href).searchParams.get('project');
  createApp(UniformWorkshop,{context:'page',initialId:projectId}).mount(editor);
}
const library = document.querySelector('[data-mskba-uniform-list]');
if (library) createApp(MyUniforms).mount(library);
