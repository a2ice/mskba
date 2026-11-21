@if($reportOwner)
<dialog class="mskba-modal studio-event-dialog studio-report-editor" id="studio-report-editor" aria-labelledby="studio-report-editor-title">
    <header class="mskba-modal__header"><h2 id="studio-report-editor-title">Как это было</h2><button type="button" class="icon-button" data-event-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12M6 6l12 12"/></svg></button></header>
    <div class="mskba-modal__body mskba-scroll">
        <label class="field-group">Описание<textarea rows="4" maxlength="4000" data-report-input placeholder="Расскажите, как прошло мероприятие"></textarea></label>
        <div class="studio-report-files">
            <label class="field-group">Фотографии<input type="file" multiple accept="image/jpeg,image/png,image/webp,image/gif,image/avif" data-report-file="photo"></label>
            <ul class="studio-report-file-list" data-report-file-list="photo"></ul>
        </div>
        <div class="studio-report-files">
            <label class="field-group">Видео<input type="file" multiple accept="video/mp4,video/webm,video/quicktime" data-report-file="video"></label>
            <ul class="studio-report-file-list" data-report-file-list="video"></ul>
        </div>
    </div>
    <footer class="mskba-modal__footer"><button type="button" class="button secondary" data-event-close>Отмена</button><button type="button" class="button primary" data-report-publish disabled>Опубликовать</button></footer>
</dialog>
@endif
<dialog class="mskba-modal studio-event-dialog studio-report-viewer" id="studio-report-viewer" aria-labelledby="studio-report-viewer-title">
    <header class="mskba-modal__header"><h2 id="studio-report-viewer-title">Как это было</h2><button type="button" class="icon-button" data-event-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12M6 6l12 12"/></svg></button></header>
    <div class="mskba-modal__body mskba-scroll" data-report-view-body></div>
</dialog>
<script type="module">
const section=document.getElementById('studio-report');
const viewer=document.getElementById('studio-report-viewer');
const view=viewer.querySelector('[data-report-view-body]');
function openMedia(type,url,name){
    view.replaceChildren();
    viewer.querySelector('h2').textContent=type==='photo'?'Фотоотчёт':'Видеоотчёт';
    if(!url){
        const p=document.createElement('p');p.textContent='Демонстрационный видеоотчёт. В образце нет видеофайла.';view.append(p);
    }else{
        const media=document.createElement(type==='photo'?'img':'video');
        media.src=url;
        if(type==='photo')media.alt=name||'Фото мероприятия';
        else {media.controls=true;media.playsInline=true;}
        view.append(media);
    }
    viewer.showModal();
}
viewer.addEventListener('close',()=>view.replaceChildren());
section.querySelector('[data-report-sample-photo]')?.addEventListener('click',e=>openMedia('photo',e.currentTarget.querySelector('img').src,'Демонстрационное фото'));
section.querySelector('[data-report-sample-video]')?.addEventListener('click',()=>openMedia('video',null,''));
const editor=document.getElementById('studio-report-editor');
if(editor){
    const text=editor.querySelector('[data-report-input]');
    const publish=editor.querySelector('[data-report-publish]');
    const edit=section.querySelector('[data-report-edit]');
    let published={text:'',photo:[],video:[]};
    let draft={photo:[],video:[]};
    const revoke=items=>items.forEach(item=>URL.revokeObjectURL(item.url));
    const all=state=>[...state.photo,...state.video];
    const sync=()=>{publish.disabled=!text.value.trim()&&!draft.photo.length&&!draft.video.length;};
    function renderLists(){
        for(const kind of ['photo','video']){
            const list=editor.querySelector('[data-report-file-list="'+kind+'"]');list.replaceChildren();
            draft[kind].forEach((item,i)=>{
                const row=document.createElement('li'),name=document.createElement('span'),remove=document.createElement('button');
                name.textContent=item.name;
                remove.type='button';remove.className='icon-button';remove.setAttribute('aria-label','Удалить '+item.name);remove.textContent='×';
                remove.addEventListener('click',()=>{draft[kind].splice(i,1);if(!all(published).includes(item))URL.revokeObjectURL(item.url);renderLists();});
                row.append(name,remove);list.append(row);
            });
        }
        sync();
    }
    edit.addEventListener('click',()=>{
        text.value=published.text;draft={photo:[...published.photo],video:[...published.video]};
        renderLists();
    });
    editor.querySelectorAll('[data-report-file]').forEach(input=>input.addEventListener('change',()=>{
        const kind=input.dataset.reportFile,prefix=kind==='photo'?'image/':'video/';
        for(const file of input.files){
            if(!file.type.startsWith(prefix))continue;
            draft[kind].push({name:file.name,url:URL.createObjectURL(file)});
        }
        input.value='';renderLists();
    }));
    text.addEventListener('input',sync);
    function renderReport(){
        const description=section.querySelector('[data-report-description]');
        description.textContent=published.text;description.hidden=!published.text;
        for(const kind of ['photo','video']){
            const key=kind==='photo'?'photos':'videos';
            const grid=section.querySelector('[data-report-'+key+']');grid.replaceChildren();
            section.querySelector('[data-report-'+key+'-section]').hidden=!published[kind].length;
            published[kind].forEach(item=>{
                const button=document.createElement('button');button.type='button';button.className='studio-report-tile';
                button.setAttribute('aria-label',(kind==='photo'?'Открыть фото ':'Открыть видео ')+item.name);
                if(kind==='photo'){
                    const img=document.createElement('img');img.src=item.url;img.alt=item.name;button.append(img);
                }else{
                    const video=document.createElement('video');
                    video.src=item.url;video.muted=true;video.playsInline=true;video.preload='metadata';video.setAttribute('aria-hidden','true');
                    const play=document.createElement('span');play.className='studio-report-play';play.textContent='▶';play.setAttribute('aria-hidden','true');
                    const label=document.createElement('span');label.className='studio-report-media-label';label.textContent=item.name;button.append(video,play,label);
                }
                button.addEventListener('click',()=>openMedia(kind,item.url,item.name));grid.append(button);
            });
        }
        section.querySelector('[data-report-empty]').hidden=true;
        section.querySelector('[data-report-content]').hidden=false;
        edit.textContent='Редактировать';
    }
    publish.addEventListener('click',()=>{
        if(publish.disabled)return;
        const next={text:text.value.trim(),photo:[...draft.photo],video:[...draft.video]};
        revoke(all(published).filter(item=>!all(next).includes(item)));
        published=next;renderReport();editor.close();
    });
    editor.addEventListener('close',()=>{
        revoke(all(draft).filter(item=>!all(published).includes(item)));
        draft={photo:[],video:[]};
    });
    window.addEventListener('pagehide',()=>{revoke([...new Set([...all(published),...all(draft)])]);});
}
</script>
