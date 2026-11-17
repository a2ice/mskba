@php
    $money = app(\App\Modules\VenueBooking\Application\Services\MinorAmountParser::class);
    $btn = $specialButtonClass ?? 'button secondary';
@endphp
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
@if(!config('features.venue_rental.special_terms'))
    <p role="status">Применение специальных условий пока выключено. Условия можно подготовить, но цена аренды не изменится.</p>
@endif
<p>Скидку оплачивает площадка. Применяется одно наиболее выгодное условие. Использование списывается при отправке заявки, в том числе при последующей отмене. Чтобы изменить цену, отзови условие и создай новое.</p>
<form method="GET" action="{{ route('account.venues.special-terms.index', $venue->id) }}" class="app-rental-pricing__form">
    <label class="field-group">Найти получателя по логину
        <input type="text" name="recipient_search" minlength="2" maxlength="80" value="{{ request('recipient_search') }}">
    </label>
    <button class="{{ $btn }}" type="submit">Найти</button>
</form>
<section class="panel app-rental-pricing__panel">
    <h2>Новое условие</h2>
    <form method="POST" action="{{ route('account.venues.special-terms.store', $venue->id) }}" class="app-rental-pricing__form">
        @csrf
        <div class="app-rental-pricing__grid">
            <label class="field-group">Название <input name="title" required minlength="3" maxlength="120" value="{{ old('title') }}"></label>
            <label class="field-group">Причина (видна получателю) <textarea name="description" required maxlength="1000">{{ old('description') }}</textarea></label>
            <label class="field-group">Тип <span class="select-control"><select name="audience" required>
                <option value="USER_BOUND" @selected(old('audience') === 'USER_BOUND')>Персональные условия</option>
                <option value="PUBLIC" @selected(old('audience') === 'PUBLIC')>Публичный купон</option>
            </select></span></label>
            <label class="field-group">Получатели (найди по логину выше; можно выбрать несколько)
                <span class="select-control"><select name="recipient_ids[]" multiple>
                    @foreach($candidates as $candidate)<option value="{{ $candidate->id }}" @selected(in_array($candidate->id, old('recipient_ids', [])))>{{ $candidate->username }}</option>@endforeach
                </select></span>
            </label>
            <label class="check-label"><input type="checkbox" name="auto_apply" value="1" @checked(old('auto_apply'))> Применять автоматически к получателям</label>
            <label class="field-group">Код (обязателен для публичного купона)
                <input name="code" minlength="5" maxlength="32" pattern="[A-Za-z0-9_-]+" value="{{ old('code') }}">
            </label>
            <label class="field-group">Расчёт <span class="select-control"><select name="discount_type">
                @foreach(['percent' => 'Скидка, %', 'fixed' => 'Скидка, копейки', 'fixed_total' => 'Итоговая цена, копейки'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('discount_type') === $value)>{{ $label }}</option>
                @endforeach
            </select></span></label>
            <label class="field-group">Значение <input name="discount_value" type="number" min="1" max="100000000" required value="{{ old('discount_value') }}"></label>
            <label class="field-group">Зал <span class="select-control"><select name="venue_court_id"><option value="">Все залы</option>
                @foreach($courts as $option)<option value="{{ $option->id }}" @selected((int)old('venue_court_id') === (int)$option->id)>{{ $option->name }}</option>@endforeach
            </select></span></label>
            <label class="field-group">Зона <span class="select-control"><select name="scope"><option value="">Любая</option>
                @foreach(['whole' => 'Весь зал', 'half_a' => 'Половина А', 'half_b' => 'Половина Б'] as $value => $label)<option value="{{ $value }}" @selected(old('scope') === $value)>{{ $label }}</option>@endforeach
            </select></span></label>
            @foreach(['valid_from' => 'Начало действия (пусто = сейчас)', 'valid_until' => 'Окончание (пусто = бессрочно)'] as $name => $label)
                <label class="field-group">{{ $label }} <input type="datetime-local" name="{{ $name }}" value="{{ old($name) }}"></label>
            @endforeach
            @foreach(['booking_date_from' => 'Дата аренды с', 'booking_date_to' => 'Дата аренды по'] as $name => $label)
                <label class="field-group">{{ $label }} <input type="date" name="{{ $name }}" value="{{ old($name) }}"></label>
            @endforeach
            @foreach(['time_from' => 'Время аренды с', 'time_to' => 'Время аренды до'] as $name => $label)
                <label class="field-group">{{ $label }} <input type="time" name="{{ $name }}" value="{{ old($name) }}"></label>
            @endforeach
            @foreach(['minimum_amount_minor' => 'Минимальная сумма, копейки', 'max_total_uses' => 'Общий лимит использований', 'max_uses_per_account' => 'Лимит на аккаунт'] as $name => $label)
                <label class="field-group">{{ $label }} <input type="number" name="{{ $name }}" min="{{ $name === 'minimum_amount_minor' ? 0 : 1 }}" value="{{ old($name) }}"></label>
            @endforeach
        </div>
        <p>Даты и время аренды — в часовом поясе площадки: {{ $venue->schedule()->value('timezone') ?: config('app.timezone') }}. Пустые ограничения означают отсутствие лимита.</p>
        <fieldset><legend>Дни недели (пусто = все)</legend>
            @foreach([1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'] as $value => $label)
                <label class="check-label"><input type="checkbox" name="weekdays[]" value="{{ $value }}" @checked(in_array($value, old('weekdays', [])))>{{ $label }}</label>
            @endforeach
        </fieldset>
        <button type="submit" class="{{ $btn }}">Сохранить черновик</button>
    </form>
</section>
<section class="panel app-rental-pricing__panel">
    <h2>Предпросмотр для получателя</h2>
    <p>Использует обычный расчёт аренды и опубликованные условия. Лимиты не списываются.</p>
    <form method="POST" action="{{ route('account.venues.special-terms.preview', $venue->id) }}" class="app-rental-pricing__form">
        @csrf
        <div class="app-rental-pricing__grid">
            <label class="field-group">Получатель <span class="select-control"><select name="recipient_id" required>
                @foreach($candidates as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->username }}</option>@endforeach
            </select></span></label>
            <label class="field-group">Зал <span class="select-control"><select name="venue_court_id" required>
                @foreach($courts as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
            </select></span></label>
            <label class="field-group">Начало <input type="datetime-local" name="starts_at" required></label>
            <label class="field-group">Длительность, мин <input type="number" name="duration_minutes" min="1" max="1440" required></label>
            <label class="field-group">Зона <span class="select-control"><select name="scope"><option value="whole">Весь зал</option><option value="half_a">Половина А</option><option value="half_b">Половина Б</option></select></span></label>
            <label class="field-group">Промокод <input name="coupon_code" maxlength="32"></label>
        </div>
        <button class="{{ $btn }}" type="submit">Рассчитать для получателя</button>
    </form>
    @if($preview = session('special_preview'))
        <p role="status">
            @if($personal = $preview['personal_discount'])<del>{{ $money->format($personal['original_amount_minor'], $preview['currency']) }}</del>@endif
            <strong title="{{ $personal['reason'] ?? '' }}">{{ $money->format($preview['amount_minor'], $preview['currency']) }} {{ $money->currencyLabel($preview['currency']) }}</strong>
        </p>
    @endif
</section>
<section class="panel app-rental-pricing__panel">
    <h2>Условия и история</h2>
    @forelse($offers as $offer)
        <article>
            <h3>{{ $offer->title }} · {{ $offer->status }}</h3>
            <p>{{ $offer->description }}</p>
            <p>{{ $offer->audience }} · {{ $offer->discount_type }}: {{ $offer->discount_value }} · Использовано: {{ $offer->used_count }}</p>
            <p>Автор: #{{ $offer->created_by_user_id }} · Код: <strong>{{ $offer->code_ciphertext }}</strong></p>
            <p>Получатели: {{ $offer->recipients->pluck('username')->join(', ') }}</p>
            <p>Действует с {{ $offer->valid_from }} до {{ $offer->valid_until ?? 'бессрочно' }}</p>
            <div class="app-rental-pricing__actions">
                @foreach(['publish' => 'Опубликовать', 'pause' => 'Приостановить', 'revoke' => 'Отозвать'] as $action => $label)
                    @if(($action === 'publish' && in_array($offer->status, ['draft','paused'], true)) || ($action === 'pause' && $offer->status === 'active') || ($action === 'revoke' && $offer->status !== 'revoked'))
                        <form method="POST" action="{{ route('account.venues.special-terms.transition', [$venue->id, $offer->id, $action]) }}">@csrf<button class="{{ $btn }}" type="submit">{{ $label }}</button></form>
                    @endif
                @endforeach
            </div>
            <details><summary>История</summary>
                @foreach($offer->audits as $audit)<p>{{ $audit->created_at }} · {{ $audit->action }} · #{{ $audit->actor_user_id }} · v{{ $audit->terms_version }}</p>@endforeach
            </details>
        </article>
    @empty<p>Специальных условий пока нет.</p>@endforelse
    {{ $offers->links() }}
</section>
