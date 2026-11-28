{{-- Actual shared editor and list, with local fixtures only. --}}
<main class="container" style="padding-block:32px">
    <button type="button" class="button secondary" data-studio-open>Создать мероприятие</button>
</main>
<dialog class="mskba-modal event-game-editor" data-studio-event aria-labelledby="studio-event-title">
    <header class="mskba-modal__header event-game-editor__header">
        <h2 id="studio-event-title">Создать мероприятие</h2>
        <button type="button" class="icon-button" data-studio-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    </header>
    <div class="mskba-modal__body mskba-scroll event-game-editor__body">
        <label class="event-game-editor__field"><span>Тип мероприятия</span><select><option>Игра</option><option>Тренировка</option></select></label>
        @include('components.event-game-plan-list', ['planId'=>'studio-game-plan'])
        <label class="event-game-editor__field"><span>Название</span><input type="text" value="Игра на площадке"></label>
        <label class="event-game-editor__field"><span>Площадка</span><input type="text" value="ДелоСпорт"></label>
    </div>
    <footer class="mskba-modal__footer event-game-editor__footer"><button type="button" class="button primary" data-studio-close>Далее</button></footer>
</dialog>
@include('components.event-game-editor', ['editorId'=>'studio-game-editor'])
<script type="module">
{!! file_get_contents(resource_path('js/event-game-editor.js')) !!}
{!! str_replace("import {gamePresets, mountEventGameEditor} from './event-game-editor.js';", '', file_get_contents(resource_path('js/event-game-plan.js'))) !!}
const parent=document.querySelector('[data-studio-event]');
parent.after(document.querySelector('[data-game-plan-delete-dialog]'));
const fixtureTeams=[{id:1,name:'MSKBA',logo_url:null},{id:2,name:'Basket Team',logo_url:null},{id:3,name:'Basket Moscow',logo_url:null}];
const plan=mountEventGamePlan(document.querySelector('[data-game-plan]'),document.querySelector('[data-game-editor]'),document.querySelector('[data-game-plan-delete-dialog]'),{
    searchTeams:async(query,exclude)=>fixtureTeams.filter(team=>team.id!==exclude&&team.name.toLowerCase().includes(query.toLowerCase())),
});
const state=@json($state);
if(state==='multiple'||state==='teams'){
    plan.restore([
        {value:{title:'Стритбол 3×3',format:'streetball_3x3',recruitment_mode:'individual_draft',scoring_type:'streetball',timing_mode:'whole_game'},teams:{}},
        {value:{title:'Баскетбол 5×5',format:'basketball_5x5',recruitment_mode:'preformed_teams',team_a_id:1,team_b_id:2,scoring_type:'basketball',timing_mode:'periods',periods_count:4},teams:{a:fixtureTeams[0],b:fixtureTeams[1]}},
    ]);
}
document.querySelector('[data-studio-open]').addEventListener('click',()=>parent.showModal());
document.querySelectorAll('[data-studio-close]').forEach(button=>button.addEventListener('click',()=>parent.close()));
parent.showModal();
if(state==='editing')document.querySelector('[data-game-plan-first]').click();
if(state==='teams')document.querySelectorAll('.event-game-plan__name')[1]?.click();
if(state==='delete'){
    plan.restore([{value:{title:'Игра',format:null},teams:{}}]);
    document.querySelector('.event-game-plan__remove')?.click();
}
</script>
