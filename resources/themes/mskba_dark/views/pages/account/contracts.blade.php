@php $contracts = isset($contracts) ? $contracts : null; @endphp

@php $title = 'Мои контракты'; @endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => $title,
    'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false,
    'sidebarPartial' => 'theme::partials.account.sidebar',
])

@section('section-content')
    @if(isset($error))
        <div class="alert alert-danger">
            {{ $error['message'] }}
        </div>
    @endif

    @if($contracts !== null)
        @if($contracts === [])
            <div class="alert alert-info">
                Контракты пока не назначены.
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-striped table-dark align-middle fs-smaller">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Номер</th>
                            <th>Статус</th>
                            <th>Контракт</th>
                            <th>Начало</th>
                            <th>Окончание</th>
                            <th>Область</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($contracts as $contract)
                            <tr>
                                <td>{{ $contract->id }}</td>
                                <td>
                                    <a href="{{ route('account.contracts.show', $contract->id) }}">{{ $contract->number ?? '—' }}</a>
                                </td>
                                <td>{{ $contract->status }}</td>
                                <td>
                                    {{ $contract->accessLevelLabel ?? '—' }}
                                    @if($contract->accessLevel)
                                        <br><code>{{ $contract->accessLevel }}</code>
                                    @endif
                                </td>
                                <td>{{ $contract->startsAt ?? '—' }}</td>
                                <td>{{ $contract->expiresAt ?? '—' }}</td>
                                <td>
                                    @forelse ($contract->scopes as $scope)
                                        <div>
                                            <span class="text-muted">{{ $scope['type_label'] }}</span><br>
                                            @if($scope['url'])
                                                <a href="{{ $scope['url'] }}">{{ $scope['name'] }}</a>
                                            @else
                                                {{ $scope['name'] }}
                                            @endif
                                            <br><code>{{ $scope['permissions'] }}</code>
                                        </div>
                                    @empty
                                        —
                                    @endforelse
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
@endsection
