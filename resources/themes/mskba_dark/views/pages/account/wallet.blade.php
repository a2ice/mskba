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

            <form method="POST" action="{{ route('account.wallet.transfers.store') }}" class="account-wallet__transfer-form form-row--top">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">

                <div class="account-wallet__recipient-picker">
                    @include('theme::partials.forms.entity-predictive-search', [
                        'id' => 'walletTransferRecipient',
                        'name' => 'recipient_user_id',
                        'label' => 'Получатель',
                        'placeholder' => 'Имя, никнейм или логин...',
                        'searchUrl' => route('account.wallet.transfer-recipients'),
                        'minimumLength' => 2,
                        'required' => true,
                    ])
                    @error('recipient_user_id')<div class="form-error">{{ $message }}</div>@enderror
                </div>

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

                <div class="form-row__action">
                    <button type="submit" class="btn btn--primary btn--sm" @disabled($bonusBalanceMinor <= 0)>
                        Перевести
                    </button>
                </div>
            </form>

            <p class="account-wallet__transfer-hint">
                Доступно для перевода: <strong>{{ $formatMoney($bonusBalanceMinor, $currency) }}</strong>. Получатель увидит уведомление и toast о зачислении.
            </p>
        </section>

        @if($canGrantBonus)
            <section class="account-wallet__grant" aria-labelledby="account-wallet-grant-title">
                <div>
                    <h2 class="h3 mb-1" id="account-wallet-grant-title">Начислить бонусы</h2>
                    <p>Служебное начисление на свой бонусный баланс. Каждое начисление фиксируется в финансовой истории.</p>
                </div>

                <div class="account-wallet__grant-form form-row--top">
                    <label>
                        <span>Сумма, ₽</span>
                        <input
                            type="number"
                            class="form-control"
                            value="{{ old('grant_amount') }}"
                            placeholder="10000"
                            min="0.01"
                            max="999999999.99"
                            step="0.01"
                            inputmode="decimal"
                            autocomplete="off"
                            required
                            aria-describedby="wallet-bonus-grant-amount-help"
                            data-wallet-bonus-grant-amount
                        >
                        <small id="wallet-bonus-grant-amount-help" class="text-muted">Сумма в рублях с точностью до копеек, например 500 или 1250,50.</small>
                        @error('grant_amount')<div class="form-error">{{ $message }}</div>@enderror
                    </label>

                    <div class="form-row__action">
                        <button
                            type="button"
                            class="btn btn--secondary btn--sm js-handler"
                            data-handler="modal"
                            data-modal-action="open"
                            data-modal-target="wallet-bonus-grant-confirm"
                            data-wallet-bonus-grant-open
                        >
                            Начислить
                        </button>
                    </div>
                </div>
            </section>

            @component('theme::partials.modal.layout', [
                'id' => 'wallet-bonus-grant-confirm',
                'dialogClass' => 'account-wallet-bonus-grant-modal__dialog',
                'openOnLoad' => $errors->has('grant_password') || $errors->has('grant'),
                'persistInUrl' => false,
            ])
                <div class="account-wallet-bonus-grant-confirm">
                    <h2 class="modal_title" id="modal-title-wallet-bonus-grant-confirm">Подтвердить начисление</h2>
                    <p>
                        Начислить <strong data-wallet-bonus-grant-display>{{ old('grant_amount', '—') }} ₽</strong>
                        на бонусный баланс?
                    </p>

                    <form method="POST" action="{{ route('account.wallet.bonus-grants.store') }}" data-wallet-bonus-grant-form>
                        @csrf
                        <input
                            type="hidden"
                            name="grant_amount"
                            value="{{ old('grant_amount') }}"
                            data-wallet-bonus-grant-modal-amount
                        >
                        <input
                            type="hidden"
                            name="grant_idempotency_key"
                            value="{{ old('grant_idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}"
                        >

                        <label>
                            <span>Текущий пароль superadmin</span>
                            <input
                                type="password"
                                name="grant_password"
                                class="form-control @error('grant_password') is-invalid @enderror"
                                autocomplete="current-password"
                                data-wallet-bonus-grant-password
                                required
                            >
                        </label>

                        @error('grant_password')<div class="form-error">{{ $message }}</div>@enderror
                        @error('grant')<div class="form-error">{{ $message }}</div>@enderror

                        <div class="account-wallet-bonus-grant-confirm__actions">
                            <button type="submit" class="btn btn--primary btn--sm">Подтвердить начисление</button>
                            <button
                                type="button"
                                class="btn btn--secondary btn--sm js-handler"
                                data-handler="modal"
                                data-modal-action="close"
                            >
                                Отмена
                            </button>
                        </div>
                    </form>
                </div>
            @endcomponent
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
