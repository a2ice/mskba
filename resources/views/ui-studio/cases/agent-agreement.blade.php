@php
    $state = $state ?? 'normal';
    $status = match($state) {
        'accepted' => 'accepted', 'verified' => 'verified', 'active' => 'active',
        'closed' => 'deactivated', default => 'proposed',
    };
    $agreement = [
        'id' => 101, 'venue_name' => 'Баскетбольный зал — демонстрация',
        'status' => $status,
        'status_label' => match($status) {
            'accepted' => 'Ожидает проверки', 'verified' => 'Ожидает активации',
            'active' => 'Действует', 'deactivated' => 'Прекращён',
            default => 'Ожидает вашего ответа',
        },
        'rate_label' => '5,25%', 'proposed_at' => '27.11.2026',
        'terms' => 'Условия демонстрационного договора. Агент получает согласованную комиссию за аренду. Подтверждение чужих оплат не входит в полномочия.',
        'permissions' => ['Просмотр', 'Просмотр предварительного интереса к аренде', 'Предложить бронирование по предзаявке'],
        'can_accept' => $status === 'proposed',
        'can_decline' => in_array($status, ['proposed','accepted','verified'], true),
        'terms_hash' => str_repeat('a',64), 'accept_url' => '#', 'decline_url' => '#',
        'contract_url' => in_array($status,['active','deactivated'],true) ? '#' : null,
    ];
@endphp
<div class="account-contracts-workspace" style="max-width:1000px;margin:0 auto;padding:16px">
    <div class="account-contracts-workspace__cards">
        @include('account.partials.agent-agreement-card', ['agreement' => $agreement, 'previewMode' => true])
    </div>
</div>
