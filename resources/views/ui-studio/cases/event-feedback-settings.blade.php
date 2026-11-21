@include('ui-studio.cases.event-detail-candidate')
<main class="container" style="padding-block:24px">
    <section class="panel app-venue-block" style="max-width:660px;margin-inline:auto;align-items:stretch">
        <header class="app-venue-block__header"><h2>{{ $state === 'editing' ? 'Редактирование мероприятия' : 'Создание мероприятия' }}</h2></header>
        <label class="field-group">Название мероприятия<input type="text" value="{{ $state === 'editing' ? 'Игра на м. Яхромская' : '' }}"></label>
        <h3>Обсуждение и комментарии</h3>
        @include('ui-studio.cases.event-feedback-fields')
        <button type="button" class="button primary" data-studio-save>{{ $state === 'editing' ? 'Сохранить' : 'Создать мероприятие' }}</button>
    </section>
</main>
<script>
document.querySelector('[data-studio-save]').addEventListener('click',e=>{e.currentTarget.textContent='Сохранено в демонстрации';});
</script>