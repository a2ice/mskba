@php $title = 'Роли в проекте'; @endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => $title,
    'contentSubtitle' => 'Выберите, в каком качестве вы участвуете в жизни баскетбольного сообщества.',
    'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false,
    'sidebarPartial' => 'theme::partials.account.sidebar',
])

@section('section-content')
    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('account.roles.update') }}" class="account-roles-form">
        @csrf
        @method('PATCH')

        <div class="account-role-grid">
            @foreach($roles as $role)
                @php
                    $isActive = in_array($role->value, old('roles')
                        ? array_keys(array_filter(old('roles')))
                        : $activeRoleValues, true);
                @endphp

                <article class="account-role-card">
                    @include('theme::partials.forms.toggle', [
                        'id' => 'participation-role-'.$role->value,
                        'name' => 'roles['.$role->value.']',
                        'checked' => $isActive,
                        'title' => $role->label(),
                        'description' => $role->description(),
                        'wrapperClass' => 'account-role-card__toggle',
                    ])

                    @if($isActive && !old('roles'))
                        <a
                            href="{{ route('account.participation-role', ['role' => $role->value]) }}"
                            class="fc-link account-role-card__details"
                        >Подробнее о роли</a>
                    @endif
                </article>
            @endforeach
        </div>

        @error('roles')
            <div class="invalid-feedback d-block mt-3">{{ $message }}</div>
        @enderror
        @error('roles.*')
            <div class="invalid-feedback d-block mt-3">{{ $message }}</div>
        @enderror

        <p class="text-muted account-roles-form__hint">
            Можно выбрать несколько ролей или временно отключить все. Изменения не затрагивают системные права доступа.
        </p>

        <button type="submit" class="btn btn--primary btn--sm">Сохранить роли</button>
    </form>
@endsection
