@extends('theme::layouts.account', ['title' => 'Кошелёк'])
@php
    $money = static fn (int $minor): string => number_format($minor / 100, 2, ',', ' ').' ₽';
@endphp

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Кошелёк',
        'intro' => 'Баланс, переводы и история операций.',
    ])
@endsection

@section('account-content')
    @if(session('status')) <p class="app-player-workspace__notice" role="status">{{ session('status') }}</p> @endif
    @if($errors->any()) <p class="app-player-workspace__error" role="alert">{{ $errors->first() }}</p> @endif
    <div class="app-wallet">
        <section class="panel app-wallet__hero" aria-label="Доступный баланс">
            <span>Доступно для оплаты сервисов MSKBA</span>
            <strong>{{ $money($totalBalanceMinor) }}</strong>
            <div class="app-wallet__balances">
                <p>Бонусы <strong>{{ $money($bonusBalanceMinor) }}</strong></p>
                <p>Основные средства <strong>{{ $money($realBalanceMinor) }}</strong></p>
            </div>
        </section>
        <section class="panel app-wallet__transfer">
            <h2>Перевести бонусы</h2>
            <p>Здесь можно перевести бонусные средства другому пользователю. Основной баланс и вывод не подключены.</p>
            <form method="POST" action="{{ route('account.wallet.transfers.store') }}" data-wallet-transfer-form data-search-url="{{ route('account.wallet.transfer-recipients') }}">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <label>Получатель
                    <input type="search" autocomplete="off" placeholder="Найти по имени или логину" data-wallet-recipient-query>
                </label>
                <div class="app-player-workspace__transfer-results" data-wallet-recipient-results role="listbox" hidden></div>
                <input type="hidden" name="recipient_user_id" data-wallet-recipient-id required>
                <label>Сумма, ₽ <input type="text" name="amount" inputmode="decimal" placeholder="100,00" required></label>
                <button class="button primary" type="submit" data-wallet-transfer-submit disabled>Перевести бонусы</button>
                <p role="status" data-wallet-transfer-status></p>
            </form>
        </section>
        <section class="panel app-wallet__history">
            <h2>История операций</h2>
            @if($operations === [])
                <p>Операций пока нет.</p>
            @else
                <div class="app-wallet__table-wrap">
                    <table>
                        <thead><tr><th>Дата</th><th>Операция</th><th>Основные</th><th>Бонусы</th></tr></thead>
                        <tbody>
                        @foreach($operations as $operation)
                            <tr>
                                <td>{{ $operation['completedAt']?->format('d.m.Y H:i') ?? '—' }}</td>
                                <td>{{ $operation['label'] }} @if($operation['description']) <small>{{ $operation['description'] }}</small> @endif</td>
                                <td>{{ $money($operation['realDeltaMinor']) }}</td>
                                <td>{{ $money($operation['bonusDeltaMinor']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
