@php
    $studioParticipationUrl = route('ui-studio.show', ['ui_name'=>'event-detail','theme'=>$theme,'width'=>$width,'state'=>'guest'], false);
    $studioReportMode = ($studioChatMode ?? 'discussion') === 'report';
@endphp
<script type="module">
{!! file_get_contents(resource_path('js/chat-composer-actions.js')) !!}
const reportMode = @json($studioReportMode);
const pageRoot = document.querySelector('[data-studio-chat]');
const dialog = reportMode ? document.querySelector('[data-studio-report-chat]') : pageRoot;
const form = reportMode ? dialog.querySelector('[data-chat-composer]') : pageRoot.querySelector('[data-chat-composer]');
const field = form.querySelector('textarea');
const submit = form.querySelector('[type=submit]');
const counter = form.querySelector('[data-venue-play-chars]');
const cancel = form.querySelector('[data-venue-play-edit-cancel]');
const remove = form.querySelector('[data-chat-context-delete]');
const messages = dialog.querySelector('[data-venue-play-messages]');
const empty = dialog.querySelector('[data-venue-play-wall-empty]');
const roster = dialog.querySelector('[data-venue-play-participants]');
const deleteDialog = document.querySelector('[data-venue-play-delete-dialog]');
const pageMode = !reportMode && !dialog.matches('dialog');
const modalMode = !reportMode && !pageMode;
const state = @json($state);
const eventFinished = pageRoot.dataset.eventFinished === 'true';
let viewerAuthenticated = !['guest','report-guest'].includes(state);
const reportEligible = pageRoot.dataset.reportViewerEligible === 'true';
const reportViewer = state === 'report-owner' ? 'olsen' : 'superadmin';
const mayReact = () => eventFinished && viewerAuthenticated && reportEligible;
let isParticipant = ['confirmed','tentative','editing','report-owner','report-admin','discussion-owner','discussion-closed'].includes(state);
const mayPost = () => reportMode
    ? mayReact() && pageRoot.dataset.reportCommentsEnabled === 'true'
    : pageMode ? !eventFinished && isParticipant && pageRoot.dataset.discussionEnabled === 'true' : state !== 'disabled';
let postingAllowed = mayPost();
let editing = null;
let own = null;
// Candidate-only DOM adaptation of the real shared component. After acceptance,
// promote these placements into the component itself, not a second chat.
const actionsMenu = form.querySelector('.chat-actions__menu');
cancel.classList.remove('venue-play-dialog__edit-cancel');
cancel.textContent = 'Отменить';
cancel.removeAttribute('aria-label');
cancel.removeAttribute('title');
cancel.dataset.chatAction = 'cancel-edit';
actionsMenu.prepend(cancel);
const rosterSection = dialog.querySelector('.venue-play-dialog__roster');
const rsvpSection = dialog.querySelector('[data-venue-play-rsvp]')?.closest('.venue-play-dialog__rsvp');
if (!reportMode && rosterSection && rsvpSection) rosterSection.before(rsvpSection);

const wall = dialog.querySelector('.venue-play-dialog__wall');
wall.tabIndex = -1;
function scrollToMessagesEnd() {
    wall.scrollIntoView({block:'end'});
    messages.scrollTop = messages.scrollHeight;
}
dialog.querySelector('[data-studio-messages-jump]')?.addEventListener('click', () => {
    wall.focus({preventScroll:true});
    scrollToMessagesEnd();
});

// Fixed fixture clock: identical data in all themes and viewports.
// Dates/times below use the venue's Moscow time (+03:00).
let clock = Date.parse('2026-11-21T16:39:00+03:00');
const nextStamp = () => new Date(clock += 60000).toISOString();
const dateFormat = new Intl.DateTimeFormat('ru-RU',{day:'numeric',month:'long',timeZone:'Europe/Moscow'});
const timeFormat = new Intl.DateTimeFormat('ru-RU',{hour:'2-digit',minute:'2-digit',timeZone:'Europe/Moscow'});
const dayFormat = new Intl.DateTimeFormat('sv-SE',{year:'numeric',month:'2-digit',day:'2-digit',timeZone:'Europe/Moscow'});
function setStamp(item, stamp, edited = false) {
    const date = new Date(stamp);
    item.dataset.messageStamp = date.toISOString();
    const time = item.querySelector('time');
    time.dateTime = date.toISOString();
    time.textContent = timeFormat.format(date) + (edited ? ' (изменено)' : '');
    time.title = dateFormat.format(date) + ', ' + timeFormat.format(date);
}
function regroup() {
    const items = [...messages.querySelectorAll('[data-message-stamp]')]
        .sort((a,b)=>a.dataset.messageStamp.localeCompare(b.dataset.messageStamp));
    let previousDay = '';
    const fragment = document.createDocumentFragment();
    for (const item of items) {
        const date = new Date(item.dataset.messageStamp);
        const day = dayFormat.format(date);
        if (day !== previousDay) {
            const separator = document.createElement('li');
            separator.className = 'venue-play-dialog__date';
            const label = document.createElement('time');
            label.dateTime = day;
            label.textContent = dateFormat.format(date);
            separator.append(label);
            fragment.append(separator);
            previousDay = day;
        }
        fragment.append(item);
    }
    messages.replaceChildren(fragment);
    empty.hidden = items.length !== 0;
}
const append = (author, body, rsvp = null, isOwn = false, stamp = nextStamp()) => {
    const item = document.createElement('li');
    if (rsvp && reportMode) item.dataset.reaction = rsvp;
    else if (rsvp) item.dataset.rsvpStatus = rsvp;
    const title = document.createElement('strong');
    title.textContent = author;
    const time = document.createElement('time');
    const p = document.createElement('p');
    p.textContent = body;
    item.append(title, time, p);
    setStamp(item, stamp);
    if (isOwn) {
        const edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'venue-play-dialog__edit';
        edit.setAttribute('aria-label','Редактировать сообщение');
        edit.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20l4-1 12-12-3-3L5 16l-1 4zM14 7l3 3"/></svg>';
        edit.hidden = !postingAllowed;
        edit.addEventListener('click', () => startEdit(item));
        item.append(edit);
    }
    messages.append(item);
    regroup();
    messages.scrollTop = messages.scrollHeight;
    return item;
};
const sync = () => {
    counter.textContent = 200 - field.value.length;
    submit.disabled = !postingAllowed || !field.value.trim();
    syncChatActions(form);
};
const stopEdit = () => {
    editing = null;
    field.value = '';
    cancel.hidden = true;
    remove.hidden = true;
    form.classList.remove('is-editing');
    sync();
};
function startEdit(item) {
    if (!postingAllowed) return;
    editing = item;
    field.value = item.querySelector('p').textContent;
    cancel.hidden = false;
    remove.hidden = false;
    form.classList.add('is-editing');
    sync();
    field.focus();
}
function insertMention(name) {
    if (!postingAllowed || !name || name === 'Участник') return;
    const prefix = '@' + name + ' ';
    if (!field.value.startsWith(prefix) && field.value.length + prefix.length <= field.maxLength) {
        field.setRangeText(prefix, 0, 0, 'end');
    }
    sync();
    field.focus({preventScroll:true});
    field.setSelectionRange(field.value.length, field.value.length);
    scrollToMessagesEnd();
}
const canMentionReport = () => mayReact() && pageRoot.dataset.reportCommentsEnabled === 'true';
function rosterRow(name, status = 'confirmed') {
    const li = document.createElement('li');
    const participant = document.createElement('button');
    participant.type = 'button';
    participant.className = 'venue-play-dialog__participant';
    participant.disabled = !(postingAllowed || canMentionReport()) || name === 'Участник';
    li.dataset.studioParticipant = name;
    li.dataset.studioStatus = status;
    participant.innerHTML = '<span class="venue-play-dialog__avatar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8M5 21v-2a7 7 0 0 1 14 0v2"/></svg></span><span class="venue-play-dialog__participant-status is-confirmed"></span><span class="venue-play-dialog__participant-name"></span>';
    participant.querySelector('.venue-play-dialog__participant-name').textContent = name;
    participant.querySelector('.venue-play-dialog__participant-status').classList.toggle('is-confirmed',status === 'confirmed');
    if (pageMode && name === 'olsen') {
        const star = document.createElement('span');star.className = 'venue-play-dialog__participant-star';
        star.title = 'Организатор';star.textContent = '★';participant.append(star);
    }
    participant.addEventListener('click', () => {
        if(eventFinished && canMentionReport())pageRoot.dispatchEvent(new CustomEvent('studio:report-mention',{detail:{name}}));
        else insertMention(name);
    });
    li.append(participant);
    return li;
}
const updateRosterHeading = () => {
    if (!roster) return;
    const count=roster.children.length;
    dialog.querySelector('[data-venue-play-roster-heading]').textContent = 'Участники (' + count + '/20)';
    const audience=dialog.querySelector('[data-studio-audience-avatars]');
    if(!audience)return;
    audience.replaceChildren();
    for(const row of [...roster.children].slice(0,7)){
        const name=row.dataset.studioParticipant;
        const avatar=document.createElement('span');
        avatar.className='app-venue-interest-summary__avatar';
        avatar.dataset.status=row.dataset.studioStatus;
        avatar.title=name;
        if(name==='olsen'){
            const image=document.createElement('img');
            image.src='/build/assets/logo-mark-100--FePTRLm.png';image.alt='';
            image.addEventListener('error',()=>avatar.replaceChildren(document.createTextNode('O')),{once:true});
            avatar.append(image);
        }else{
            const icon=row.querySelector('.venue-play-dialog__avatar svg')?.cloneNode(true);
            if(icon){icon.classList.add('app-venue-interest-summary__profile-icon');avatar.append(icon);}
        }
        audience.append(avatar);
    }
    const mod=count%100,ones=count%10;
    const word=mod>=11&&mod<=14?'участников':ones===1?'участник':ones>=2&&ones<=4?'участника':'участников';
    dialog.querySelector('[data-studio-audience-count]').textContent=count+' '+word;
};
if (roster) {
    for (const name of (pageMode ? ['olsen','Участник'] : ['olsen','superadmin'])) roster.append(rosterRow(name));
    if(pageMode && (isParticipant || eventFinished))roster.append(rosterRow('superadmin',state === 'tentative' ? 'tentative' : 'confirmed'));
    updateRosterHeading();
    dialog.querySelector('[data-venue-play-roster-empty]').hidden = true;
}
empty.textContent = reportMode ? 'Комментариев пока нет' : 'Сообщений пока нет';
if (reportMode) {
    field.placeholder='Написать комментарий';field.setAttribute('aria-label','Написать комментарий');
    submit.setAttribute('aria-label','Отправить комментарий');submit.title='Отправить комментарий';
    dialog.querySelector('.venue-play-dialog__wall').setAttribute('aria-label','Комментарии к мероприятию');
    if (['report','report-guest','report-outsider','report-closed'].includes(state)) {
        append('Событие','Пользователю olsen нравится мероприятие.','like',false,'2026-11-19T14:10:00+03:00');
        append('olsen','Отлично поиграли, спасибо всем!',null,false,'2026-11-19T14:12:00+03:00');
        append('superadmin','До встречи на следующей игре!',null,reportEligible && reportViewer === 'superadmin','2026-11-19T14:15:00+03:00');
    }
} else if (state !== 'empty') {
    append('Событие','olsen пойдёт на мероприятие.','confirmed',false,'2026-11-19T18:20:00+03:00');
    append('Событие','olsen не пойдёт на мероприятие.','left',false,'2026-11-19T19:05:00+03:00');
    append('Событие','superadmin пойдёт на мероприятие.','confirmed',false,'2026-11-21T16:35:00+03:00');
}
if (!reportMode && (state === 'editing' || pageMode && state === 'confirmed')) own = append('superadmin','Проверка сообщения.',null,true,'2026-11-21T16:37:00+03:00');
const commentNotice = dialog.querySelector('[data-venue-play-comment-notice]');
const participationLink = document.createElement('a');
participationLink.href = pageMode ? '#studio-event-title' : @json($studioParticipationUrl);
if (modalMode) participationLink.target = '_top';
participationLink.className = 'venue-play-dialog__participation-link';
participationLink.textContent = 'Участвовать';
if (pageMode) participationLink.addEventListener('click', event => {
    event.preventDefault();dialog.dispatchEvent(new CustomEvent('studio:participate'));
});
const loginLink=document.createElement('a');
loginLink.href='#studio-auth';loginLink.className='venue-play-dialog__participation-link';loginLink.textContent='Войти';
loginLink.addEventListener('click',e=>{e.preventDefault();pageRoot.dispatchEvent(new CustomEvent('studio:report-auth'));});
function setPosting(allowed) {
    if(!allowed && editing)stopEdit();
    postingAllowed = !!allowed;
    setChatComposerAccess(form, postingAllowed);
    commentNotice.hidden = postingAllowed;
    if(reportMode) {
        if(pageRoot.dataset.reportCommentsEnabled !== 'true')commentNotice.textContent='Комментарии закрыты.';
        else if(!viewerAuthenticated)commentNotice.replaceChildren('Оценивать и комментировать могут только участники и организатор мероприятия. ',loginLink);
        else if(!reportEligible)commentNotice.textContent='Оценивать и комментировать могут только участники и организатор мероприятия.';
    } else if(eventFinished)commentNotice.textContent='Мероприятие завершено. Обсуждение закрыто.';
    else if(pageMode && pageRoot.dataset.discussionEnabled !== 'true')commentNotice.textContent='Обсуждение закрыто.';
    else commentNotice.replaceChildren('Написать сообщение могут участники мероприятия. ', participationLink);
    roster?.querySelectorAll('button').forEach(button => button.disabled = !(postingAllowed || canMentionReport()) || button.closest('li').dataset.studioParticipant === 'Участник');
    messages.querySelectorAll('.venue-play-dialog__edit').forEach(button=>button.hidden=!postingAllowed);
    sync();
}
setPosting(postingAllowed);
if(pageMode || reportMode){
    pageRoot.addEventListener('studio:feedback-settings',()=>setPosting(mayPost()));
    pageRoot.addEventListener('studio:authenticated',()=>{viewerAuthenticated=true;setPosting(mayPost());});
}
if(reportMode){
    pageRoot.addEventListener('studio:report-mention',event=>insertMention(event.detail.name));
    let reaction=null, pendingReaction=null;
    // Count active likes, not historical "like" events. Other users' demo votes remain unchanged.
    const othersLikes=Number(pageRoot.dataset.reportInitialLikes)||0;
    const tally=pageRoot.querySelector('[data-report-like-count]');
    const controls=[...dialog.querySelectorAll('[data-report-reaction]')];
    function syncReaction(){
        const likes=othersLikes+Number(reaction==='like');
        if(tally){tally.textContent=String(likes);tally.setAttribute('aria-label','Лайков: '+likes);}
        controls.forEach(b=>{
            b.disabled=viewerAuthenticated&&!reportEligible;
            b.setAttribute('aria-pressed',String(b.dataset.reportReaction===reaction));
        });
    }
    function applyReaction(value){
        if(!mayReact()||!['like','dislike'].includes(value))return;
        reaction=reaction===value?null:value;
        syncReaction();
        append('Событие',reaction==='like'?'Пользователю '+reportViewer+' нравится мероприятие.':reaction==='dislike'?'Пользователю '+reportViewer+' не нравится мероприятие.':'Пользователь '+reportViewer+' убрал оценку.',reaction);
    }
    controls.forEach(b=>b.addEventListener('click',()=>{
        const value=b.dataset.reportReaction;
        if(!viewerAuthenticated){pendingReaction=value;pageRoot.dispatchEvent(new CustomEvent('studio:report-auth'));return;}
        if(mayReact())applyReaction(value);
    }));
    pageRoot.addEventListener('studio:authenticated',()=>{syncReaction();if(pendingReaction){applyReaction(pendingReaction);pendingReaction=null;}});
    pageRoot.addEventListener('studio:auth-cancelled',()=>{pendingReaction=null;});
    syncReaction();
}
if(!reportMode)dialog.addEventListener('studio:participation-changed', event => {
    if (!event.detail.allowed && editing) stopEdit();
    isParticipant=event.detail.allowed;
    if (pageMode) {
        roster.querySelector('[data-studio-participant="superadmin"]')?.remove();
        if(event.detail.allowed)roster.append(rosterRow('superadmin',event.detail.status));
        updateRosterHeading();
    }
    setPosting(mayPost());
    if (event.detail.message) append('Событие',event.detail.message,event.detail.status);
});
bindChatActions(form);
field.addEventListener('input',sync);
form.addEventListener('submit',event=>{
    event.preventDefault();
    if (submit.disabled) return;
    if (editing) {
        editing.querySelector('p').textContent = field.value.trim();
        setStamp(editing, nextStamp(), true);
        regroup();
        messages.scrollTop = messages.scrollHeight;
    } else {
        append(reportMode ? reportViewer : 'superadmin',field.value.trim(),null,true);
    }
    stopEdit();
});
form.addEventListener('mskba:chat-action', event => {
    if (event.detail.id === 'cancel-edit') {
        stopEdit();
        field.focus();
    }
});
remove.addEventListener('click',()=>{
    if (!editing || !postingAllowed) return;
    deleteDialog.studioDelete = () => {
        if (editing && postingAllowed) {editing.remove();stopEdit();regroup();}
        deleteDialog.close();
        if(postingAllowed)field.focus();
    };
    deleteDialog.showModal();
});
if(!deleteDialog.dataset.studioBound){
    deleteDialog.dataset.studioBound='true';
    deleteDialog.querySelector('[data-venue-play-delete-close]').addEventListener('click',()=>deleteDialog.close());
    deleteDialog.querySelector('[data-venue-play-delete-confirm]').addEventListener('click',()=>deleteDialog.studioDelete?.());
    deleteDialog.addEventListener('close',()=>{deleteDialog.studioDelete=null;});
}
const attendance = dialog.querySelector('[data-venue-play-attendance]');
const tentative = dialog.querySelector('[data-venue-play-rsvp="tentative"]');
let rsvp = 'confirmed';
const syncRsvp = () => {
    attendance.textContent = rsvp === 'confirmed' ? 'Не пойду' : 'Пойду';
    tentative.classList.toggle('is-selected',rsvp === 'tentative');
};
attendance?.addEventListener('click',()=>{
    rsvp = rsvp === 'confirmed' ? 'left' : 'confirmed';
    append('Событие',rsvp === 'confirmed' ? 'superadmin пойдёт на мероприятие.' : 'superadmin не пойдёт на мероприятие.',rsvp);
    syncRsvp();
});
tentative?.addEventListener('click',()=>{rsvp='tentative';append('Событие','superadmin подумает об участии.','tentative');syncRsvp();});
if (attendance && tentative) syncRsvp();
if (modalMode) {
    dialog.querySelectorAll('[data-studio-close]').forEach(button=>button.addEventListener('click',()=>dialog.close()));
    document.querySelector('[data-studio-open]').addEventListener('click',()=>dialog.showModal());
    dialog.showModal();
}
sync();
messages.scrollTop = messages.scrollHeight;
if (state === 'editing' && own) startEdit(own);
</script>
