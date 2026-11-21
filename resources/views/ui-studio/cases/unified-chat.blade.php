{{-- Preview fixture: use the actual production discussion, composer and modal classes. No application data or endpoints. --}}
@php
    $isAppTheme = true;
    $primaryButton = 'button primary';
    $secondaryButton = 'button secondary';
    $chatEvent = (object) ['id' => 'studio'];
@endphp
@include('components.event-discussion-styles')
@include('ui-studio.cases.unified-chat-candidate')
<main class="container" style="padding-block:32px">
    <button type="button" class="button secondary" data-studio-open>Мероприятие</button>
</main>
<dialog class="mskba-modal venue-play-dialog venue-play-dialog--app" data-studio-chat aria-labelledby="studio-chat-title">
    <header class="mskba-modal__header venue-play-dialog__header">
        <h2 id="studio-chat-title">Мероприятие</h2>
        <button type="button" class="icon-button venue-play-dialog__close" data-studio-close aria-label="Закрыть" autofocus><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12M6 6l12 12"/></svg></button>
    </header>
    <div class="mskba-modal__body venue-play-dialog__body mskba-scroll">
        <article class="venue-play-dialog__detail">
            <div class="venue-play-dialog__summary">
                <h3>Игра на м. Яхромская</h3>
                <p class="venue-play-dialog__event-meta">Игра · 22.11.2026 12:00 · время предварительное</p>
                <p><strong>Описание:</strong> Хуперы любого уровня, главное желание</p>
            </div>
            @include('components.event-discussion', ['chatCanRespond' => true])
        </article>
    </div>
    <footer class="mskba-modal__footer venue-play-dialog__footer" data-venue-play-detail-footer>
        <button class="button secondary" type="button" data-studio-close>Назад</button>
        <button class="button primary" type="button" data-studio-close>К мероприятию</button>
    </footer>
</dialog>
@include('components.event-discussion-delete-dialog')
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
const state = @json($state);
let editing = null;
let own = null;
const append = (author, body, rsvp = null, isOwn = false) => {
    const item = document.createElement('li');
    if (rsvp) item.dataset.rsvpStatus = rsvp;
    const title = document.createElement('strong');
    title.textContent = author;
    const time = document.createElement('time');
    time.textContent = '21.11.2026 16:35';
    const p = document.createElement('p');
    p.textContent = body;
    item.append(title, time, p);
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
    empty.hidden = true;
    messages.scrollTop = messages.scrollHeight;
    return item;
};
const sync = () => {
    counter.textContent = 200 - field.value.length;
    submit.disabled = state === 'disabled' || !field.value.trim();
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
    if (state === 'disabled') return;
    editing = item;
    field.value = item.querySelector('p').textContent;
    cancel.hidden = false;
    remove.hidden = false;
    form.classList.add('is-editing');
    sync();
    field.focus();
}
for (const name of ['olsen','superadmin']) {
    const li = document.createElement('li');
    li.className = 'venue-play-dialog__participant';
    li.innerHTML = '<span class="venue-play-dialog__avatar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8M5 21v-2a7 7 0 0 1 14 0v2"/></svg></span><span class="venue-play-dialog__participant-status is-confirmed"></span><span class="venue-play-dialog__participant-name"></span>';
    li.querySelector('.venue-play-dialog__participant-name').textContent = name;
    roster.append(li);
}
dialog.querySelector('[data-venue-play-roster-heading]').textContent = 'Участники (2/20)';
dialog.querySelector('[data-venue-play-roster-empty]').hidden = true;
empty.textContent = 'Сообщений пока нет';
if (state !== 'empty') {
    append('Событие','olsen'+' пойдёт на мероприятие.','confirmed');
    append('Событие','olsen'+' не пойдёт на мероприятие.','left');
    append('Событие','superadmin пойдёт на мероприятие.','confirmed');
}
if (state === 'editing') own = append('superadmin','Проверка сообщения.',null,true);
setChatComposerAccess(form, state !== 'disabled');
dialog.querySelector('[data-venue-play-comment-notice]').hidden = state !== 'disabled';
bindChatActions(form);
field.addEventListener('input',sync);
form.addEventListener('submit',event=>{
    event.preventDefault();
    if (submit.disabled) return;
    if (editing) {
        editing.querySelector('p').textContent = field.value.trim();
        editing.querySelector('time').textContent = '21.11.2026 16:39 (изменено)';
    } else {
        append('superadmin',field.value.trim(),null,true);
    }
    stopEdit();
});
cancel.addEventListener('click',stopEdit);
remove.addEventListener('click',()=>{
    if (editing) deleteDialog.showModal();
});
deleteDialog.querySelector('[data-venue-play-delete-close]').addEventListener('click',()=>deleteDialog.close());
deleteDialog.querySelector('[data-venue-play-delete-confirm]').addEventListener('click',()=>{
    if (editing) editing.remove();
    stopEdit();
    empty.hidden = messages.children.length !== 0;
    deleteDialog.close();
});
const attendance = dialog.querySelector('[data-venue-play-attendance]');
const tentative = dialog.querySelector('[data-venue-play-rsvp="tentative"]');
let rsvp = 'confirmed';
const syncRsvp = () => {
    attendance.textContent = rsvp === 'confirmed' ? 'Не пойду' : 'Пойду';
    tentative.classList.toggle('is-selected',rsvp === 'tentative');
};
attendance.addEventListener('click',()=>{
    rsvp = rsvp === 'confirmed' ? 'left' : 'confirmed';
    append('Событие',rsvp === 'confirmed' ? 'superadmin пойдёт на мероприятие.' : 'superadmin не пойдёт на мероприятие.',rsvp);
    syncRsvp();
});
tentative.addEventListener('click',()=>{rsvp='tentative';append('Событие','superadmin подумает об участии.','tentative');syncRsvp();});
syncRsvp();
dialog.querySelectorAll('[data-studio-close]').forEach(button=>button.addEventListener('click',()=>dialog.close()));
document.querySelector('[data-studio-open]').addEventListener('click',()=>dialog.showModal());
sync();
dialog.showModal();
messages.scrollTop = messages.scrollHeight;
if (own) startEdit(own);
</script>
