@php 
    $contract = isset($contract) ? $contract : null;
    $title = $contract && $contract->type ? $contract->type : '-';
    
@endphp

@extends('theme::layouts.account', [
    'title' => 'Контракт: ' . $title,
])

@section('account-content')
    @if(isset($error))
        <div class="alert alert-danger">
            {{ $error['message'] }}
        </div>
    @endif

    @if($contract !== null)
        <ul class="list-unstyled mb-4">
            <li class="mb-3">
                Номер:
                <span class="fw-bold">{{ $contract->number }}</span>
            </li>
        <ul class="list-unstyled mb-4">
            <li class="mb-3">
                Статус:
                <span class="fw-bold">{{ $contract->status }}</span>
            </li>
            <li class="mb-3">
                Уровень доступа:
                <span class="fw-bold">{{ $contract->accessLevelLabel ?? '—' }}</span>
                @if($contract->accessLevel)
                    <code>{{ $contract->accessLevel }}</code>
                @endif
            </li>
            <li class="mb-3">
                Название:
                <span class="fw-bold">{{ $contract->name ?? '—' }}</span>
            </li>
            <li class="mb-3">
                Область:
                @forelse($contract->scopes as $scope)
                    <span class="fw-bold">{{ $scope['type_label'] }}</span>
                    @if($scope['url'])
                        <a href="{{ $scope['url'] }}">{{ $scope['name'] }}</a>
                    @else
                        <span>{{ $scope['name'] }}</span>
                    @endif
                    <code>{{ $scope['type'] }}:{{ $scope['id'] }}</code>
                @empty
                    <span class="fw-bold">—</span>
                @endforelse
            </li>
            <li class="mb-3">
                Права:
                <span class="fw-bold">{{ $contract->permissions ?? '—' }}</span>
            </li>
            <li class="mb-3">
                Назначен:
                <span class="fw-bold">{{ $contract->assignedByUser?->name ?? $contract->assignedBy ?? '—' }}</span>
            </li>
            <li class="mb-3">
                Действует с:
                <span class="fw-bold">{{ $contract->startsAt ?? '—' }}</span>
            </li>
            <li class="mb-3">
                Действует до:
                <span class="fw-bold">{{ $contract->expiresAt ?? '—' }}</span>
            </li>
        </ul>

        <div class="d-flex gap-2">
            <a href="{{ route('account.contracts') }}" class="btn btn-outline-secondary">К списку</a>
        </div>
    @endif
@endsection
