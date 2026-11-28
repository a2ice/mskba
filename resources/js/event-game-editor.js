// Shared editor: no persistence side effects unless the caller supplies them.
export const gamePresets = {
    streetball_1x1: {title:'Стритбол 1×1', label:'1×1', scoring_type:'streetball', periods:'whole_game'},
    streetball_3x3: {title:'Стритбол 3×3', label:'3×3', scoring_type:'streetball', periods:'whole_game'},
    basketball_5x5: {title:'Баскетбол 5×5', label:'5×5', scoring_type:'basketball', periods:'4'},
};

export function mountEventGameEditor(dialog, {searchTeams, onSave}) {
    const form = dialog.querySelector('[data-game-editor-form]');
    const field = name => form.elements.namedItem(name);
    const title = field('title'), format = field('format'), mode = field('recruitment_mode');
    const scoring = field('scoring_type'), periods = field('periods');
    const typeRow = form.querySelector('[data-game-type-row]');
    const teamGroup = form.querySelector('[data-game-teams]');
    const advanced = form.querySelector('[data-game-advanced]');
    const hint = form.querySelector('[data-game-invite-hint]');
    const submit = form.querySelector('[type=submit]');
    const submitError = form.querySelector('[data-game-submit-error]');
    let selected = {a:null,b:null}, edited = false, saving = false, context = null, generation = 0;
    const pickers = {};
    const isTeams = () => !typeRow.hidden && mode.value === 'preformed_teams';
    function error(name, message = '') {
        const node = form.querySelector('[data-game-error="'+name+'"]');
        const input = name === 'title' ? title : pickers[name === 'team_a_id' ? 'a' : 'b']?.input;
        if (node) {node.textContent=message;node.hidden=!message;}
        if (input) {if(message)input.setAttribute('aria-invalid','true');else input.removeAttribute('aria-invalid');}
    }
    function sync() {
        typeRow.hidden = !format.value || format.value === 'streetball_1x1';
        advanced.hidden = !format.value;
        teamGroup.hidden = !isTeams();
        hint.hidden = !isTeams() || !selected.a || !selected.b;
        if (teamGroup.hidden) for (const p of Object.values(pickers)) p.close();
    }
    function choose(side, team) {
        const other = side === 'a' ? 'b' : 'a';
        if (team && selected[other]?.id === team.id) {error('team_'+side+'_id','Команды должны быть разными.');return;}
        selected[side] = team;
        pickers[side].input.value = team?.name || '';
        pickers[side].close();
        error('team_'+side+'_id');
        sync();
    }
    for (const side of ['a','b']) {
        const root = form.querySelector('[data-game-team="'+side+'"]');
        const input = root.querySelector('input'), list = root.querySelector('[role=listbox]');
        let seq=0, timer, options=[], active=-1;
        function close() {
            seq++;clearTimeout(timer);options=[];active=-1;list.replaceChildren();list.hidden=true;
            input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');
        }
        pickers[side]={input,close};
        function mark(index) {
            active=index;
            [...list.children].forEach((el,i)=>el.setAttribute('aria-selected',String(i===index)));
            if(index>=0)input.setAttribute('aria-activedescendant',list.children[index].id);
            else input.removeAttribute('aria-activedescendant');
        }
        function state(text) {
            list.replaceChildren();const node=document.createElement('p');node.className='event-game-editor__hint';node.textContent=text;list.append(node);
            list.hidden=false;input.setAttribute('aria-expanded','true');
        }
        input.addEventListener('input',()=>{
            selected[side]=null;close();sync();
            const query=input.value.trim();if(query.length<2)return;
            const id=++seq, current=generation;
            timer=setTimeout(async()=>{
                state('Поиск…');
                try {
                    const other=side==='a'?'b':'a';
                    const found=await searchTeams(query,selected[other]?.id??null);
                    if(id!==seq||current!==generation||!dialog.open||!isTeams())return;
                    options=found.filter(team=>team.id!==selected[other]?.id);
                    list.replaceChildren();active=-1;
                    if(!options.length){state('Команды не найдены');return;}
                    options.forEach((team,i)=>{
                        const option=document.createElement('div');option.id=list.id+'-'+i;option.className='event-game-editor__option';option.setAttribute('role','option');option.setAttribute('aria-selected','false');
                        if(team.logo_url){const image=document.createElement('img');image.src=team.logo_url;image.alt='';option.append(image);}
                        else {const avatar=document.createElement('span');avatar.className='event-game-editor__avatar';avatar.textContent=team.name.slice(0,2).toUpperCase();option.append(avatar);}
                        const text=document.createElement('span');text.textContent=team.name;option.append(text);
                        option.addEventListener('pointerdown',e=>e.preventDefault());
                        option.addEventListener('click',()=>choose(side,team));list.append(option);
                    });
                    list.hidden=false;input.setAttribute('aria-expanded','true');
                } catch {if(id===seq&&current===generation&&dialog.open)state('Не удалось загрузить команды. Попробуйте ещё раз.');}
            },200);
        });
        input.addEventListener('keydown',e=>{
            if(e.key==='Escape'&&!list.hidden){e.preventDefault();e.stopPropagation();close();}
            if(!options.length)return;
            if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();mark(active<0?(e.key==='ArrowDown'?0:options.length-1):(active+(e.key==='ArrowDown'?1:-1)+options.length)%options.length);}
            if(e.key==='Enter'&&active>=0){e.preventDefault();choose(side,options[active]);}
        });
        input.addEventListener('blur',close);
    }
    title.addEventListener('input',()=>{edited=true;if(title.value.trim())error('title');});
    format.addEventListener('change',()=>{
        const preset=gamePresets[format.value];
        if(!edited)title.value=preset?.title||'Игра';
        scoring.value=preset?.scoring_type||'streetball';periods.value=preset?.periods||'whole_game';
        mode.value='individual_draft';
        choose('a',null);choose('b',null);sync();
    });
    mode.addEventListener('change',()=>{if(mode.value!=='preformed_teams'){choose('a',null);choose('b',null);}sync();});
    dialog.querySelectorAll('[data-game-editor-close]').forEach(button=>button.addEventListener('click',()=>{if(!saving)dialog.close();}));
    dialog.addEventListener('cancel',e=>{if(saving)e.preventDefault();});
    dialog.addEventListener('close',()=>{generation++;Object.values(pickers).forEach(p=>p.close());});
    form.addEventListener('submit',async e=>{
        e.preventDefault();if(saving)return;
        let valid=true;
        if(!title.value.trim()){error('title','Укажите название игры.');valid=false;}
        if(isTeams()){
            for(const side of ['a','b'])if(!selected[side]){error('team_'+side+'_id','Выберите команду.');valid=false;}
            if(selected.a&&selected.b&&selected.a.id===selected.b.id){error('team_b_id','Команды должны быть разными.');valid=false;}
        }
        if(!valid){form.querySelector('[aria-invalid=true]')?.focus();return;}
        const value={title:title.value.trim(),format:format.value||null};
        if(format.value){
            value.recruitment_mode=isTeams()?'preformed_teams':'individual_draft';
            value.scoring_type=scoring.value;
            value.timing_mode=periods.value==='whole_game'?'whole_game':'periods';
            value.periods_count=periods.value==='whole_game'?null:Number(periods.value);
            if(isTeams()){value.team_a_id=selected.a.id;value.team_b_id=selected.b.id;}
        }
        saving=true;submit.disabled=true;submitError.hidden=true;
        try{await onSave(value,{...selected},context);dialog.close();}
        catch(e){submitError.textContent=e?.message||'Не удалось сохранить игру.';submitError.hidden=false;}
        finally{saving=false;submit.disabled=false;}
    });
    return {open(value=null,teams={},key=null){
        if(dialog.open)return;
        generation++;context=key;format.value=value?.format||'';
        const preset=gamePresets[format.value];title.value=value?.title||preset?.title||'Игра';
        edited=Boolean(value&&title.value!==(preset?.title||'Игра'));
        mode.value=value?.recruitment_mode||'individual_draft';
        scoring.value=value?.scoring_type||preset?.scoring_type||'streetball';
        periods.value=value?.timing_mode==='periods'?String(value.periods_count||4):value?.timing_mode==='whole_game'?'whole_game':preset?.periods||'whole_game';
        selected={a:teams.a||null,b:teams.b||null};
        for(const side of ['a','b']){pickers[side].input.value=selected[side]?.name||'';pickers[side].close();error('team_'+side+'_id');}
        error('title');submitError.hidden=true;advanced.open=false;
        dialog.querySelector('[data-game-editor-title]').textContent=value?'Редактировать игру':'Создать игру';
        sync();dialog.showModal();title.focus();
    }};
}
