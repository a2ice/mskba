@php
    $title = 'Подтверждение управления площадкой';
    $statusValue = $claim->status->value;
    $applicantName = trim(implode(' ', array_filter([
        $claim->applicant?->profile?->first_name,
        $claim->applicant?->profile?->last_name,
    ]))) ?: $claim->applicant?->username;
    $ownership = $claim->ownership;
@endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section
        class="first-screen venue-ownership-page"
        data-venue-ownership-claim-page
        data-claim-id="{{ $claim->public_id }}"
        data-conversation-url="{{ route('account.venue-ownership.conversation.index', $claim) }}"
        data-message-url="{{ route('account.venue-ownership.conversation.store', $claim) }}"
        data-attachment-url="{{ route('account.venue-ownership.conversation.attach', $claim) }}"
        data-attachment-download-base="{{ url('/account/venue-ownership/'.$claim->public_id.'/conversation/messages') }}"
    >
        <div class="inner venue-ownership-shell venue-ownership-shell--claim">
            @include('theme::partials.breadcrumbs')

            @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
            @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
            @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

            <header class="venue-ownership-heading venue-ownership-heading--claim">
                <div>
                    <p class="venue-ownership-eyebrow">ЗАЯВКА · {{ $claim->public_id }}</p>
                    <h1>{{ $title }}</h1>
                    <a class="fc-link" href="{{ route('venues.show', $claim->venue->routeIdentifier()) }}">{{ $claim->venue->name }} →</a>
                </div>
                <div class="venue-ownership-live-status venue-ownership-live-status--{{ $statusValue }}" data-ownership-status>
                    <span class="venue-ownership-live-status__dot"></span>
                    <div>
                        <small>Статус</small>
                        <strong data-ownership-status-label>{{ $claim->status->label() }}</strong>
                    </div>
                </div>
            </header>

            <div class="venue-ownership-claim-grid">
                <main class="venue-ownership-claim-main">
                    <article class="venue-ownership-card venue-ownership-card--compact">
                        <div class="venue-ownership-card__meta">
                            <span>Заявитель</span>
                            <strong>{{ $applicantName ?: 'Пользователь MSKBA' }}</strong>
                            <span>Отправлена {{ $claim->submitted_at->format('d.m.Y H:i') }}</span>
                        </div>
                        <h2>Подтверждение полномочий</h2>
                        <p class="venue-ownership-evidence">{!! nl2br(e($claim->evidence)) !!}</p>

                        @if($claim->documents->isNotEmpty())
                            <div class="venue-ownership-source-documents">
                                <h3>Документы, приложенные к заявке</h3>
                                @foreach($claim->documents as $document)
                                    <div class="venue-ownership-source-document">
                                        <a href="{{ route('account.venue-ownership.documents.download', [$claim, $document]) }}">
                                            <i class="ti ti-paperclip"></i>
                                            <span>{{ $document->name }}</span>
                                        </a>
                                        @if($isReviewer && $ownership)
                                            <form method="POST" action="{{ route('admin.venue-ownership.documents.claim', [$ownership, $document]) }}" class="venue-ownership-promote-form">
                                                @csrf
                                                <select name="type" class="form-select" required>
                                                    @foreach($documentTypes as $type)
                                                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                                    @endforeach
                                                </select>
                                                <input class="form-control" type="text" name="note" maxlength="2000" placeholder="Комментарий (необязательно)">
                                                <button class="btn btn--secondary btn--sm" type="submit">В основания владения</button>
                                            </form>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if($claim->decision_reason)
                            <div class="venue-ownership-decision">
                                <span>Комментарий по решению</span>
                                <p>{!! nl2br(e($claim->decision_reason)) !!}</p>
                            </div>
                        @endif
                    </article>

                    @if($ownership)
                        <section class="venue-ownership-card venue-ownership-card--compact venue-ownership-result-card">
                            <div class="venue-ownership-result-card__head">
                                <div>
                                    <p class="venue-ownership-card__eyebrow">ВЛАДЕНИЕ</p>
                                    <h2>Основания подтверждённого управления</h2>
                                </div>
                                <span class="ownership-admin-status ownership-admin-status--{{ $ownership->status->value }}">{{ $ownership->status->label() }}</span>
                            </div>
                            @if($ownership->status_reason)
                                <p><strong>Причина текущего статуса:</strong> {{ $ownership->status_reason }}</p>
                            @endif

                            @if($ownership->documents->isEmpty())
                                <p class="text-muted">Формальные документы-основания пока не отмечены. Администратор может сохранить сюда файлы из заявки или переписки.</p>
                            @else
                                <div class="venue-ownership-basis-list">
                                    @foreach($ownership->documents as $document)
                                        <div class="venue-ownership-basis-item">
                                            <div>
                                                <a href="{{ route('admin.venue-ownership.documents.download', $document) }}">
                                                    <i class="ti ti-file-certificate"></i> {{ $document->name }}
                                                </a>
                                                <small>{{ $document->type->label() }}@if($document->note) · {{ $document->note }}@endif</small>
                                            </div>
                                            @if($isReviewer)
                                                <form method="POST" action="{{ route('admin.venue-ownership.documents.update', $document) }}" class="venue-ownership-document-edit">
                                                    @csrf
                                                    @method('PATCH')
                                                    <select name="type" class="form-select" required>
                                                        @foreach($documentTypes as $type)
                                                            <option value="{{ $type->value }}" @selected($document->type === $type)>{{ $type->label() }}</option>
                                                        @endforeach
                                                    </select>
                                                    <input class="form-control" type="text" name="note" value="{{ $document->note }}" maxlength="2000" placeholder="Комментарий">
                                                    <button class="btn btn--secondary btn--sm" type="submit">Сохранить</button>
                                                </form>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </section>
                    @endif

                    <section class="venue-ownership-chat" id="ownership-conversation" aria-labelledby="ownership-chat-title">
                        <div class="venue-ownership-chat__head">
                            <div>
                                <p class="venue-ownership-card__eyebrow">ПЕРЕПИСКА</p>
                                <h2 id="ownership-chat-title">Проверка полномочий</h2>
                            </div>
                            <span class="venue-ownership-chat__realtime"><i class="ti ti-bolt"></i> realtime</span>
                        </div>

                        <div class="venue-ownership-chat__messages" data-ownership-messages>
                            <div class="venue-ownership-chat__empty" data-ownership-empty>
                                @if($claim->conversation)
                                    Загружаем переписку…
                                @elseif($isReviewer)
                                    Переписка ещё не начата. Первое сообщение отправляет подтверждающая сторона.
                                @else
                                    Заявка принята. Если для проверки понадобятся дополнительные сведения, администратор напишет здесь.
                                @endif
                            </div>
                        </div>

                        <div class="venue-ownership-chat__waiting" data-ownership-waiting @if($claim->conversation || $isReviewer) hidden @endif>
                            <i class="ti ti-clock"></i>
                            <span>Ожидайте сообщения подтверждающей стороны. После первого сообщения здесь появится форма ответа.</span>
                        </div>

                        <div class="venue-ownership-chat__composer" data-ownership-composer @if(! $claim->conversation && ! $isReviewer) hidden @endif>
                            <form method="POST" action="{{ route('account.venue-ownership.conversation.store', $claim) }}" data-ownership-message-form>
                                @csrf
                                <input type="hidden" name="client_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                <label for="ownershipMessage">Сообщение</label>
                                <textarea id="ownershipMessage" name="body" class="form-control" rows="4" maxlength="4000" required></textarea>
                                <div class="venue-ownership-chat__actions">
                                    <button type="submit" class="btn btn--primary btn--sm">Отправить</button>
                                    <span data-ownership-send-status></span>
                                </div>
                            </form>

                            <form
                                method="POST"
                                action="{{ route('account.venue-ownership.conversation.attach', $claim) }}"
                                enctype="multipart/form-data"
                                class="venue-ownership-attachment-form"
                                data-ownership-attachment-form
                            >
                                @csrf
                                <input type="hidden" name="client_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                <label>
                                    <span>Вложение: JPG, PNG, PDF или TXT до 10 МБ</span>
                                    <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf,.txt" required>
                                </label>
                                <button type="submit" class="btn btn--secondary btn--sm">Прикрепить</button>
                            </form>
                        </div>

                        @if($isReviewer && $ownership && $claim->conversation)
                            @php
                                $attachmentMessages = $claim->conversation->messages->filter(fn ($message) => $message->attachment_path !== null);
                            @endphp
                            @if($attachmentMessages->isNotEmpty())
                                <div class="venue-ownership-chat-evidence">
                                    <p class="venue-ownership-card__eyebrow">ВЛОЖЕНИЯ ПЕРЕПИСКИ</p>
                                    <p>Файлы остаются в истории заявки. Нужные документы можно отдельно зафиксировать как основание владения.</p>
                                    @foreach($attachmentMessages as $message)
                                        <div class="venue-ownership-source-document">
                                            <span><i class="ti ti-paperclip"></i> {{ $message->attachment_name }}</span>
                                            <form method="POST" action="{{ route('admin.venue-ownership.documents.message', [$ownership, $message]) }}" class="venue-ownership-promote-form">
                                                @csrf
                                                <select name="type" class="form-select" required>
                                                    @foreach($documentTypes as $type)
                                                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                                    @endforeach
                                                </select>
                                                <input class="form-control" type="text" name="note" maxlength="2000" placeholder="Комментарий (необязательно)">
                                                <button class="btn btn--secondary btn--sm" type="submit">В основания владения</button>
                                            </form>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                    </section>
                </main>

                <aside class="venue-ownership-claim-side">
                    @if($isReviewer && $claim->status === \App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum::PENDING)
                        <section class="venue-ownership-review-card">
                            <p class="venue-ownership-card__eyebrow">РЕШЕНИЕ</p>
                            <h2>Проверка администратора</h2>
                            <form method="POST" action="{{ route('account.venue-ownership.approve', $claim) }}">
                                @csrf
                                <label>
                                    <span>Комментарий при подтверждении</span>
                                    <textarea name="reason" class="form-control" rows="3" maxlength="2000"></textarea>
                                </label>
                                <button class="btn btn--primary btn--sm" type="submit">Подтвердить управление</button>
                            </form>
                            <form method="POST" action="{{ route('account.venue-ownership.reject', $claim) }}">
                                @csrf
                                <label>
                                    <span>Причина отказа</span>
                                    <textarea name="reason" class="form-control" rows="3" required minlength="5" maxlength="2000"></textarea>
                                </label>
                                <button class="btn btn--secondary btn--sm" type="submit">Отклонить заявку</button>
                            </form>
                            <form method="POST" action="{{ route('account.venue-ownership.reject-and-block', $claim) }}" class="venue-ownership-danger-action">
                                @csrf
                                <label>
                                    <span>Причина блокировки повторных заявок</span>
                                    <textarea name="reason" class="form-control" rows="3" required minlength="5" maxlength="2000"></textarea>
                                </label>
                                <button class="btn btn--secondary btn--sm" type="submit">Отклонить и заблокировать повторную подачу</button>
                                <small>Блокировка относится к этому пользователю и этой площадке, а не только к текущей заявке.</small>
                            </form>
                        </section>
                    @endif

                    @if($isApplicant && $claim->status === \App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum::PENDING)
                        <section class="venue-ownership-review-card venue-ownership-review-card--muted">
                            <h2>Заявка на рассмотрении</h2>
                            <p>Вы можете отменить её, пока решение ещё не принято.</p>
                            <form method="POST" action="{{ route('account.venue-ownership.cancel', $claim) }}">
                                @csrf
                                <button type="submit" class="btn btn--secondary btn--sm">Отменить заявку</button>
                            </form>
                        </section>
                    @endif

                    <a class="venue-ownership-side-link" href="{{ route('venues.management', $claim->venue) }}">
                        <i class="ti ti-building-stadium"></i>
                        <span>Управление площадкой</span>
                        <i class="ti ti-arrow-right"></i>
                    </a>
                </aside>
            </div>
        </div>
    </section>
@endsection
