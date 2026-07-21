@php $title = 'Площадки'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Каталог площадок, статусы и базовая модерационная сводка.',
])

@section('section-content')
    @php $showDeleted = ($filters['deleted'] ?? '') === '1'; @endphp

    <form method="GET" action="{{ route('admin.venues') }}" class="admin-filter">
        <label class="admin-filter__field">
            <span class="admin-filter__label">Поиск</span>
            <input class="form-control" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Название, alias, адрес">
        </label>
        <label class="admin-filter__field">
            <span class="admin-filter__label">Статус</span>
            <select class="form-select" name="status">
                <option value="">Все</option>
                <option value="pending_moderation" @selected(($filters['status'] ?? '') === 'pending_moderation')>На рассмотрении</option>
                @foreach($statuses as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </label>
        <label class="admin-filter__field">
            <span class="admin-filter__label">Тип</span>
            <select class="form-select" name="type">
                <option value="">Все</option>
                @foreach($types as $type)
                    <option value="{{ $type->value }}" @selected(($filters['type'] ?? '') === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </select>
        </label>
        <label class="admin-filter__field">
            <span class="admin-filter__label">Удалённые</span>
            <select class="form-select" name="deleted">
                <option value="" @selected(($filters['deleted'] ?? '') !== '1')>Активные</option>
                <option value="1" @selected(($filters['deleted'] ?? '') === '1')>Удалённые</option>
            </select>
        </label>
        <div class="admin-filter__actions">
            <button type="submit" class="btn btn--primary btn--sm">Фильтр</button>
            <a href="{{ route('admin.venues') }}" class="btn btn--secondary btn--sm">Сброс</a>
        </div>
    </form>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @if($venues->count() === 0)
        <div class="admin-empty">Площадки не найдены.</div>
    @else
        <form
            method="POST"
            action="{{ $showDeleted ? route('admin.venues.bulk-restore') : route('admin.venues.bulk-delete') }}"
            data-admin-venue-bulk-form
            @if(! $showDeleted) data-confirm-message="Вы уверены что хотите удалить выбранные площадки?" @endif
        >
            @csrf
            <input type="hidden" name="message" data-admin-venue-bulk-message>

            <div class="admin-table-toolbar">
                @unless($showDeleted)
                    <button type="submit" formaction="{{ route('admin.venues.bulk-block') }}" class="btn btn--danger btn--sm" data-admin-venue-bulk-submit data-admin-venue-bulk-action="block" disabled>Заблокировать</button>
                    <button type="submit" formaction="{{ route('admin.venues.bulk-unblock') }}" class="btn btn--secondary btn--sm" data-admin-venue-bulk-submit data-admin-venue-bulk-action="unblock" disabled>Снять блокировку</button>
                @endunless
                <button
                    type="submit"
                    class="btn {{ $showDeleted ? 'btn--success' : 'btn--danger' }} btn--sm"
                    data-admin-venue-bulk-submit
                    data-admin-venue-bulk-action="{{ $showDeleted ? 'restore' : 'delete' }}"
                    disabled
                >{{ $showDeleted ? 'Восстановить' : 'Удалить' }}</button>
            </div>

            <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="admin-table__check-cell">
                            <input type="checkbox" aria-label="Выбрать все площадки" data-admin-venue-select-all>
                        </th>
                        <th>ID</th>
                        <th>Дубли</th>
                        <th>Название</th>
                        <th>Alias</th>
                        <th>Статус</th>
                        <th>Тип</th>
                        <th>Создатель</th>
                        <th>Создана</th>
                        @if(auth()->user()?->hasSystemRole(\App\Modules\Identity\Domain\Enums\UserSystemRoleEnum::SUPERADMIN))
                            <th>Действия</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($venues as $venue)
                        @php
                            $pendingDuplicateIds = $venue->duplicateCandidates
                                ->pluck('duplicate_venue_id')
                                ->merge($venue->duplicateOfCandidates->pluck('venue_id'))
                                ->unique()
                                ->sort()
                                ->values();
                            $latestModerationRequest = $venue->moderationRequests->sortByDesc('id')->first();
                            $pendingModerationRequest = $venue->moderationRequests
                                ->first(fn ($request) => $request->status === \App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum::PENDING);
                            $statusModalId = 'venue-status-'.$venue->id;
                        @endphp
                        <tr>
                            <td class="admin-table__check-cell">
                                <input
                                    type="checkbox"
                                    name="venue_ids[]"
                                    value="{{ $venue->id }}"
                                    aria-label="Выбрать площадку {{ $venue->name }}"
                                    data-admin-venue-select
                                    data-venue-status="{{ $venue->status->value }}"
                                >
                            </td>
                            <td>{{ $venue->id }}</td>
                            <td>
                                @if($venue->canonicalVenue)
                                    <span class="admin-badge">Дубль #{{ $venue->canonicalVenue->id }}</span>
                                    <div class="admin-muted">{{ $venue->canonicalVenue->name }}</div>
                                @elseif($venue->duplicate_venues_count > 0)
                                    <span class="admin-badge">Главная</span>
                                    <div class="admin-muted">Дублей: {{ $venue->duplicate_venues_count }}</div>
                                @elseif($pendingDuplicateIds->isNotEmpty())
                                    <span class="admin-badge">#{{ $pendingDuplicateIds->implode(', #') }}</span>
                                @else
                                    <span class="admin-muted">—</span>
                                @endif
                            </td>
                            <td>{{ $venue->name }}</td>
                            <td>{{ $venue->alias }}</td>
                            <td>
                                <button
                                    type="button"
                                    class="admin-badge admin-badge--button"
                                    data-admin-action-modal-open="{{ $statusModalId }}"
                                >
                                    {{ $venue->trashed() ? 'Удалена' : ($pendingModerationRequest?->status->label() ?? $venue->status->label()) }}
                                </button>
                            </td>
                            <td>{{ $venue->type->label() }}</td>
                            <td>{{ $venue->creatorActor?->user?->username ?? '—' }}</td>
                            <td>{{ $venue->created_at?->format('d.m.Y H:i') }}</td>
                            @if(auth()->user()?->hasSystemRole(\App\Modules\Identity\Domain\Enums\UserSystemRoleEnum::SUPERADMIN))
                                <td>
                                    @unless($venue->trashed())
                                        <a href="{{ route('admin.venues.edit', $venue) }}" class="btn btn--secondary btn--sm">Редактировать</a>
                                    @endunless
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        </form>

        @foreach($venues as $venue)
            @php
                $pendingModerationRequest = $venue->moderationRequests
                    ->first(fn ($request) => $request->status === \App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum::PENDING);
                $moderationMessages = $venue->moderationRequests
                    ->sortBy('id')
                    ->flatMap(fn ($request) => $request->messages
                        ->sortByDesc('id')
                        ->map(fn ($message) => [
                            'message' => $message,
                            'is_owner' => $message->sender_id === $request->submitted_by_actor_id,
                            'sender_label' => $message->sender_id === $request->submitted_by_actor_id
                                ? 'От пользователя'
                                : 'От модератора',
                            'sender_username' => $message->sender?->user?->username
                                ?? $message->sender?->user?->email
                                ?? 'гость',
                        ]))
                    ->sortByDesc(fn ($item) => $item['message']->id)
                    ->values();
            @endphp

            <div class="admin-action-modal" data-admin-action-modal="venue-status-{{ $venue->id }}" hidden>
                <div class="admin-action-modal__backdrop" data-admin-action-modal-close></div>
                <section class="admin-action-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="venue-status-title-{{ $venue->id }}">
                    <button type="button" class="admin-action-modal__close" data-admin-action-modal-close aria-label="Закрыть"></button>

                    <p class="admin-kicker">Статус площадки</p>
                    <h3 id="venue-status-title-{{ $venue->id }}" class="admin-action-modal__title">
                        {{ $venue->name }}
                    </h3>

                    <p class="admin-action-modal__description">
                        Сейчас: <strong>{{ $venue->trashed() ? 'Удалена' : ($pendingModerationRequest?->status->label() ?? $venue->status->label()) }}</strong>
                    </p>

                    @if($pendingModerationRequest?->venueRevision)
                        @php
                            $revisionDiff = $pendingModerationRequest->revision_diff ?? [
                                'fields' => [],
                                'gallery_changed' => false,
                                'gallery_summary' => '',
                                'before_gallery' => [],
                                'after_gallery' => [],
                                'has_changes' => false,
                            ];
                        @endphp
                        <section class="admin-moderation-revision" aria-label="Предлагаемые изменения">
                            <h4>Что изменится</h4>

                            @if(! $revisionDiff['has_changes'])
                                <p class="admin-muted">В заявке нет отличий от опубликованной версии.</p>
                            @else
                                @if($revisionDiff['fields'] !== [])
                                    <div class="admin-moderation-diff" role="table" aria-label="Изменённые поля">
                                        <div class="admin-moderation-diff__header" role="row">
                                            <span role="columnheader">Поле</span>
                                            <span role="columnheader">Было</span>
                                            <span role="columnheader">Станет</span>
                                        </div>
                                        @foreach($revisionDiff['fields'] as $field)
                                            <div class="admin-moderation-diff__row" role="row">
                                                <strong role="cell">{{ $field['label'] }}</strong>
                                                <span class="admin-moderation-diff__before" role="cell">{{ $field['before'] }}</span>
                                                <span class="admin-moderation-diff__after" role="cell">{{ $field['after'] }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                @if($revisionDiff['gallery_changed'])
                                    <div class="admin-moderation-gallery-diff">
                                        <h5>Фотографии</h5>
                                        <p>{{ $revisionDiff['gallery_summary'] }}</p>
                                        <div class="admin-moderation-gallery-diff__columns">
                                            <div>
                                                <strong>Было</strong>
                                                <div class="admin-moderation-revision__gallery">
                                                    @forelse($revisionDiff['before_gallery'] as $photo)
                                                        <figure @class(['is-removed' => $photo['state'] === 'removed'])>
                                                            <img src="{{ $photo['url'] }}" alt="" @class(['is-featured' => $photo['is_featured']])>
                                                            @if($photo['state'] === 'removed')<figcaption>Удаляется</figcaption>@endif
                                                        </figure>
                                                    @empty
                                                        <span class="admin-muted">Нет фотографий</span>
                                                    @endforelse
                                                </div>
                                            </div>
                                            <div>
                                                <strong>Станет</strong>
                                                <div class="admin-moderation-revision__gallery">
                                                    @forelse($revisionDiff['after_gallery'] as $photo)
                                                        <figure @class(['is-added' => $photo['state'] === 'added'])>
                                                            <img src="{{ $photo['url'] }}" alt="" @class(['is-featured' => $photo['is_featured']])>
                                                            @if($photo['state'] === 'added')<figcaption>Новая</figcaption>@endif
                                                        </figure>
                                                    @empty
                                                        <span class="admin-muted">Нет фотографий</span>
                                                    @endforelse
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endif
                        </section>
                    @endif

                    @unless($venue->trashed())
                    <div @class(['admin-moderation-workspace', 'admin-moderation-workspace--single' => $moderationMessages->isEmpty()])>
                        @if($moderationMessages->isNotEmpty())
                            <section class="admin-moderation-history" aria-label="История заявки модерации">
                                @forelse($moderationMessages as $historyItem)
                                    <article @class([
                                        'admin-moderation-history__item',
                                        'admin-moderation-history__item--owner' => $historyItem['is_owner'],
                                        'admin-moderation-history__item--moderator' => ! $historyItem['is_owner'],
                                    ])>
                                        <div class="admin-moderation-history__meta">
                                            {{ $historyItem['sender_label'] }} ({{ $historyItem['sender_username'] }}) · {{ $historyItem['message']->created_at?->format('d.m.Y H:i') ?? '—' }}
                                        </div>
                                        <p>{{ $historyItem['message']->message }}</p>
                                    </article>
                                @empty
                                    <div class="admin-muted">Истории сообщений пока нет.</div>
                                @endforelse
                            </section>
                        @endif

                        @if($pendingModerationRequest)
                            <label class="admin-moderation-form__field">
                                <span>Комментарий</span>
                                <textarea class="form-control" rows="3" data-admin-action-comment>{{ old('message') }}</textarea>
                            </label>
                        @endif
                    </div>

                    @if($pendingModerationRequest)
                        <div class="admin-moderation-actions admin-moderation-actions--single-row">
                            <form
                                method="POST"
                                action="{{ route('admin.venues.moderation.approve', $pendingModerationRequest) }}"
                                data-admin-confirm="{{ $pendingModerationRequest->venueRevision ? 'Применить предложенные изменения?' : 'Вы уверены, что хотите подтвердить площадку?' }}"
                            >
                                @csrf
                                <input type="hidden" name="message" data-admin-action-message-input>
                                <button type="submit" class="btn btn--success btn--sm" data-admin-action-comment-copy>{{ $pendingModerationRequest->venueRevision ? 'Применить изменения' : 'Подтвердить' }}</button>
                            </form>

                            <form
                                method="POST"
                                action="{{ route('admin.venues.moderation.reject', $pendingModerationRequest) }}"
                                data-admin-confirm="Вы уверены, что хотите отклонить заявку?"
                            >
                                @csrf
                                <input type="hidden" name="message" data-admin-action-message-input>
                                <button type="submit" class="btn btn--secondary btn--sm" data-admin-action-comment-submit>Отклонить</button>
                            </form>

                        </div>
                    @endif
                    @else
                        <div class="admin-muted">Для восстановления выберите площадку в таблице.</div>
                    @endunless
                </section>
            </div>
        @endforeach

        @include('theme::partials.admin.pagination', ['paginator' => $venues])
    @endif
@endsection
