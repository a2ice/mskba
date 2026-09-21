@php
    $title = 'Кошелёк';

    $formatMoney = static function (int $minor, string $currency = 'RUB', bool $signed = false): string {
        $sign = '';
        if ($signed && $minor !== 0) {
            $sign = $minor > 0 ? '+' : '−';
        } elseif ($minor < 0) {
            $sign = '−';
        }

        $suffix = strtoupper($currency) === 'RUB' ? '₽' : strtoupper($currency);

        return $sign.number_format(abs($minor) / 100, 2, ',', ' ').' '.$suffix;
    };
@endphp

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
    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="account-wallet">
        <section class="account-wallet__hero" aria-labelledby="account-wallet-total-title">
            <span class="account-wallet__eyebrow" id="account-wallet-total-title">Доступно</span>
            <strong class="account-wallet__total">{{ $formatMoney($totalBalanceMinor, $currency) }}</strong>
            <p>Общий баланс — это основные средства и бонусы, доступные для внутренних сервисов MSKBA.</p>
        </section>

        <div class="account-wallet__balances" aria-label="Состав баланса">
            <section class="account-wallet__balance-card">
                <span>Основной баланс</span>
                <strong>{{ $formatMoney($realBalanceMinor, $currency) }}</strong>
                <p>Средства из подтверждённых денежных пополнений.</p>
            </section>

            <section class="account-wallet__balance-card">
                <span>Бонусный баланс</span>
                <strong>{{ $formatMoney($bonusBalanceMinor, $currency) }}</strong>
                <p>Промо, реферальные и другие бонусные начисления.</p>
            </section>
        </div>

        <section class="account-wallet__transfer" aria-labelledby="account-wallet-transfer-title">
            <div>
                <h2 class="h3 mb-1" id="account-wallet-transfer-title">Перевести бонусы</h2>
                <p>Сейчас между пользовательскими кошельками переводится только бонусный баланс. Основной баланс остаётся недоступным для переводов.</p>
            </div>

            <form method="POST" action="{{ route('account.wallet.transfers.store') }}" class="account-wallet__transfer-form">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">

                <label>
                    <span>Получатель</span>
                    <input
                        type="text"
                        name="recipient"
                        class="form-control"
                        value="{{ old('recipient') }}"
                        placeholder="@olsen"
                        maxlength="64"
                        autocomplete="off"
                        required
                    >
                </label>

                <label>
                    <span>Сумма, ₽</span>
                    <input
                        type="text"
                        name="amount"
                        class="form-control"
                        value="{{ old('amount') }}"
                        placeholder="500"
                        inputmode="decimal"
                        autocomplete="off"
                        required
                    >
                </label>

                <button type="submit" class="btn btn--primary btn--sm" @disabled($bonusBalanceMinor <= 0)>
                    Перевести
                </button>
            </form>

            <p class="account-wallet__transfer-hint">
                Доступно для перевода: <strong>{{ $formatMoney($bonusBalanceMinor, $currency) }}</strong>. Получатель увидит уведомление и toast о зачислении.
            </p>
        </section>

        @if($bootstrapBonusAvailable)
            <section class="account-wallet__bootstrap" aria-labelledby="account-wallet-bootstrap-title">
                <div>
                    <h2 class="h3 mb-1" id="account-wallet-bootstrap-title">Тестовый бонус superadmin</h2>
                    <p>Одноразовое начисление для проверки цепочки переводов между аккаунтами.</p>
                </div>
                <form method="POST" action="{{ route('account.wallet.bootstrap-bonus.store') }}">
                    @csrf
                    <button type="submit" class="btn btn--secondary btn--sm">Начислить 10 000 ₽ бонусами</button>
                </form>
            </section>
        @endif

        <p class="account-wallet__notice">
            Пополнение реальными деньгами, вывод и перевод основного баланса пока не подключены.
        </p>

        <section class="account-wallet__history" aria-labelledby="account-wallet-history-title">
            <div class="account-wallet__history-head">
                <h2 class="h3" id="account-wallet-history-title">История операций</h2>
                <p class="small">Последние {{ count($operations) }} операций</p>
            </div>

            @if($operations === [])
                <div class="account-wallet__empty">
                    Операций пока нет.
                </div>
            @else
                <div class="account-wallet__history-table-wrap">
                    <table class="account-wallet__history-table">
                        <thead>
                            <tr>
                                <th scope="col">Дата</th>
                                <th scope="col">Операция</th>
                                <th scope="col">Основные</th>
                                <th scope="col">Бонусы</th>
                                <th scope="col">Итого</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($operations as $operation)
                                @php
                                    $totalClass = $operation['totalDeltaMinor'] > 0
                                        ? 'account-wallet__amount--positive'
                                        : ($operation['totalDeltaMinor'] < 0 ? 'account-wallet__amount--negative' : '');
                                @endphp
                                <tr>
                                    <td>{{ $operation['completedAt']?->format('d.m.Y H:i') ?? '—' }}</td>
                                    <td>
                                        <strong>{{ $operation['label'] }}</strong>
                                        @if($operation['description'])
                                            <small class="account-wallet__operation-description">{{ $operation['description'] }}</small>
                                        @endif
                                    </td>
                                    <td>
                                        {{ $operation['realDeltaMinor'] === 0 ? '—' : $formatMoney($operation['realDeltaMinor'], $currency, true) }}
                                    </td>
                                    <td>
                                        {{ $operation['bonusDeltaMinor'] === 0 ? '—' : $formatMoney($operation['bonusDeltaMinor'], $currency, true) }}
                                    </td>
                                    <td class="{{ $totalClass }}">
                                        {{ $formatMoney($operation['totalDeltaMinor'], $currency, true) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
