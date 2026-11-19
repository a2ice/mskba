@php
    $title = 'Заявка на аренду · '.$booking->venue->name;
    $timezone = $booking->venue->schedule()->value('timezone') ?: 'Europe/Moscow';
    $money = app(\App\Modules\VenueBooking\Application\Services\MinorAmountParser::class);
@endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section
        class="first-screen venue-booking-page"
        data-venue-booking-page
        data-booking-id="{{ $booking->public_id }}"
        data-booking-version="{{ $booking->optimistic_version }}"
        data-booking-details-url="{{ route('account.venue-bookings.show', $booking) }}"
    ><div class="inner">
        @include('theme::partials.breadcrumbs')
        <h1 class="section-title mb-4">{{ $title }}</h1>
        @if($booking->pre_request_id)
            <p>Создано представителем по предзаявке.
                <a href="{{ route('account.venue-pre-requests.show', $booking->preRequest) }}">Переписка и согласование предложения</a></p>
        @endif
        @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
        @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

        @php
            $statusReason = $booking->status->isTerminal() ? $booking->transitions->last()?->reason : null;
        @endphp

        <div class="card mb-4 venue-booking-summary"><div class="card-body">
            <div class="venue-booking-summary__header">
                <div>
                    @if($special = data_get($booking->quote_snapshot, 'pricing.personal_discount'))
                        <p><del>{{ number_format($special['original_amount_minor'] / 100, 2, ',', ' ') }}</del>
                        <strong title="{{ $special['reason'] }}" tabindex="0">{{ number_format($special['final_amount_minor'] / 100, 2, ',', ' ') }} {{ data_get($booking->quote_snapshot, 'pricing.currency') }}</strong></p>
                    @endif
                    <p><strong>Статус:</strong> {{ $booking->status->label() }}</p>
                    @if($statusReason)<p class="venue-booking-status-reason"><strong>Причина:</strong> {{ $statusReason }}</p>@endif
                    <p>{{ $booking->starts_at->format('d.m.Y H:i') }}–{{ $booking->ends_at->format('d.m.Y H:i') }}</p>
                </div>
                @if(!$isRequester)
                    <div class="venue-booking-applicant">
                        <span class="venue-booking-applicant__label">Заявитель</span>
                        @if(config('features.venue_rental.conversations'))
                            <button
                                class="venue-booking-applicant__button"
                                type="button"
                                data-handler="modal"
                                data-modal-action="open"
                                data-modal-target="venue-booking-conversation"
                            >
                                <i class="ti ti-user" aria-hidden="true"></i>
                                <span>{{ $booking->requester?->username ?? 'Пользователь' }}</span>
                                <span data-booking-unread-wrap @if(!$conversationUnread) hidden @endif>(<span data-booking-unread-count>{{ $conversationUnread }}</span>)</span>
                            </button>
                        @else
                            <strong>{{ $booking->requester?->username ?? 'Пользователь' }}</strong>
                        @endif
                    </div>
                @endif
            </div>
            @if($booking->hold_expires_at)<p>Подтвердить бронь до (холд): {{ $booking->hold_expires_at->setTimezone($timezone)->format('d.m.Y H:i') }} (время площадки)</p>@endif
            @if($booking->effective_protection_until)<p>Крайний срок подтверждения брони: {{ $booking->effective_protection_until->setTimezone($timezone)->format('d.m.Y H:i') }} (время площадки)</p>@endif
            <p class="text-muted">Серверное время: {{ now()->format('d.m.Y H:i:s') }}</p>
            <p class="text-muted">Версия состояния: {{ $booking->optimistic_version }}</p>

            <div class="venue-booking-actions">
                @foreach(['accept' => 'Принять', 'confirm' => 'Подтвердить'] as $action => $label)
                    @if($actions[$action]['allowed'])
                        <form method="POST" action="{{ route('account.venue-bookings.'.$action, $booking) }}">
                            @csrf
                            <input type="hidden" name="version" value="{{ $booking->optimistic_version }}">
                            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <button class="btn {{ in_array($action, ['accept', 'confirm'], true) ? 'btn--primary' : 'btn--secondary' }} btn--sm" type="submit">{{ $label }}</button>
                        </form>
                    @endif
                @endforeach
                @foreach(['reject' => ['Отклонить', 'Подтвердить отклонение'], 'cancel' => ['Отменить', 'Подтвердить отмену']] as $action => [$label, $confirmLabel])
                    @if($actions[$action]['allowed'])
                        <details class="venue-booking-decision">
                            <summary class="btn btn--secondary btn--sm">{{ $label }}</summary>
                            <form method="POST" action="{{ route('account.venue-bookings.'.$action, $booking) }}">
                                @csrf
                                <input type="hidden" name="version" value="{{ $booking->optimistic_version }}">
                                <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                <label class="form-label" for="booking-{{ $action }}-reason">Причина (необязательно)</label>
                                <textarea class="form-control" id="booking-{{ $action }}-reason" name="reason" maxlength="2000" rows="3"></textarea>
                                <button class="btn btn--secondary btn--sm" type="submit">{{ $confirmLabel }}</button>
                            </form>
                        </details>
                    @endif
                @endforeach
            </div>
        </div></div>

        @if($interestLink)
            <div class="alert alert-info mb-3">
                Эта заявка связана с предварительным интересом к аренде.
                Инициатор оформления: <strong>{{ $booking->interest_booking_initiator === 'venue' ? 'представитель площадки (предложение принял организатор)' : 'организатор' }}</strong>.
                <a href="{{ $interestLink }}" class="fc-link">Открыть общую переписку и историю</a>
            </div>
        @endif
        @if($isRequester && config('features.venue_rental.conversations'))
            <button
                class="venue-booking-message-notice {{ $conversationUnread ? 'is-unread' : '' }}"
                type="button"
                data-booking-message-notice
                data-handler="modal"
                data-modal-action="open"
                data-modal-target="venue-booking-conversation"
            >
                <i class="ti ti-message-circle" aria-hidden="true"></i>
                <span data-booking-message-notice-label>{{ $conversationUnread ? 'Новые сообщения' : 'Переписка по заявке' }}</span>
                <span data-booking-unread-wrap @if(!$conversationUnread) hidden @endif>(<span data-booking-unread-count>{{ $conversationUnread }}</span>)</span>
            </button>
        @endif

        @if($booking->eventIntent && !$booking->event)
            <div class="alert {{ $booking->status->isTerminal() ? 'alert-warning' : 'alert-info' }} mb-4">
                @if($booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::CONFIRMED)
                    <strong>Бронь для мероприятия «{{ $booking->eventIntent->event_payload['title'] }}» подтверждена.</strong>
                    Страница мероприятия создаётся автоматически. Обновите страницу через несколько секунд.
                @elseif($booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::REJECTED)
                    <strong>Мероприятие «{{ $booking->eventIntent->event_payload['title'] }}» не создано: заявка на аренду отклонена.</strong>
                @elseif($booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::CANCELLED)
                    <strong>Мероприятие «{{ $booking->eventIntent->event_payload['title'] }}» не создано: заявка на аренду отменена.</strong>
                @elseif($booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::EXPIRED)
                    <strong>Мероприятие «{{ $booking->eventIntent->event_payload['title'] }}» не создано: срок заявки истёк.</strong>
                @else
                    <strong>Мероприятие «{{ $booking->eventIntent->event_payload['title'] }}» ожидает подтверждения брони.</strong>
                    До подтверждения отдельный черновик не создаётся и публикация в каталоге и Telegram не выполняется.
                @endif
            </div>
        @endif

        @if(config('features.venue_rental.booking_events') && $booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::CONFIRMED)
            <div class="card mb-4"><div class="card-body">
                <h2 class="h4">Мероприятие</h2>
                @if($booking->event)
                    <a href="{{ route('events.show', $booking->event->routeIdentifier()) }}">Открыть созданное мероприятие «{{ $booking->event->title }}»</a>
                    @if($isRequester && $booking->starts_at->isFuture())
                        <form method="POST" action="{{ route('account.venue-bookings.event.reschedule', [$booking, $booking->event]) }}" class="row g-3 mt-2">
                            @csrf
                            <input type="hidden" name="venue_id" value="{{ $booking->venue_id }}">
                            <div class="col-md-4"><label class="form-label">Новое начало</label><input class="form-control" type="datetime-local" name="starts_at" value="{{ $booking->starts_at->format('Y-m-d\TH:i') }}" required></div>
                            <div class="col-md-3"><label class="form-label">Длительность, мин</label><input class="form-control" type="number" name="duration_minutes" min="1" max="1440" value="{{ (int) $booking->starts_at->diffInMinutes($booking->ends_at) }}" required></div>
                            <div class="col-md-3"><label class="form-label">Зона</label><select class="form-control" name="scope"><option value="whole" @selected($booking->scope?->value === 'whole')>Вся площадка</option><option value="half_a" @selected($booking->scope?->value === 'half_a')>Половина A</option><option value="half_b" @selected($booking->scope?->value === 'half_b')>Половина B</option></select></div>
                            <div class="col-md-2 align-self-end"><button class="btn btn--secondary btn--sm" type="submit">Перенести</button></div>
                        </form>
                    @endif
                @elseif($isRequester && !$booking->eventIntent)
                    <form method="POST" action="{{ route('account.venue-bookings.event.store', $booking) }}" class="row g-3">
                        @csrf
                        <div class="col-md-5"><label class="form-label">Название</label><input class="form-control" name="title" maxlength="255" required></div>
                        <div class="col-md-3"><label class="form-label">Тип</label><select class="form-control" name="type"><option value="training">Тренировка</option><option value="game_training">Игровая тренировка</option><option value="game">Игра</option></select></div>
                        <div class="col-md-2"><label class="form-label">Видимость</label><select class="form-control" name="visibility"><option value="public">Публичное</option><option value="private">По приглашению</option></select></div>
                        <div class="col-md-2 align-self-end"><button class="btn btn--primary btn--sm" type="submit">Создать</button></div>
                    </form>
                @endif
            </div></div>
        @endif

        @if(config('features.venue_rental.attendance_v2'))
            <div class="card mb-4"><div class="card-body">
                <h2 class="h4">Подтверждение явки</h2>
                <p class="text-muted">Ответы участников носят информационный характер и не продлевают удержание слота.</p>
                @if($attendanceRound)
                    <p><a href="{{ route('venue-booking-attendance.show', $attendanceRound) }}">Открыть текущий или последний сбор ответов</a></p>
                @elseif($isRequester && $booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::HELD && $booking->effective_protection_until?->isFuture() && $attendanceCandidates->isNotEmpty())
                    <form method="POST" action="{{ route('venue-booking-attendance.store', $booking) }}">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label">Ответить до</label>
                                <input class="form-control" type="datetime-local" name="deadline_at" required max="{{ $booking->effective_protection_until->format('Y-m-d\TH:i') }}" value="{{ $booking->effective_protection_until->format('Y-m-d\TH:i') }}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Минимум «Пойду»</label>
                                <input class="form-control" type="number" name="minimum_yes_responses" min="1" max="{{ $attendanceCandidates->count() }}" value="{{ min(2, $attendanceCandidates->count()) }}" required>
                            </div>
                        </div>
                        <fieldset class="mt-3"><legend class="h6">Пригласить</legend>
                            @foreach($attendanceCandidates as $candidate)
                                <label class="form-check"><input class="form-check-input" type="checkbox" name="invited_user_ids[]" value="{{ $candidate->user_id }}" checked> {{ $candidate->user->username }}</label>
                            @endforeach
                        </fieldset>
                        <input type="hidden" name="responses_visibility" value="participants">
                        <button class="btn btn--primary btn--sm mt-3" type="submit">Открыть сбор явки</button>
                    </form>
                @else
                    <p>Сбор можно открыть только заявителю во время действующего удержания и при наличии приглашённых участников.</p>
                @endif
            </div></div>
        @endif

        <div class="card mb-4"><div class="card-body">
            <h2 class="h4">Продление удержания</h2>
            @php
                $extensionAllowed = (bool) data_get($booking->quote_snapshot, 'policy.allows_hold_extension', false);
                $extensionLimit = (int) data_get($booking->quote_snapshot, 'policy.maximum_hold_extension_minutes', 0);
                $pendingExtension = $booking->extensionRequests->first(fn($extension) => $extension->status === \App\Modules\VenueBooking\Domain\Enums\VenueBookingExtensionStatus::PENDING);
            @endphp
            @if($isRequester && $extensionAllowed && !$pendingExtension && $booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::HELD && $booking->effective_protection_until?->isFuture())
                <p class="text-muted">Можно запросить срок не позднее {{ $booking->hold_expires_at->addMinutes($extensionLimit)->format('d.m.Y H:i') }}.</p>
                <form method="POST" action="{{ route('account.venue-bookings.extensions.store', $booking) }}" class="row g-3 mb-4">
                    @csrf
                    <div class="col-md-4"><label class="form-label">Новый срок</label><input class="form-control" type="datetime-local" name="requested_until" min="{{ $booking->effective_protection_until->format('Y-m-d\TH:i') }}" max="{{ $booking->hold_expires_at->addMinutes($extensionLimit)->format('Y-m-d\TH:i') }}" required></div>
                    <div class="col-md-6"><label class="form-label">Причина</label><textarea class="form-control" name="reason" maxlength="2000" required></textarea></div>
                    <div class="col-md-2 align-self-end"><button class="btn btn--primary btn--sm" type="submit">Запросить</button></div>
                </form>
            @endif
            @forelse($booking->extensionRequests as $extension)
                <div class="border-top pt-3 mb-3">
                    <p><strong>{{ $extension->status->label() }}</strong>: {{ $extension->previous_deadline_at->format('d.m.Y H:i') }} → {{ $extension->requested_until->format('d.m.Y H:i') }}</p>
                    <p>{{ $extension->reason }}</p>
                    @if($extension->decision_reason)<p class="text-muted">Комментарий: {{ $extension->decision_reason }}</p>@endif
                    @if($extension->status === \App\Modules\VenueBooking\Domain\Enums\VenueBookingExtensionStatus::PENDING)
                        <div class="venue-management-actions">
                            @if($canApproveExtensions)
                                <form method="POST" action="{{ route('account.venue-bookings.extensions.approve', [$booking, $extension]) }}">@csrf<button class="btn btn--secondary btn--sm" type="submit">Одобрить</button></form>
                            @endif
                            @if($canRejectExtensions)
                                <form method="POST" action="{{ route('account.venue-bookings.extensions.reject', [$booking, $extension]) }}">@csrf<button class="btn btn--secondary btn--sm" type="submit">Отклонить</button></form>
                            @endif
                            @if($isRequester)<form method="POST" action="{{ route('account.venue-bookings.extensions.cancel', [$booking, $extension]) }}">@csrf<button class="btn btn--secondary btn--sm" type="submit">Отменить запрос</button></form>@endif
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-muted">Запросов на продление пока нет.</p>
            @endforelse
        </div></div>

            @if($usesExternalHold)
        <section class="card mb-4" aria-labelledby="external-hold-title">
            <h2 id="external-hold-title">Удержание на стороне площадки</h2>
            @if($booking->externalHold)
                <p>{{ $booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::CONFIRMED ? 'Ранее подтверждённый внешний холд' : 'Подтверждённый внешний холд' }}: {{ $booking->externalHold->method }} · до {{ $booking->externalHold->expires_at->setTimezone($timezone)->format('d.m.Y H:i') }} (время площадки).</p>
                @if($booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::CONFIRMED)
                    <p>Это история удержания; аренда уже подтверждена, срок холда больше не является дедлайном бронирования.</p>
                @endif
                @if($booking->externalHold->note)<p>{{ $booking->externalHold->note }}</p>@endif
            @elseif($canConfirmExternalHold)
                <p>MSKBA создаст внутренний холд только после реального подтверждения.</p>
                <form class="mb-3" method="POST" action="{{ route('account.venue-bookings.external-hold.confirm', $booking) }}">
                    @csrf
                    <input type="hidden" name="version" value="{{ $booking->optimistic_version }}">
                    <label>Способ подтверждения
                        <select name="method" required>
                            <option value="phone">Телефон</option>
                            <option value="messenger">Мессенджер</option>
                            <option value="aggregator">Агрегатор</option>
                            <option value="other">Другой способ</option>
                        </select>
                    </label>
                    <label>Внешний холд действителен до
                        <input type="datetime-local" name="expires_at" required
                            value="{{ now()->addMinutes(min((int) data_get($booking->quote_snapshot, 'policy.hold_duration_minutes', 30), 30))->setTimezone($timezone)->format('Y-m-d\TH:i') }}">
                    </label>
                    <label>Номер брони (если есть)<input name="reference" maxlength="190"></label>
                    <label>Кто и как подтвердил удержание<textarea name="note" maxlength="2000" rows="2" required></textarea></label>
                    <button class="btn btn--primary btn--sm" type="submit">Подтвердить внешний холд</button>
                </form>
            @else
                <p>Ожидается подтверждение реального удержания слота.</p>
            @endif
        </section>
    @endif

    @if($usesInvoices)
        <section class="card mb-4" aria-labelledby="invoice-chain-title">
            <h2 id="invoice-chain-title">Счета и расчёты</h2>
            <p><strong>Срок удержания слота и сроки финансовых расчётов — разные вещи.</strong> Плановая дата внутреннего счёта не продлевает удержание и не разрешает подтверждение брони после его окончания.</p>
            @if($booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::HELD && $booking->effective_protection_until)
                <p><strong>Подтвердить бронирование необходимо до {{ $booking->effective_protection_until->setTimezone($timezone)->format('d.m.Y H:i') }} (время площадки).</strong> До этого момента все обязательные счета должны быть оплачены или переведены в разрешённую постоплату. После истечения холда бронь подтвердить нельзя.</p>
            @elseif($booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::CONFIRMED)
                <p><strong>Бронирование подтверждено.</strong> Срок удержания больше не ограничивает погашение уже согласованной постоплаты; задолженность сохраняется до фактического расчёта.</p>
            @endif
            @if($canIssueBuyerInvoice && $booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::HELD
                && ! $booking->invoices->contains('kind', 'buyer'))
                <form class="app-rental-inbox__fields" data-invoice-issue-form method="POST" action="{{ route('account.venue-bookings.invoices.issue', $booking) }}">
                    @csrf
                    <label>Шаблон счёта
                        <select name="template_id">
                            <option value="">Стандартный счёт</option>
                            @foreach($invoiceTemplates as $template)
                                <option value="{{ $template->id }}">{{ $template->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Способ оплаты
                        <select name="payment_method" data-invoice-method>
                            <option value="">Из шаблона</option>
                            <option value="sbp">СБП / перевод по номеру карты</option>
                            <option value="bank_transfer">Банковский перевод</option>
                            <option value="cash">Наличные</option>
                            <option value="other">Другой способ</option>
                        </select>
                    </label>
                    <div data-invoice-sbp-fields>
                        <label>Сохранённые реквизиты
                            <select name="payment_profile_id" data-invoice-profile>
                                <option value="">Новые реквизиты</option>
                                @foreach($invoicePaymentProfiles as $profile)
                                    <option value="{{ $profile->id }}">{{ $profile->label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <div data-invoice-new-profile>
                            <label>Банк получателя<input name="sbp_bank" maxlength="120" placeholder="Например, Сбербанк"></label>
                            <label>Телефон для СБП<input name="sbp_phone" maxlength="32" inputmode="tel" placeholder="+7 ..."></label>
                            <label>Или номер карты<input name="sbp_card" maxlength="32" inputmode="numeric"></label>
                            <label>Комментарий к переводу<input name="transfer_purpose" maxlength="400" placeholder="Например, за аренду зала"></label>
                            <label>Инструкция после оплаты<input name="confirmation_hint" maxlength="400" placeholder="Укажите номер операции из банковского приложения"></label>
                            <label><input type="checkbox" name="save_profile" value="1" checked> Сохранить реквизиты для следующих счетов</label>
                        </div>
                    </div>
                    <button class="btn btn--primary btn--sm" type="submit">Выставить счёт покупателю</button>
                </form>
            @endif
            @php
                $invoiceLabels = [
                    'buyer' => 'Счёт покупателю',
                    'agent_portal' => 'Агент → MSKBA',
                    'portal_venue' => 'MSKBA → площадка',
                ];
                $invoiceStatuses = [
                    'issued' => 'Выставлен', 'claimed' => 'Оплата заявлена',
                    'paid' => 'Оплачен', 'postpay' => 'Постоплата согласована',
                    'void' => 'Отменён',
                ];
                $invoiceAgentId = data_get($booking->quote_snapshot, 'pricing.commissions.agent.user_id');
            @endphp
            @forelse($visibleInvoices as $invoice)
                <article class="app-rental-inbox__summary" style="margin-top:1rem;padding:1rem;border:1px solid var(--color-border, #ddd);border-radius:8px">
                    <h3>{{ $invoiceLabels[$invoice->kind] ?? 'Счёт' }} · {{ $invoice->number }}</h3>
                    <p>{{ $invoiceStatuses[$invoice->status] ?? $invoice->status }} ·
                        <strong>{{ $money->format($invoice->amount_minor, $invoice->currency) }} {{ $money->currencyLabel($invoice->currency) }}</strong>
                    </p>
                    @if($invoice->due_at)
                        <p>{{ $invoice->kind === 'buyer' ? 'Срок оплаты покупателем (в пределах холда)' : 'Плановый срок взаиморасчёта (не продлевает холд)' }}: {{ $invoice->due_at->setTimezone($timezone)->format('d.m.Y H:i') }} (время площадки)</p>
                    @endif
                    @if($invoice->status === 'postpay')
                        <p><strong>Открытая задолженность.</strong> Постоплата согласована, но средства ещё не получены. После подтверждения аренды погасить этот долг можно отдельно.</p>
                    @elseif($invoice->kind !== 'buyer' && $booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::HELD)
                        <p>Для подтверждения аренды этот счёт нужно оплатить либо согласовать разрешённую постоплату до окончания холда, независимо от планового срока расчёта.</p>
                    @endif
                    @if($invoice->payment_instructions)<p>{{ $invoice->payment_instructions }}</p>@endif
                    @if($invoice->kind === 'buyer' && ($isRequester || $canIssueBuyerInvoice))
                        @if(data_get($invoice->payment_details, 'bank'))<p>Банк: {{ data_get($invoice->payment_details, 'bank') }}</p>@endif
                        @if(data_get($invoice->payment_details, 'phone'))<p>Телефон: {{ data_get($invoice->payment_details, 'phone') }}</p>@endif
                        @if(data_get($invoice->payment_details, 'card'))<p>Карта: {{ data_get($invoice->payment_details, 'card') }}</p>@endif
                        @if(data_get($invoice->payment_details, 'purpose'))<p>Комментарий к переводу: {{ data_get($invoice->payment_details, 'purpose') }}</p>@endif
                        @if($invoice->payment_reference)<p>Номер операции: {{ $invoice->payment_reference }}</p>@endif
                    @endif
                    @if($invoice->settlement_note)<p>Основание: {{ $invoice->settlement_note }}</p>@endif
                    @if($invoice->status === 'issued' && (($invoice->kind === 'buyer' && $isRequester)
                        || ($invoice->kind === 'agent_portal' && $invoiceAgentId !== null && (int) $invoiceAgentId === (int) auth()->id())))
                        <form method="POST" action="{{ route('account.venue-bookings.invoices.claim', [$booking, $invoice]) }}" class="app-rental-inbox__fields">
                            @csrf
                            @if(in_array('sbp', $invoice->payment_methods ?? [], true))
                                <label>Номер операции<input name="reference" maxlength="190" required></label>
                                @if(data_get($invoice->payment_details, 'confirmation_hint'))
                                    <p>{{ data_get($invoice->payment_details, 'confirmation_hint') }}</p>
                                @endif
                            @endif
                            <label>Комментарий об оплате<input name="note" maxlength="2000"></label>
                            <button class="btn btn--outline-secondary btn--sm" type="submit">Сообщить об оплате</button>
                        </form>
                    @endif
                    @php
                        $canSettleInvoice = ($invoice->kind === 'buyer' && $canIssueBuyerInvoice)
                            || ($invoice->kind !== 'buyer' && $canInvoicePortal);
                        $canAgentAttest = $invoice->kind === 'agent_portal'
                            && (bool) data_get($booking->quote_snapshot, 'policy.agent_portal_self_attestation_allowed', false)
                            && $invoiceAgentId !== null && (int) $invoiceAgentId === (int) auth()->id();
                    @endphp
                    @if(($canSettleInvoice || $canAgentAttest) && in_array($invoice->status, ['issued', 'claimed', 'postpay'], true))
                        <form method="POST" action="{{ route('account.venue-bookings.invoices.settle', [$booking, $invoice]) }}" class="app-rental-inbox__fields">
                            @csrf
                            <label>Решение по счёту
                                <select name="decision" required>
                                    @if($canSettleInvoice)
                                        <option value="received">{{ $invoice->kind === 'portal_venue' ? 'Подтверждаю получение средств площадкой' : 'Подтверждаю получение оплаты' }}</option>
                                        @if($invoice->status !== 'postpay')
                                            <option value="postpay">Согласована постоплата</option>
                                        @endif
                                    @endif
                                    @if($canAgentAttest)
                                        <option value="self_attested">Доверенное подтверждение агента</option>
                                    @endif
                                </select>
                            </label>
                            <label>Способ оплаты
                                <select name="method">
                                    <option value="bank_transfer">Перевод</option>
                                    <option value="sbp">СБП / перевод по карте</option>
                                    <option value="cash">Наличные</option>
                                    <option value="other">Другое</option>
                                </select>
                            </label>
                            <label>Основание решения<textarea name="note" maxlength="2000" rows="2" required></textarea></label>
                            <button class="btn btn--primary btn--sm" type="submit">Сохранить решение</button>
                        </form>
                    @endif
                </article>
            @empty
                <p>Счета ещё не выставлены. После подтверждения удержания будет доступно выставление счёта покупателю.</p>
            @endforelse
            @if($canIssueBuyerInvoice || $canInvoicePortal)
                <p><a href="{{ route('account.venues.invoice-templates.index', $booking->venue_id) }}">Настроить шаблоны счетов</a></p>
            @endif
        </section>
    @endif


        @if(! $usesInvoices && config('features.venue_rental.external_payment') && $canViewPayment && (bool) data_get($booking->quote_snapshot, 'policy.requires_payment', false))
            <div class="card mb-4"><div class="card-body">
                <h2 class="h4">Внешняя оплата</h2>
                <p><strong>Статус:</strong> {{ $booking->payment_state->value }}</p>
                @if(!$booking->paymentAttempt && $canConfirmPayment && $booking->status === \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::HELD && $booking->effective_protection_until?->isFuture())
                    <form method="POST" action="{{ route('account.venue-bookings.payment.open', $booking) }}" class="row g-3">
                        @csrf
                        <div class="col-md-3"><label class="form-label">Способ</label><select class="form-control" name="method"><option value="bank_transfer">Банковский перевод</option><option value="cash">Наличные</option><option value="other">Другой</option></select></div>
                        <div class="col-md-7"><label class="form-label">Реквизиты и инструкция</label><textarea class="form-control" name="payment_instructions" maxlength="4000" required></textarea></div>
                        <div class="col-md-2 align-self-end"><button class="btn btn--primary btn--sm" type="submit">Открыть окно</button></div>
                    </form>
                @elseif($booking->paymentAttempt)
                    @php $payment = $booking->paymentAttempt; @endphp
                    <p>{{ number_format($payment->amount_minor / 100, 2, ',', ' ') }} {{ app(\App\Modules\VenueBooking\Application\Services\MinorAmountParser::class)->currencyLabel($payment->currency) }}, способ: {{ $payment->method }}</p>
                    <p class="text-muted">Источник: {{ $payment->provider }}@if($payment->provider_reference), reference ***{{ substr($payment->provider_reference, -4) }}@endif</p>
                    <p>{{ $payment->payment_instructions }}</p>
                    <p class="text-muted">Оплатить и сообщить до {{ $payment->window_expires_at->format('d.m.Y H:i') }}. Заявление об оплате само по себе не подтверждает бронь.</p>
                    @if($isRequester && $payment->status === \App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState::WINDOW_OPEN && now()->lessThan($payment->window_expires_at))
                        <form method="POST" enctype="multipart/form-data" action="{{ route('account.venue-bookings.payment.claim', [$booking, $payment]) }}" class="row g-3">
                            @csrf
                            <div class="col-md-4"><label class="form-label">Номер/ссылка подтверждения</label><input class="form-control" name="reference" maxlength="255"></div>
                            <div class="col-md-3"><label class="form-label">Комментарий</label><textarea class="form-control" name="note" maxlength="2000"></textarea></div>
                            <div class="col-md-3"><label class="form-label">Чек (JPG, PNG, PDF до 5 МБ)</label><input class="form-control" type="file" name="evidence_file" accept="image/jpeg,image/png,application/pdf"></div>
                            <div class="col-md-2 align-self-end"><button class="btn btn--primary btn--sm" type="submit">Я оплатил</button></div>
                        </form>
                    @endif
                    @if($canConfirmPayment && $payment->status === \App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState::CLAIMED)
                        <div class="venue-management-actions">
                            <form method="POST" action="{{ route('account.venue-bookings.payment.confirm', [$booking, $payment]) }}">@csrf<button class="btn btn--primary btn--sm" type="submit">Подтвердить оплату</button></form>
                            <form method="POST" action="{{ route('account.venue-bookings.payment.reject', [$booking, $payment]) }}">@csrf<input type="hidden" name="reason" value="Доказательство оплаты не принято."><button class="btn btn--secondary btn--sm" type="submit">Отклонить</button></form>
                        </div>
                    @endif
                @endif
            </div></div>
        @endif

        @if(config('features.venue_rental.contributions') && $contributionSummary)
            @php
                $contributionExponent = $contributionSummary['currency_exponent'];
                $contributionDivisor = 10 ** $contributionExponent;
                $formatContribution = static function (int $minor) use ($contributionDivisor, $contributionExponent): string {
                    $whole = (string) intdiv($minor, $contributionDivisor);
                    if ($contributionExponent === 0) return $whole;
                    return $whole.','.str_pad((string) ($minor % $contributionDivisor), $contributionExponent, '0', STR_PAD_LEFT);
                };
                $ownContribution = $contributionSummary['own_commitment'];
                $contributionCurrencyLabel = app(\App\Modules\VenueBooking\Application\Services\MinorAmountParser::class)->currencyLabel($contributionSummary['currency']);
            @endphp
            <section class="card mb-4" aria-labelledby="booking-contributions-title"><div class="card-body">
                <h2 class="h4" id="booking-contributions-title">Обещания участников</h2>
                <p class="text-muted">Это добровольное обещание покрыть часть аренды, а не списание денег и не подтверждение оплаты.</p>
                <dl class="row">
                    <dt class="col-sm-4">Цель</dt><dd class="col-sm-8">{{ $formatContribution($contributionSummary['target_minor']) }} {{ $contributionCurrencyLabel }}</dd>
                    <dt class="col-sm-4">Обещано</dt><dd class="col-sm-8">{{ $formatContribution($contributionSummary['committed_minor']) }} {{ $contributionCurrencyLabel }}</dd>
                    <dt class="col-sm-4">Фактически подтверждено внешней оплатой</dt><dd class="col-sm-8">{{ $formatContribution($contributionSummary['confirmed_minor']) }} {{ $contributionCurrencyLabel }}</dd>
                </dl>
                @if($contributionSummary['is_open'])
                    <form method="POST" action="{{ route('account.venue-bookings.contributions.store', $booking) }}" class="row g-3">
                        @csrf
                        <div class="col-md-4">
                            <label class="form-label" for="contribution-amount">Моё обещание, {{ $contributionCurrencyLabel }}</label>
                            <input class="form-control" id="contribution-amount" name="amount" inputmode="decimal" value="{{ old('amount', $ownContribution ? $formatContribution($ownContribution['amount_minor']) : '') }}" required>
                        </div>
                        <div class="col-md-5 align-self-end">
                            <label class="form-check"><input class="form-check-input" type="checkbox" name="share_with_organizer" value="1" @checked(old('share_with_organizer', $ownContribution['share_with_organizer'] ?? false))> Разрешить организатору видеть мою индивидуальную сумму</label>
                        </div>
                        <div class="col-md-3 align-self-end"><button class="btn btn--primary btn--sm" type="submit">Сохранить обещание</button></div>
                    </form>
                @else
                    <p class="text-muted">Сбор закрыт: новые обещания не принимаются.</p>
                @endif
                @if($ownContribution)
                    <p class="mt-3">Ваша текущая сумма: <strong>{{ $formatContribution($ownContribution['amount_minor']) }} {{ app(\App\Modules\VenueBooking\Application\Services\MinorAmountParser::class)->currencyLabel($ownContribution['currency']) }}</strong>.</p>
                    <form method="POST" action="{{ route('account.venue-bookings.contributions.destroy', $booking) }}">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn--secondary btn--sm" type="submit">Отозвать обещание</button>
                    </form>
                @endif
            </div></section>
        @endif

        @if(config('features.venue_rental.conversations'))
            @component('theme::partials.modal.layout', [
                'id' => 'venue-booking-conversation',
                'dialogClass' => 'venue-booking-conversation-modal__dialog',
            ])
                <div class="venue-booking-conversation-modal__heading">
                    <p class="venue-booking-conversation-modal__eyebrow">Заявка на аренду</p>
                    <h2 class="modal_title" id="modal-title-venue-booking-conversation">Переписка</h2>
                    <p>{{ $isRequester ? $booking->venue->name : ($booking->requester?->username ?? 'Пользователь') }}</p>
                </div>
                <p class="text-muted">Сообщения не меняют статус, цену или срок удержания. Доменные решения показаны отдельно в истории ниже.</p>
                <div
                    id="booking-conversation-messages"
                    class="venue-booking-conversation-modal__messages"
                    aria-live="polite"
                    data-poll-url="{{ route('account.venue-bookings.conversation.index', $booking) }}"
                    data-read-url-base="{{ url('/account/venue-bookings/'.$booking->public_id.'/conversation') }}"
                    data-attachment-url-base="{{ url('/account/venue-bookings/'.$booking->public_id.'/conversation/messages') }}"
                    data-conversation-id="{{ $conversation?->public_id }}"
                >
                    @forelse($conversation?->messages?->sortBy('id') ?? [] as $message)
                        <article class="venue-booking-message" data-message-id="{{ $message->id }}" data-message-public-id="{{ $message->public_id }}">
                            <div class="venue-booking-message__header">
                                <strong>{{ $message->authorActor->user?->username ?? 'Система' }}</strong>
                                <time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->format('d.m.Y H:i') }}</time>
                            </div>
                            <p>{{ $message->body }}</p>
                            @if($message->attachment_path)<a href="{{ route('account.venue-bookings.conversation.attachment', [$booking, $message]) }}">Скачать: {{ $message->attachment_name }}</a>@endif
                        </article>
                    @empty
                        <p class="text-muted" data-booking-conversation-empty>Сообщений пока нет.</p>
                    @endforelse
                </div>
                <form id="booking-conversation-form" method="POST" action="{{ route('account.venue-bookings.conversation.store', $booking) }}" class="venue-booking-conversation-modal__form">
                    @csrf
                    <input type="hidden" name="client_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <label class="form-label" for="booking-message-body">Сообщение</label>
                    <textarea class="form-control" id="booking-message-body" name="body" maxlength="4000" required></textarea>
                    <button class="btn btn--primary btn--sm" type="submit">Отправить</button>
                </form>
                <form method="POST" enctype="multipart/form-data" action="{{ route('account.venue-bookings.conversation.attach', $booking) }}" class="venue-booking-conversation-modal__attachment" data-booking-conversation-attachment>
                    @csrf
                    <input type="hidden" name="client_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <label class="form-label" for="booking-message-attachment">Вложение: JPG, PNG, PDF или TXT до 10 МБ</label>
                    <input class="form-control" id="booking-message-attachment" type="file" name="attachment" accept="image/jpeg,image/png,application/pdf,text/plain" required>
                    <button class="btn btn--secondary btn--sm" type="submit">Прикрепить</button>
                </form>
                <p class="venue-booking-conversation-modal__status" data-booking-conversation-status aria-live="polite"></p>
            @endcomponent
        @endif

        <div class="card"><div class="card-body">
            <h2 class="h4">История</h2>
            <ul>
                @foreach($booking->transitions as $transition)
                    <li>{{ $transition->created_at->format('d.m.Y H:i') }} — {{ $transition->to_status->label() }}@if($transition->reason): {{ $transition->reason }}@endif</li>
                @endforeach
            </ul>
        </div></div>
    </div></section>
@endsection
