@extends('theme::layouts.account', ['title' => 'Сообщения — Приглашение на игру', 'compactSidebar' => true])

@section('account-content')
@php($game = $context->game)
<div class="app-message-workspace">
    @include('theme::pages.account.partials.conversation-rail')
    <section class="app-messages app-messages--thread" aria-label="Приглашение на игру" data-game-chat-root
        data-chat-url="{{ route('account.notifications.threads.game-invitation', $context) }}"
        data-chat-channel="game-invitation-threads.{{ $context->id }}"
        data-chat-read-url="{{ route('account.notifications.threads.game-invitation.read', $context) }}">
        <header class="panel app-messages__thread-header">
            <a class="app-messages__back" href="{{ route('account.notifications') }}" aria-label="Все сообщения"><svg aria-hidden="true"><use href="#arrow"/></svg></a>
            <span class="app-messages__avatar" aria-hidden="true"><svg><use href="#message"/></svg></span>
            <div class="app-messages__thread-contact">
                <h2>Приглашение на игру</h2>
                <p>{{ ($context->team?->name ?? 'Команда') }} · {{ $game?->title ?: 'Игра' }}</p>
            </div>
        </header>
        <div data-game-chat-content style="display:contents">
            @include('theme::pages.account.partials.game-invitation-content')
        </div>
        @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
        <p data-game-chat-error role="alert" hidden></p>
        <form class="panel app-messages__compose" data-game-chat-compose method="POST" action="{{ route('account.notifications.threads.game-invitation.messages', $context) }}">
            @csrf
            <label class="sr-only" for="game-invitation-message">Сообщение</label>
            <textarea id="game-invitation-message" name="body" rows="3" maxlength="2000" required placeholder="Введите сообщение…">{{ old('body') }}</textarea>
            @foreach($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
            <div class="app-messages__compose-footer"><button class="button primary" type="submit">Отправить</button></div>
        </form>
    </section>
</div>
<style>
.event-teammates-dialog>form{display:flex;flex-direction:column;min-height:0;overflow:hidden}
.event-teammates-dialog .mskba-modal__body{display:grid;gap:12px;padding:20px;overflow:auto}
.event-teammates-dialog .mskba-modal__header,.event-teammates-dialog .mskba-modal__footer{padding:16px 20px}
.event-teammates-dialog .mskba-modal__footer{display:flex;justify-content:flex-end;gap:8px}
.event-teammates-dialog .check-label{display:flex;align-items:center;gap:8px}
.event-teammates-dialog .check-label input{width:20px;height:20px;flex:none;margin:0}
</style>

@endsection
