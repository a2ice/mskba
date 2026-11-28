import {gamePresets, mountEventGameEditor} from './event-game-editor.js';

// Restore draft metadata separately from the server payload. Legacy drafts only
// restore an actual opted-in game; opening the new wizard never creates a game.
export function restoreEventGamePlanDraft(saved) {
    if(Array.isArray(saved?.gamePlan))return saved.gamePlan;
    const draft=saved?.draft||{};
    if(Array.isArray(draft.games))return draft.games.map(value=>({value,teams:{}}));
    if(draft.type!=='game'||String(draft.create_game)!=='1')return [];
    const preset=gamePresets[draft.game_format];
    if(!preset)return [];
    return [{value:{title:preset.title,format:draft.game_format,recruitment_mode:'individual_draft',
        scoring_type:preset.scoring_type,timing_mode:preset.periods==='whole_game'?'whole_game':'periods',
        periods_count:preset.periods==='whole_game'?null:Number(preset.periods)},teams:{}}];
}

export function gamePlanValidation(errors) {
    for(const key of Object.keys(errors||{})){
        const match=/^games\.(\d+)\./.exec(key);
        if(!match)continue;
        const index=Number(match[1]), prefix='games.'+index+'.';
        return {index,errors:Object.fromEntries(Object.entries(errors).filter(([field])=>field.startsWith(prefix)).map(([field,messages])=>[field.slice(prefix.length),Array.isArray(messages)?messages[0]:messages]))};
    }
    return null;
}

// Adapter for the event wizard / isolated studio. No network writes.
export function mountEventGamePlan(root, editorDialog, deleteDialog, {searchTeams, onChange=()=>{}}) {
    const list=root.querySelector('[data-game-plan-list]');
    const empty=root.querySelector('[data-game-plan-empty]');
    const first=root.querySelector('[data-game-plan-first]');
    const add=root.querySelector('[data-game-plan-add]');
    const help=root.querySelector('[data-game-plan-help]');
    const helpText=root.querySelector('[data-game-plan-help-text]');
    let games=[], nextKey=1, deleting=null;
    const label=row=>[gamePresets[row.value.format]?.label, row.value.format&&row.value.format!=='streetball_1x1'?(row.value.recruitment_mode==='preformed_teams'?'Между командами':'Из участников'):null].filter(Boolean).join(' · ');
    const teamsLabel=row=>[row.teams.a?.name,row.teams.b?.name].filter(Boolean).join(' — ');
    const editor=mountEventGameEditor(editorDialog,{searchTeams,onSave(value,teams,key){
        if(key===null){
            if(games.length>=50)throw Error('Можно добавить не более 50 игр.');
            games.push({key:nextKey++,value,teams});
        }else{
            const index=games.findIndex(row=>row.key===key);
            if(index<0)throw Error('Игра больше не находится в списке.');
            games[index]={key,value,teams};
        }
        render();onChange();
    }});
    function action(text,icon,callback,remove=false){
        const button=document.createElement('button');button.type='button';button.className='icon-button event-game-plan__icon'+(remove?' event-game-plan__remove':'');button.title=text;button.setAttribute('aria-label',text);
        button.innerHTML=icon;button.addEventListener('click',callback);return button;
    }
    function render(){
        first.checked=false;empty.hidden=games.length>0;add.hidden=!games.length;
        if(games.length){helpText.hidden=true;help.setAttribute('aria-expanded','false');}
        list.replaceChildren();
        for(const row of games){
            const item=document.createElement('div');item.className='event-game-plan__row';
            const details=document.createElement('div');details.className='event-game-plan__details';
            const edit=()=>editor.open(row.value,row.teams,row.key);
            const title=document.createElement('button');title.type='button';title.className='event-game-plan__name';title.textContent=row.value.title;title.addEventListener('click',edit);details.append(title);
            for(const line of [label(row),teamsLabel(row)])if(line){const p=document.createElement('p');p.className='event-game-editor__hint';p.textContent=line;details.append(p);}
            const remove=()=>{deleting=row.key;deleteDialog.querySelector('[data-game-plan-delete-name]').textContent=row.value.title;deleteDialog.querySelector('[data-game-plan-delete-info]').textContent=[label(row),teamsLabel(row)].filter(Boolean).join(' · ');deleteDialog.showModal();};
            item.append(details,action('Редактировать игру','<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 20 4-1 12-12-3-3L5 16l-1 4zM14 7l3 3"/></svg>',edit),action('Удалить игру','<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>',remove,true));
            list.append(item);
        }
    }
    first.addEventListener('change',()=>{first.checked=false;editor.open();});
    add.addEventListener('click',()=>editor.open());
    help.addEventListener('click',()=>{helpText.hidden=!helpText.hidden;help.setAttribute('aria-expanded',String(!helpText.hidden));});
    deleteDialog.querySelectorAll('[data-game-plan-delete-cancel]').forEach(button=>button.addEventListener('click',()=>deleteDialog.close()));
    deleteDialog.querySelector('[data-game-plan-delete-confirm]').addEventListener('click',()=>{
        games=games.filter(row=>row.key!==deleting);deleteDialog.close();deleting=null;render();onChange();
    });
    render();
    return {
        showValidation({index,errors}){
            const row=games[index];if(!row)return false;
            editor.open(row.value,row.teams,row.key,errors);return true;
        },
        values:()=>games.map(row=>({...row.value})),
        draft:()=>games.map(row=>({value:{...row.value},teams:{...row.teams}})),
        restore(rows){games=Array.isArray(rows)?rows.slice(0,50).filter(row=>row?.value?.title).map(row=>({key:nextKey++,value:row.value,teams:row.teams||{}})):[];render();},
    };
}
