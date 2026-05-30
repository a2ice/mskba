@php $contracts = isset($contracts) ? $contracts : null; @endphp

@extends('theme::layouts.account', ['title' => 'Мои контракты'])

@section('account-content')
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
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Номер</th>
                            <th>Статус</th>
                            <th>Начало</th>
                            <th>Окончание</th>
                            <th>Площадки</th>
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
                                <td>{{ $contract->startsAt ?? '—' }}</td>
                                <td>{{ $contract->expiresAt ?? '—' }}</td>
                                <td>
                                    @forelse ($contract->venues as $venue)
                                        <div>
                                            <a href="{{ route('venues.show', $venue['alias']) }}">{{ $venue['name'] }}</a>
                                            <code>{{ $venue['permissions'] }}</code>
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
