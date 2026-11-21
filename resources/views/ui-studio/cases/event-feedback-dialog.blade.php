<dialog class="mskba-modal studio-event-dialog studio-feedback-dialog" id="studio-feedback-settings" aria-labelledby="studio-feedback-settings-title">
    <header class="mskba-modal__header"><h2 id="studio-feedback-settings-title">Редактирование мероприятия</h2><button type="button" class="icon-button" data-event-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12M6 6l12 12"/></svg></button></header>
    <div class="mskba-modal__body mskba-scroll">
        @include('ui-studio.cases.event-feedback-fields')
    </div>
    <footer class="mskba-modal__footer"><button type="button" class="button secondary" data-event-close>Отмена</button><button type="button" class="button primary" data-feedback-save>Сохранить</button></footer>
</dialog>
<script type="module">
const root=document.querySelector('.studio-event-page'), dialog=document.getElementById('studio-feedback-settings');
const discussion=dialog.querySelector('[data-feedback-discussion]'), comments=dialog.querySelector('[data-feedback-comments]');
document.querySelector('[data-event-open="studio-feedback-settings"]').addEventListener('click',()=>{discussion.checked=root.dataset.discussionEnabled==='true';comments.checked=root.dataset.reportCommentsEnabled==='true';});
dialog.querySelector('[data-feedback-save]').addEventListener('click',()=>{root.dataset.discussionEnabled=String(discussion.checked);root.dataset.reportCommentsEnabled=String(comments.checked);root.dispatchEvent(new CustomEvent('studio:feedback-settings'));dialog.close();});
</script>