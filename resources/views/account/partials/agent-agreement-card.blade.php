{{-- Shared candidate for both account themes. All dynamic content is escaped. --}}
<article class="account-contracts-workspace__card">
    <div class="account-contracts-workspace__card-head">
        <div>
            <p class="account-contracts-workspace__eyebrow">Агентский договор</p>
            <h2>{{ $agreement['venue_name'] }}</h2>
        </div>
        <span class="account-contracts-workspace__status {{ $agreement['status'] === 'active' ? 'is-active' : '' }}">{{ $agreement['status_label'] }}</span>
    </div>
    <p>Комиссия агента: <strong>{{ $agreement['rate_label'] }}</strong></p>
    <div class="account-contracts-workspace__meta">
        <span>Предложение № {{ $agreement['id'] }}</span>
        <span>{{ $agreement['proposed_at'] }}</span>
    </div>
    <details @if($agreement['status'] === 'proposed') open @endif>
        <summary>Условия и полномочия</summary>
        <p style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $agreement['terms'] }}</p>
        <p><strong>Полномочия после активации</strong></p>
        @if($agreement['permissions'] !== [])
            <ul>
                @foreach($agreement['permissions'] as $permission)
                    <li>{{ $permission }}</li>
                @endforeach
            </ul>
        @else
            <p>Дополнительные полномочия не предусмотрены.</p>
        @endif
    </details>
    @if($agreement['status'] === 'proposed')
        <p>Принятие предложения не активирует договор. MSKBA отдельно проверит основание и подтвердит назначение.</p>
    @elseif($agreement['status'] === 'accepted')
        <p>Вы приняли условия. Договор ожидает проверки MSKBA.</p>
    @elseif($agreement['status'] === 'verified')
        <p>Договор проверен. Ожидается отдельная активация MSKBA.</p>
    @elseif($agreement['status'] === 'active')
        <p>Договор действует. Согласованные полномочия предоставлены.</p>
    @else
        <p>Предложение закрыто или действие договора завершено.</p>
    @endif
    @if(!empty($agreement['can_accept']))
        <form class="d-grid gap-2" method="POST" action="{{ $agreement['accept_url'] }}" @if($previewMode ?? false) onsubmit="event.preventDefault();this.querySelector('[data-demo-status]').hidden=false;" @endif>
            @unless($previewMode ?? false) @csrf @endunless
            <input type="hidden" name="terms_hash" value="{{ $agreement['terms_hash'] }}">
            <label style="display:flex;align-items:flex-start;gap:.5rem">
                <input type="checkbox" name="accepted" value="1" required style="margin-top:.25rem">
                <span>Я ознакомился с условиями и принимаю предложение.</span>
            </label>
            <div class="account-contracts-workspace__actions">
                <button class="account-contracts-workspace__primary" type="submit">Принять договор</button>
            </div>
            @if($previewMode ?? false)<p role="status" hidden data-demo-status>Демонстрация: предложение принято, запрос не отправлен.</p>@endif
        </form>
    @endif
    @if(!empty($agreement['can_decline']))
        <details>
            <summary>Отказаться от предложения</summary>
            <form class="d-grid gap-2" method="POST" action="{{ $agreement['decline_url'] }}" @if($previewMode ?? false) onsubmit="event.preventDefault();this.querySelector('[data-demo-status]').hidden=false;" @endif>
                @unless($previewMode ?? false) @csrf @endunless
                <label class="d-grid gap-2">Причина отказа
                    <textarea class="form-control" name="reason" minlength="10" maxlength="500" required rows="3"></textarea>
                </label>
                <div class="account-contracts-workspace__actions">
                    <button class="account-contracts-workspace__secondary" type="submit">Отклонить предложение</button>
                </div>
                @if($previewMode ?? false)<p role="status" hidden data-demo-status>Демонстрация: предложение отклонено, запрос не отправлен.</p>@endif
            </form>
        </details>
    @endif
    @if(!empty($agreement['contract_url']))
        <div class="account-contracts-workspace__actions">
            <a class="account-contracts-workspace__secondary" href="{{ $agreement['contract_url'] }}">Подробнее о контракте</a>
        </div>
    @endif
</article>
