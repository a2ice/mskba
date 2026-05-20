@extends('theme::layouts.admin', ['title' => 'Админка - Пользователи'])

@section('admin_content')
<div class="content-header">
    <h3 class="content-header__title content-title">Пользователи</h3>
</div>
<div class="content-body">
    <div class="overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full table">
                <thead>
                    <tr>
                        <th>№</th>
                        <th>ID</th>
                        <th>Логин</th>
                        <th>Статус</th>
                        <th>Email</th>
                        <th>Системная роль</th>
                        <th>Роли в проекте</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($users as $user)
                        @php
                            $email = $user->contacts->where('contact_type', 'email')->first()?->value;
                            $participationRoles = $user->participationRoles->pluck('name')->join(', ');
                            $userStatus = $user->status ? ($user->status->value === 'confirmed' ? 'success' : 'danger') : 'default';
                            $userStatusLabel = $user->status?->label() ?? '-';
                            $myself = auth()->id() === $user->id;
                        @endphp
                        <tr @class([
                            'table-row--highlighted' => $myself,
                        ])>
                            <td>{{ $loop->iteration }}</td>
                            <td>{{ $user->id }}</td>
                            <td>
                                <a href="{{ route('admin.users.user', ['id' => $user->id]) }}">{{ $user->login }}</a>
                            </td>
                            <td>
                                <span class="badge badge--{{ $userStatus }}">
                                    {{ $userStatusLabel }}
                                </span>
                            </td>
                            <td>{{ $email ?? '-' }}</td>
                            <td>
                                <span class="badge badge--primary">
                                    {{ $user->system_role?->label() ?? '-' }}
                                </span>
                            </td>
                            <td>{{ $participationRoles ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td>Пользователи не найдены</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
