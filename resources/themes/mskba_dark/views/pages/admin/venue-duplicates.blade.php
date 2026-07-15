@php $title = 'Дубли площадок'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Кандидаты на объединение площадок по названию и адресу.',
])

@section('section-content')
    @php
        $creatorLabel = static function ($venue): string {
            $actor = $venue?->creatorActor;

            if ($actor === null) {
                return 'Неизвестно';
            }

            if ($actor->user !== null) {
                return $actor->user->username ?: 'user #' . $actor->user->id;
            }

            if ($actor->fingerprint !== null) {
                return 'guest #' . $actor->fingerprint->id;
            }

            return $actor->type->value . ' actor #' . $actor->id;
        };

        $venueRows = [];
        $adjacency = [];

        foreach ($duplicates as $duplicate) {
            $firstVenue = $duplicate->venue;
            $secondVenue = $duplicate->duplicateVenue;

            if (!$firstVenue || !$secondVenue) {
                continue;
            }

            foreach ([$firstVenue, $secondVenue] as $venue) {
                $venueRows[$venue->id] ??= [
                    'venue' => $venue,
                    'matched_by' => [],
                    'statuses' => [],
                    'pending' => false,
                    'group_id' => $venue->id,
                    'group_has_confirmed' => false,
                    'group_canonical_id' => null,
                    'group_size' => 1,
                ];
            }

            $venueRows[$firstVenue->id]['matched_by'][$duplicate->matched_by->value] = $duplicate->matched_by->label();
            $venueRows[$secondVenue->id]['matched_by'][$duplicate->matched_by->value] = $duplicate->matched_by->label();
            $venueRows[$firstVenue->id]['statuses'][$duplicate->status->value] = $duplicate->status->label();
            $venueRows[$secondVenue->id]['statuses'][$duplicate->status->value] = $duplicate->status->label();

            if ($duplicate->status === \App\Modules\Venue\Domain\Enums\VenueDuplicateStatusEnum::PENDING) {
                $venueRows[$firstVenue->id]['pending'] = true;
                $venueRows[$secondVenue->id]['pending'] = true;
            }

            $adjacency[$firstVenue->id][] = $secondVenue->id;
            $adjacency[$secondVenue->id][] = $firstVenue->id;
        }

        $visitedVenueIds = [];

        foreach (array_keys($venueRows) as $venueId) {
            if (isset($visitedVenueIds[$venueId])) {
                continue;
            }

            $stack = [$venueId];
            $componentVenueIds = [];

            while ($stack !== []) {
                $currentVenueId = array_pop($stack);

                if (isset($visitedVenueIds[$currentVenueId])) {
                    continue;
                }

                $visitedVenueIds[$currentVenueId] = true;
                $componentVenueIds[] = $currentVenueId;

                foreach ($adjacency[$currentVenueId] ?? [] as $nextVenueId) {
                    if (!isset($visitedVenueIds[$nextVenueId])) {
                        $stack[] = $nextVenueId;
                    }
                }
            }

            $groupId = min($componentVenueIds);
            $groupHasConfirmed = collect($componentVenueIds)
                ->contains(fn (int $componentVenueId): bool => $venueRows[$componentVenueId]['venue']->status === \App\Modules\Venue\Domain\Enums\VenueStatusEnum::CONFIRMED);
            $groupCanonicalId = collect($componentVenueIds)
                ->map(fn (int $componentVenueId): ?int => $venueRows[$componentVenueId]['venue']->canonical_venue_id)
                ->filter(fn (?int $canonicalVenueId): bool => $canonicalVenueId !== null)
                ->unique()
                ->first();
            $groupSize = count($componentVenueIds);

            foreach ($componentVenueIds as $componentVenueId) {
                $venueRows[$componentVenueId]['group_id'] = $groupId;
                $venueRows[$componentVenueId]['group_has_confirmed'] = $groupHasConfirmed;
                $venueRows[$componentVenueId]['group_canonical_id'] = $groupCanonicalId;
                $venueRows[$componentVenueId]['group_size'] = $groupSize;
            }
        }

        $venueRows = collect($venueRows)
            ->sortBy(fn (array $row): array => [$row['group_id'], $row['venue']->id])
            ->values();
    @endphp

    @if(session('success'))
        <div class="admin-empty">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="admin-empty">{{ session('error') }}</div>
    @endif

    <form method="GET" action="{{ route('admin.venues.duplicates') }}" class="admin-filter">
        <label class="admin-filter__field">
            <span class="admin-filter__label">Статус</span>
            <select class="form-select" name="status">
                <option value="">Все</option>
                @foreach($statuses as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </label>
        <div class="admin-filter__actions">
            <button type="submit" class="btn btn--primary btn--sm">Фильтр</button>
            <a href="{{ route('admin.venues.duplicates') }}" class="btn btn--secondary btn--sm">Сброс</a>
            <button
                type="button"
                class="btn btn--primary btn--sm"
                data-venue-duplicates-merge-open
                disabled
            >
                Объединить
            </button>
        </div>
    </form>

    @if($venueRows->count() === 0)
        <div class="admin-empty">Кандидатов на объединение пока нет.</div>
    @else
        <div class="admin-table-wrap">
            <table class="admin-table admin-table--venue-duplicates">
                <thead>
                    <tr>
                        <th class="admin-table__check-cell"></th>
                        <th class="admin-table__canonical-cell">Главная</th>
                        <th>Название</th>
                        <th>Адрес</th>
                        <th>Кто создал</th>
                        <th>Когда добавлено</th>
                        <th>Совпадение</th>
                        <th>Статус</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($venueRows as $row)
                        @php
                            $venue = $row['venue'];
                            $isSelectable = $row['pending']
                                || ($row['group_size'] > 1 && !$row['group_has_confirmed'])
                                || ($row['group_has_confirmed'] && $venue->status === \App\Modules\Venue\Domain\Enums\VenueStatusEnum::CONFIRMED);
                        @endphp
                        <tr>
                            <td class="admin-table__check-cell">
                                @if($isSelectable)
                                    <span class="admin-row-select">
                                        <label class="admin-row-check">
                                            <input
                                                type="checkbox"
                                                value="{{ $venue->id }}"
                                                data-venue-duplicate-option
                                                data-group-id="{{ $row['group_id'] }}"
                                                data-venue-id="{{ $venue->id }}"
                                                data-venue-title="#{{ $venue->id }} · {{ $venue->name }}"
                                                data-venue-address="{{ $venue->raw_address ?? 'Адрес не указан' }}"
                                                data-venue-creator="{{ $creatorLabel($venue) }}"
                                                data-venue-created-at="{{ $venue->created_at?->format('d.m.Y H:i') ?? '—' }}"
                                                data-venue-status="{{ $venue->status->label() }}"
                                                data-venue-type="{{ $venue->type->label() }}"
                                                data-venue-description="{{ $venue->description ?: 'Описание не заполнено' }}"
                                                data-group-has-confirmed="{{ $row['group_has_confirmed'] ? '1' : '0' }}"
                                                data-is-confirmed="{{ $venue->status === \App\Modules\Venue\Domain\Enums\VenueStatusEnum::CONFIRMED ? '1' : '0' }}"
                                            >
                                            <span></span>
                                        </label>
                                    </span>
                                @endif
                            </td>
                            <td class="admin-table__canonical-cell">
                                @if($row['group_canonical_id'] !== null && (int) $row['group_canonical_id'] === (int) $venue->id)
                                    <span class="admin-canonical-mark" aria-label="Главная площадка"></span>
                                @else
                                    <span class="admin-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <button
                                    type="button"
                                    class="admin-link-button"
                                    data-venue-duplicates-preview-open
                                    data-venue-title="#{{ $venue->id }} · {{ $venue->name }}"
                                    data-venue-address="{{ $venue->raw_address ?? 'Адрес не указан' }}"
                                    data-venue-creator="{{ $creatorLabel($venue) }}"
                                    data-venue-created-at="{{ $venue->created_at?->format('d.m.Y H:i') ?? '—' }}"
                                    data-venue-status="{{ $venue->status->label() }}"
                                    data-venue-type="{{ $venue->type->label() }}"
                                    data-venue-description="{{ $venue->description ?: 'Описание не заполнено' }}"
                                >
                                    {{ $venue->name }}
                                </button>
                                <div class="admin-muted">#{{ $venue->id }} · {{ $venue->alias }}</div>
                            </td>
                            <td>{{ $venue->raw_address ?? 'Адрес не указан' }}</td>
                            <td>{{ $creatorLabel($venue) }}</td>
                            <td>{{ $venue->created_at?->format('d.m.Y H:i') ?? '—' }}</td>
                            <td>
                                @foreach($row['matched_by'] as $matchedByLabel)
                                    <span class="admin-badge">{{ $matchedByLabel }}</span>
                                @endforeach
                            </td>
                            <td>
                                @foreach($row['statuses'] as $statusLabel)
                                    <span class="admin-badge">{{ $statusLabel }}</span>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @include('theme::partials.admin.pagination', ['paginator' => $duplicates])

        <div class="admin-action-modal" data-venue-duplicates-merge-modal hidden>
            <div class="admin-action-modal__backdrop" data-venue-duplicates-merge-close></div>
            <section class="admin-action-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="venue-duplicates-merge-title">
                <button type="button" class="admin-action-modal__close" data-venue-duplicates-merge-close aria-label="Закрыть"></button>
                <h3 id="venue-duplicates-merge-title" class="admin-action-modal__title">Объединить площадки</h3>

                <form method="POST" action="{{ route('admin.venues.duplicates.merge-batch') }}" data-venue-duplicates-merge-form>
                    @csrf
                    <input type="hidden" name="canonical_venue_id" data-venue-duplicates-canonical-id>
                    <div data-venue-duplicates-selected-inputs></div>

                    <p class="admin-action-modal__description">
                        Выберите, какая площадка останется главной. Остальные выбранные площадки будут помечены дублями.
                    </p>

                    <div class="admin-merge-choice" data-venue-duplicates-canonical-options>
                    </div>

                    <div class="admin-action-modal__actions">
                        <button type="button" class="btn btn--secondary btn--sm" data-venue-duplicates-merge-close>Отмена</button>
                        <button type="submit" class="btn btn--primary btn--sm">Объединить</button>
                    </div>
                </form>
            </section>
        </div>

        <div class="admin-action-modal" data-venue-duplicates-preview-modal hidden>
            <div class="admin-action-modal__backdrop" data-venue-duplicates-preview-close></div>
            <section class="admin-action-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="venue-duplicates-preview-title">
                <button type="button" class="admin-action-modal__close" data-venue-duplicates-preview-close aria-label="Закрыть"></button>
                <h3 id="venue-duplicates-preview-title" class="admin-action-modal__title" data-venue-preview-title></h3>

                <dl class="admin-preview-list">
                    <div>
                        <dt>Статус</dt>
                        <dd data-venue-preview-status></dd>
                    </div>
                    <div>
                        <dt>Тип</dt>
                        <dd data-venue-preview-type></dd>
                    </div>
                    <div>
                        <dt>Адрес</dt>
                        <dd data-venue-preview-address></dd>
                    </div>
                    <div>
                        <dt>Создал</dt>
                        <dd data-venue-preview-creator></dd>
                    </div>
                    <div>
                        <dt>Добавлено</dt>
                        <dd data-venue-preview-created-at></dd>
                    </div>
                    <div>
                        <dt>Описание</dt>
                        <dd data-venue-preview-description></dd>
                    </div>
                </dl>
            </section>
        </div>
    @endif
@endsection
