@extends('theme::layouts.account', ['title' => 'Настройки'])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Настройки',
        'intro' => 'Параметры аккаунта, безопасности и приватности.',
    ])
@endsection

@section('account-content')
    @php
        $designThemes = app(\App\Presentation\Theming\DesignThemeManager::class)->options();
        $selectedAppearance = $user?->canonical()?->design_theme_id;
    @endphp
    @php
        $transferPrivacy = $user?->canonical()?->privacySettings()
            ->where('type', \App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum::WALLET_TRANSFER_REQUESTS->value)
            ->first()?->visibility;
        $requestsAllowed = $transferPrivacy === null || $transferPrivacy === \App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum::EVERYONE;
    @endphp
    @if(session('status')) <p class="app-player-workspace__notice" role="status">{{ session('status') }}</p> @endif
    <section class="panel app-balance-privacy" aria-labelledby="balance-privacy-title">
        <h2 id="balance-privacy-title">Запросы переводов</h2>
        <p>Управляйте тем, могут ли другие участники присылать вам запросы на перевод бонусов. Это не разрешение автоматически списывать баллы.</p>
        <form action="{{ route('account.settings.transfer-request-privacy.update') }}" method="POST">
            @csrf
            <label class="app-player-workspace__checkbox"><input type="checkbox" name="allow_requests" value="1" @checked($requestsAllowed)> Разрешить запрос на перевод баллов</label>
            <button class="button primary" type="submit">Сохранить</button>
        </form>
    </section>
    <section class="panel app-appearance-settings" aria-labelledby="homepage-preference-heading">
        <h2 id="homepage-preference-heading">Главная страница</h2>
        <p class="muted">Выбери, какой раздел открывать при переходе на главную, когда ты вошёл в аккаунт. Для гостей всегда показывается обычная главная.</p>
        <form action="{{ route('account.settings.homepage.update') }}" method="POST">
            @csrf
            @method('PATCH')
            <label for="homepage-preference">Главная страница после входа</label>
            <span class="select-control app-appearance-settings__select">
                <select id="homepage-preference" name="homepage_preference" required>
                    @foreach (\App\Modules\Identity\Application\Services\HomepagePreference::OPTIONS as $value => $label)
                        <option value="{{ $value }}" @selected(old('homepage_preference', $user ? app(\App\Modules\Identity\Application\Services\HomepagePreference::class)->selected($user) : 'account') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <svg aria-hidden="true" focusable="false"><use href="#chevron-down"/></svg>
            </span>
            @error('homepage_preference')
                <p class="error" role="alert">{{ $message }}</p>
            @enderror
            <button class="button primary" type="submit">Сохранить</button>
            <p class="muted">Обычную главную можно открыть в любое время: <a href="{{ route('homepage.original') }}">Посмотреть главную MSKBA</a>.</p>
        </form>
    </section>
    <section class="panel app-appearance-settings" aria-labelledby="appearance-heading">
        <h2 id="appearance-heading">Оформление сайта</h2>
        <p class="muted">Выбери тему для своего аккаунта или используй оформление, установленное для всех.</p>
        <form action="{{ route('account.settings.appearance.update') }}" method="POST"
              data-design-theme-picker>
            @csrf
            @method('PATCH')
            <label for="design-theme-id">Тема оформления</label>
            <span class="select-control app-appearance-settings__select">
                <select id="design-theme-id" name="design_theme_id" data-current="{{ $selectedAppearance }}">
                    <option value="" @selected($selectedAppearance === null)>Как установлено на сайте</option>
                    @foreach ($designThemes as $id => $label)
                        <option value="{{ $id }}" @selected((int) $selectedAppearance === (int) $id)>{{ $label }}</option>
                    @endforeach
                </select>
                <svg aria-hidden="true" focusable="false"><use href="#chevron-down"/></svg>
            </span>
            <button class="button primary" type="submit">Сохранить</button>
            <p role="status" aria-live="polite" data-theme-preference-status></p>
        </form>
    </section>
@endsection
