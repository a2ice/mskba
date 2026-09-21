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

        <p class="account-wallet__notice">
            Пополнение, вывод и переводы пока не подключены. На этом этапе кошелёк показывает баланс и финансовую историю.
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
                                    <td>{{ $operation['label'] }}</td>
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
