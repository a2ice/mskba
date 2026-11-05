// One entry for both the homepage story (Page Builder owned) and the full workshop page.
import { createApp } from 'vue';
import UniformExperience from './UniformExperience.vue';
import UniformWorkshop from './UniformWorkshop.vue';
import '../../css/uniform-workshop.css';

const homeSection = document.querySelector('#venues-story-uniforms.mskba-v3-story__section--uniforms');
if (homeSection && !homeSection.dataset.mskbaUniformMounted) {
  const body = homeSection.querySelector('.mskba-v3-story__body');
  if (body) {
    const mount = document.createElement('div');
    mount.className = 'mskba-uniform-story-root';
    body.appendChild(mount);
    // Vue is mounted before original text is hidden: no blank scene when JS fails.
    createApp(UniformExperience).mount(mount);
    homeSection.classList.add('mskba-uniform-enhanced');
    homeSection.dataset.mskbaUniformMounted = '1';
  }
}

document.querySelectorAll('[data-mskba-uniform-workshop-page]').forEach((root) => {
  if (root.dataset.mskbaUniformMounted) return;
  createApp(UniformWorkshop).mount(root);
  root.dataset.mskbaUniformMounted = '1';
});
