@php($studioParticipationUrl = route('ui-studio.show', ['ui_name'=>'event-detail','theme'=>$theme,'width'=>$width,'state'=>'guest'], false))
<script type="module">
{!! file_get_contents(resource_path('js/chat-composer-actions.js')) !!}
const dialog = document.querySelector('[data-studio-chat]');
const form = dialog.querySelector('[data-chat-composer]');
const field = form.querySelector('textarea');
const submit = form.querySelector('[type=submit]');
const counter = form.querySelector('[data-venue-play-chars]');
const cancel = form.querySelector('[data-venue-play-edit-cancel]');
const remove = form.querySelector('[data-chat-context-delete]');
const messages = dialog.querySelector('[data-venue-play-messages]');
const empty = dialog.querySelector('[data-venue-play-wall-empty]');
const roster = dialog.querySelector('[data-venue-play-participants]');
const deleteDialog = document.querySelector('[data-venue-play-delete-dialog]');
const pageMode = !dialog.matches('dialog');
const state = @json($state);
const eventFinished = pageMode && ['report','report-empty','report-owner'].includes(state);
let postingAllowed = pageMode ? ['confirmed','tentative','editing','report-owner'].includes(state) : state !== 'disabled';
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
const rsvpSection = dialog.querySelector('.venue-play-dialog__rsvp');
if (rosterSection && rsvpSection) rosterSection.before(rsvpSection);

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
    if (rsvp) item.dataset.rsvpStatus = rsvp;
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
function rosterRow(name, status = 'confirmed') {
    const li = document.createElement('li');
    const participant = document.createElement('button');
    participant.type = 'button';
    participant.className = 'venue-play-dialog__participant';
    participant.disabled = !postingAllowed || name === 'Участник';
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
        if (form.dataset.chatCanPost !== 'true') return;
        const prefix = '@' + name + ' ';
        if (!field.value.startsWith(prefix) && field.value.length + prefix.length <= field.maxLength) {
            field.setRangeText(prefix, 0, 0, 'end');
        }
        sync();
        field.focus({preventScroll:true});
        field.setSelectionRange(field.value.length, field.value.length);
        scrollToMessagesEnd();
    });
    li.append(participant);
    return li;
}
const updateRosterHeading = () => {
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
for (const name of (pageMode ? ['olsen','Участник'] : ['olsen','superadmin'])) roster.append(rosterRow(name));
if(pageMode && postingAllowed)roster.append(rosterRow('superadmin',state === 'tentative' ? 'tentative' : 'confirmed'));
updateRosterHeading();
dialog.querySelector('[data-venue-play-roster-empty]').hidden = true;
empty.textContent = 'Сообщений пока нет';
if (state !== 'empty') {
    append('Событие','olsen пойдёт на мероприятие.','confirmed',false,'2026-11-19T18:20:00+03:00');
    append('Событие','olsen не пойдёт на мероприятие.','left',false,'2026-11-19T19:05:00+03:00');
    append('Событие','superadmin пойдёт на мероприятие.','confirmed',false,'2026-11-21T16:35:00+03:00');
}
if (state === 'editing' || pageMode && state === 'confirmed') own = append('superadmin','Проверка сообщения.',null,true,'2026-11-21T16:37:00+03:00');
const commentNotice = dialog.querySelector('[data-venue-play-comment-notice]');
const participationLink = document.createElement('a');
participationLink.href = pageMode ? '#studio-event-title' : @json($studioParticipationUrl);
if (!pageMode) participationLink.target = '_top';
participationLink.className = 'venue-play-dialog__participation-link';
participationLink.textContent = 'Участвовать';
if (pageMode) participationLink.addEventListener('click', event => {
    event.preventDefault();dialog.dispatchEvent(new CustomEvent('studio:participate'));
});
if (eventFinished) commentNotice.textContent = 'Написать сообщение могут участники мероприятия.';
else commentNotice.replaceChildren('Написать сообщение могут участники мероприятия. ', participationLink);
function setPosting(allowed) {
    postingAllowed = !!allowed;
    setChatComposerAccess(form, postingAllowed);
    commentNotice.hidden = postingAllowed;
    roster.querySelectorAll('button').forEach(button => button.disabled = !postingAllowed || button.closest('li').dataset.studioParticipant === 'Участник');
    sync();
}
setPosting(postingAllowed);
dialog.addEventListener('studio:participation-changed', event => {
    if (!event.detail.allowed && editing) stopEdit();
    if (pageMode) {
        roster.querySelector('[data-studio-participant="superadmin"]')?.remove();
        if(event.detail.allowed)roster.append(rosterRow('superadmin',event.detail.status));
        updateRosterHeading();
    }
    setPosting(event.detail.allowed);
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
        append('superadmin',field.value.trim(),null,true);
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
    if (editing) deleteDialog.showModal();
});
deleteDialog.querySelector('[data-venue-play-delete-close]').addEventListener('click',()=>deleteDialog.close());
deleteDialog.querySelector('[data-venue-play-delete-confirm]').addEventListener('click',()=>{
    if (editing) editing.remove();
    stopEdit();
    regroup();
    deleteDialog.close();
    field.focus();
});
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
if (!pageMode) {
    dialog.querySelectorAll('[data-studio-close]').forEach(button=>button.addEventListener('click',()=>dialog.close()));
    document.querySelector('[data-studio-open]').addEventListener('click',()=>dialog.showModal());
    dialog.showModal();
}
sync();
messages.scrollTop = messages.scrollHeight;
if (state === 'editing' && own) startEdit(own);
</script>
