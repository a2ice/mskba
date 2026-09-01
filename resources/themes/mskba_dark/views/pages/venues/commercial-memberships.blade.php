@php
    $title = 'Коммерческие роли · '.$venue->name;
    $venueSidebarActive = 'commercial-memberships';
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => $title,
    'sidebarLabel' => 'Управление площадкой',
])

@section('section-sidebar')
    @include('theme::partials.venues.internal-sidebar')
@endsection

@section('section-content')
        @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
        @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

        <div class="card mb-4"><div class="card-body">
            <h2 class="h4 mb-3">Добавить участника</h2>
            <form method="POST" action="{{ route('account.venues.commercial-memberships.store', $venue) }}">
                @csrf
                @include('theme::partials.forms.entity-predictive-search', [
                    'id' => 'venueCommercialUser', 'name' => 'user_id',
                    'label' => 'Подтверждённый пользователь',
                    'placeholder' => 'Начните вводить имя или логин…',
                    'searchUrl' => route('account.venues.commercial-memberships.candidates', $venue),
                ])
                <label class="form-label mt-3" for="venueCommercialRole">Роль</label>
                <select id="venueCommercialRole" class="form-select" name="role" required>
                    @foreach($roles as $role)<option value="{{ $role->value }}">{{ $role->label() }}</option>@endforeach
                </select>
                <p class="text-muted mt-3">Роль будет выдана со стандартным набором прав. Его можно уточнить после назначения.</p>
                <button type="submit" class="btn btn--primary btn--sm mt-3">Выдать роль</button>
            </form>
        </div></div>

        <h2 class="h4 mb-3">Участники договора</h2>
        @foreach($memberships as $membership)
            @php
                $role = \App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum::from($membership->access_level);
                $isOwner = $role === \App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum::OWNER;
            @endphp
            <article class="card mb-3"><div class="card-body">
                <strong>{{ trim(($membership->user->profile?->first_name ?? '').' '.($membership->user->profile?->last_name ?? '')) ?: $membership->user->username }}</strong>
                <p>{{ $role->label() }}</p>
                <p class="text-muted">{{ $membership->contract->permissions->map(fn($item) => \App\Modules\Venue\Domain\Enums\VenuePermissionEnum::tryFrom($item->permission)?->label())->filter()->join(', ') }}</p>
                @unless($isOwner)
                    <details class="mt-3"><summary>Изменить роль и права</summary>
                        <form method="POST" action="{{ route('account.venues.commercial-memberships.update', [$venue, $membership]) }}" class="mt-3">
                            @csrf @method('PUT')
                            <input type="hidden" name="role" value="{{ $role->value }}">
                            @foreach($role->allowedPermissions() as $permission)
                                <label class="d-block mb-2"><input type="checkbox" name="permissions[]" value="{{ $permission->value }}" @checked($membership->contract->permissions->contains('permission', $permission->value))> {{ $permission->label() }}</label>
                            @endforeach
                            <button type="submit" class="btn btn--secondary btn--sm mt-2">Сохранить</button>
                        </form>
                    </details>
                    <form method="POST" action="{{ route('account.venues.commercial-memberships.destroy', [$venue, $membership]) }}" class="mt-3" onsubmit="return confirm('Отозвать коммерческую роль? Доступ прекратится сразу.')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn--danger btn--sm">Отозвать роль</button>
                    </form>
                @endunless
            </div></article>
        @endforeach
@endsection
