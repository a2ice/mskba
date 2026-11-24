@extends('theme::layouts.account', ['title' => 'Объединение аккаунтов'])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', ['heading' => 'Объединение аккаунтов', 'intro' => 'Выберите основной аккаунт MSKBA.'])
@endsection

@section('account-content')
    <dialog open class="mskba-modal app-contact-wizard__dialog" data-account-duplicate-dialog aria-labelledby="duplicate-title">
        <header class="mskba-modal__header mskba-auth-top app-contact-wizard__header">
            <h2 id="duplicate-title" class="mskba-auth-heading">Объединение аккаунтов</h2>
            <a href="{{ route('account.contacts') }}" class="icon-button mskba-auth-close" aria-label="Закрыть окно">
                <svg aria-hidden="true"><use href="#close"/></svg>
            </a>
        </header>
        <div class="mskba-modal__body mskba-scroll app-contact-wizard__body">
            <p>Этот Telegram уже связан с другим аккаунтом MSKBA. Если оба аккаунта ваши, выберите основной.</p>
            <p>Профиль и игровые характеристики будут взяты из основного аккаунта. Второй аккаунт не удаляется; его исторические связи сохраняются, но не все данные объединяются автоматически.</p>
            @if(session('error'))
                <p class="notice app-confirmation__notice--error" role="alert">{{ session('error') }}</p>
            @endif
            @if($errors->any())
                <p class="notice app-confirmation__notice--error" role="alert">{{ $errors->first() }}</p>
            @endif
            <form id="duplicate-merge-form" method="POST" action="{{ route('account.user-duplicates.merge', $duplicate) }}">
                @csrf
                <div class="app-contact-wizard__choices">
                    @foreach([$duplicate->user, $duplicate->duplicateUser] as $candidate)
                        @php($summary = $completeness[$candidate->id])
                        <label>
                            <input type="radio" name="canonical_user_id" value="{{ $candidate->id }}" required @checked((string) old('canonical_user_id') === (string) $candidate->id)>
                            <span>
                                <strong>{{ $candidate->getFilamentName() ?: ('Аккаунт #'.$candidate->id) }} · #{{ $candidate->id }}</strong>
                                <small>{{ $candidate->username }} @if($candidate->id === auth()->id()) · Текущий аккаунт @endif</small>
                                <small>Создан {{ $candidate->created_at?->format('d.m.Y') }} · {{ $candidate->telegramAccount ? 'Telegram привязан' : 'Telegram не привязан' }}</small>
                                <strong>Заполненность: {{ $summary['percent'] }}%</strong>
                                <progress max="100" value="{{ $summary['percent'] }}" aria-label="Заполненность аккаунта {{ $candidate->id }}">{{ $summary['percent'] }}%</progress>
                                <small>Виды подтверждённых контактов: {{ $summary['contacts'] }} · Роли: {{ $summary['roles'] }}</small>
                                <small>Мероприятия: {{ $summary['events'] }} · Команды: {{ $summary['teams'] }}</small>
                            </span>
                        </label>
                    @endforeach
                </div>
            </form>
            <p><small>Процент — ориентир по заполненным полям профиля, игровым характеристикам, контактам и наличию связей. Это не рейтинг и не обязательные требования.</small></p>
        </div>
        <footer class="mskba-modal__footer app-contact-wizard__footer">
            <a href="{{ route('account.contacts') }}" class="button secondary">Отмена</a>
            <button class="button primary" type="submit" form="duplicate-merge-form">Объединить аккаунты</button>
        </footer>
    </dialog>
@endsection
