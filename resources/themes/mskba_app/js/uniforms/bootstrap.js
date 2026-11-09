// Small, theme-wide bridge. The heavier workshop is loaded only when needed.
const PENDING = 'mskba.uniform.pending.v1';
let savingPending = false;
const authenticated = () => document.querySelector('meta[name="mskba-authenticated"]')?.content === '1';
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

async function resumePending() {
  if (savingPending || !authenticated() || document.querySelector('[data-onboarding-pending]')) return;
  let raw = null;
  try { raw = sessionStorage.getItem(PENDING); } catch { return; }
  if (!raw) return;
  savingPending = true;
  try {
    const data = JSON.parse(raw);
    const payload = new FormData();
    for (const field of ['name','primary_color','secondary_color','logo_source']) payload.append(field,data[field]||'');
    // Drafts created before the sleeve selector existed must still be saveable.
    if (data.sleeve_style) payload.append('sleeve_style', data.sleeve_style);
    for (const field of ['jersey_number', 'number_color', 'number_outline_color', 'logo_front_position', 'logo_back_position']) {
      if (data[field] !== null && data[field] !== undefined && data[field] !== '') payload.append(field, String(data[field]));
    }
    payload.append('number_enabled', data.number_enabled === false ? '0' : '1');
    for (const part of ['jersey', 'shorts']) {
      for (const key of ['x', 'y', 'scale']) {
        const field = part + '_number_' + key;
        if (data[field] !== undefined && data[field] !== null) payload.append(field, String(data[field]));
      }
    }
    for (const key of ['x', 'y', 'scale']) {
      const field = 'jersey_back_number_' + key;
      if (data[field] !== undefined && data[field] !== null) payload.append(field, String(data[field]));
    }
    for (const place of ['front', 'back', 'shorts']) {
      const enabled = 'logo_' + place + '_enabled';
      payload.append(enabled, data[enabled] === undefined ? (place === 'shorts' ? '0' : '1') : (data[enabled] ? '1' : '0'));
      for (const key of ['x', 'y', 'scale']) {
        const field = 'logo_' + place + '_' + key;
        if (data[field] !== undefined && data[field] !== null) payload.append(field, String(data[field]));
      }
    }
    payload.append('number_outline', data.number_outline ? '1' : '0');
    (data.sizes || []).forEach((s,i)=>payload.append('sizes['+i+']',s));
    if(data.logo_source==='team'&&data.logo_team_id) payload.append('logo_team_id',data.logo_team_id);
    if(data.logo_source==='custom'&&data.logo_data) {
      const blob = await (await fetch(data.logo_data)).blob();
      payload.append('logo',blob,'uniform-logo.webp');
    }
    const res=await fetch('/uniforms/projects',{method:'POST',credentials:'same-origin',headers:{
      Accept:'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf(),
    },body:payload});
    if(!res.ok) throw new Error('Could not restore draft');
    sessionStorage.removeItem(PENDING);
    location.assign('/account/uniforms?saved=1');
  } catch { /* Preserve the pending design for a manual retry. */ }
  finally { savingPending=false; }
}
async function openStudio(context = 'home', projectId=null, teamId=null) {
  const module=await import('./studio-entry.js');
  module.openUniformStudio({context,projectId,teamId});
}
document.addEventListener('click', event => {
  // Page Builder component #7 remains Filament-owned and has been restructured:
  // the active workshop CTA is now in .tab-footer-actions. Keep support for
  // both published markup versions, but prefer an explicit data attribute.
  const el = event.target instanceof Element ? event.target.closest(
    '[data-mskba-open-workshop], ' +
    '#venues-story-uniforms .tab-content[data-tab-name="workshop"] .tab-footer-actions > button.section-cta, ' +
    '.mskba-uniform-story__panel--custom .mskba-uniform-story__actions > button:first-child'
  ) : null;
  if(!el) return;
  event.preventDefault();
  void openStudio(el.dataset.mskbaWorkshopContext||'home',el.dataset.mskbaWorkshopProject||null,el.dataset.mskbaWorkshopTeam||null);
});
window.addEventListener('mskba:uniform:open',event => {
  const detail=event.detail||{};
  void openStudio(detail.context||'profile', detail.projectId||null, detail.teamId||null);
});
window.addEventListener('mskba:auth:success',()=>{ document.querySelector('meta[name="mskba-authenticated"]')?.setAttribute('content','1');void resumePending(); });
window.addEventListener('mskba:onboarding-completed',()=>void resumePending());
if(document.querySelector('[data-mskba-uniform-page],[data-mskba-uniform-list]')) void import('./studio-entry.js');
void resumePending();
