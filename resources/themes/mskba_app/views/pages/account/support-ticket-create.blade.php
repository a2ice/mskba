@extends('theme::layouts.account', ['title' => 'Сообщения — Новое обращение в техподдержку', 'compactSidebar' => true])

@section('account-content')
<div class="app-message-workspace app-message-workspace--support-create">
    @include('theme::pages.account.partials.conversation-rail')
<section class="panel app-messages__support-form" aria-label="Новое обращение в техподдержку">
    <h1>Написать в техподдержку MSKBA</h1>
    <p>Опишите вопрос или проблему. Ответ появится в разделе «Сообщения».</p>
    <form method="POST" action="{{ route('account.notifications.support.store') }}">
        @csrf
        <label for="support-subject">Тема обращения</label>
        <input id="support-subject" name="subject" maxlength="150" required value="{{ old('subject') }}" placeholder="Например, вопрос о подтверждении аккаунта">
        @error('subject')<p class="app-messages__read-error">{{ $message }}</p>@enderror
        <label for="support-body">Сообщение</label>
        <textarea id="support-body" name="body" maxlength="2000" rows="6" required placeholder="Опишите ваш вопрос">{{ old('body') }}</textarea>
        @error('body')<p class="app-messages__read-error">{{ $message }}</p>@enderror
        <div class="app-messages__compose-footer">
            <a class="button secondary" href="{{ route('account.notifications') }}">Отмена</a>
            <button class="button primary" type="submit">Отправить обращение</button>
        </div>
    </form>
    <p class="app-messages__support-email">Вы также можете написать нам на почту <a href="mailto:{{ config('support.email') }}">{{ config('support.email') }}</a></p>
</section>
</div>
@endsection
