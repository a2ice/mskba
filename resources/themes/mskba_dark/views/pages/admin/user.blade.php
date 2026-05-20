@extends('theme::layouts.admin', ['title' => 'Админка - Пользователи'])

@section('admin_content')

<div class="content-header">
    <h3 class="content-header__title content-title">Пользователь</h3>
</div>

<div class="content-body">
    <div class="overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full table">
                @if($user)
                <tbody>
                    <tr>
                        <th>ID</th>
                        <td>{{ $user->id }}</td>
                    </tr>
                    <tr>
                        <th>Логин</th>
                        <td>{{ $user->login }}</td>
                    </tr>
                    <tr>
                        <th>Email</th>
                        <td>{{ $user->contacts->where('contact_type', 'email')->first()?->value ?? '-' }}</td>
                    </tr>
                    <tr>
                        <th>Системная роль</th>
                        <td>{{ $user->system_role?->label() ?? '-' }}</td>
                    </tr>
                    <tr>
                        <th>Роли в проекте</th>
                        <td>{{ $user->participationRoles->pluck('name')->join(', ') ?: '-' }}</td>
                    </tr>
                </tbody>
                @else
                <tbody>
                    <tr>
                        <td>Пользователь не найден</td>
                    </tr>
                </tbody>
                @endif
            </table>
        </div>
    </div>
</div>
@endsection