@php
    $shortWeekdays = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];
    $shortMonths = [1 => 'янв', 2 => 'фев', 3 => 'мар', 4 => 'апр', 5 => 'мая', 6 => 'июн', 7 => 'июл', 8 => 'авг', 9 => 'сен', 10 => 'окт', 11 => 'ноя', 12 => 'дек'];
    $placeholderDateLabel = static fn ($date) => $date->format('d') . ' ' . $shortMonths[(int) $date->month];
    $placeholderWeekdayLabel = static fn ($date) => $shortWeekdays[(int) $date->dayOfWeekIso];
    $legacyBookableCourtIds = collect($venue->courtSchedules)->where('bookingEnabled', true)->pluck('id')->all();
    $legacyShowOccupancy = $legacyBookableCourtIds !== []
        && ($venue->isCourtPage || count($venue->courtSchedules) === 1);
@endphp
            <section id="schedule" class="venue-show-section"
                @if(!empty($scheduleRefresh))
                    data-venue-schedule-refresh
                    data-venue-id="{{ $venue->id }}"
                    data-refresh-court="{{ $venue->isCourtPage ? $venue->selectedCourt['id'] : '' }}"
                    data-refresh-status-url="{{ route('venues.schedule.refresh-status', $venue->id) }}"
                    data-refresh-states="{{ json_encode((object) $scheduleRefresh) }}"
                @endif>
                <div class="venue-show-section__heading">
                    <div>
                        <h2>Расписание</h2>
                        <p class="venue-show-section__caption">{{ $venue->isCourtPage && $legacyBookableCourtIds !== []
                            ? 'Нажмите на день, чтобы посмотреть интервалы.'
                            : 'Режим работы залов и кортов с учётом исключений.' }}</p>
                    </div>
                    <div class="venue-management-actions">
                        @if(isset($rentalUrl) && $legacyBookableCourtIds !== [])
                            <a href="{{ $rentalUrl }}" class="btn btn--primary btn--sm">Рассчитать аренду</a>
                        @endif
                        <span class="venue-section-state">{{ $legacyShowOccupancy ? '14 дней' : 'Недельный график' }}</span>
                    </div>
                </div>

                @if(! $venue->isCourtPage || $legacyBookableCourtIds === [])
                    @forelse($venue->courtSchedules as $scheduleGroup)
                        <div class="venue-show-section__court">
                            @unless($venue->isCourtPage)
                                <h3>{{ $scheduleGroup['name'] }}</h3>
                            @endunless
                            @include('components.venue-schedule-refresh-status', ['refreshState' => $scheduleRefresh[$scheduleGroup['id']] ?? null])
                            @if($scheduleGroup['bookingEnabled'])
                                <p>Онлайн-бронирование доступно.</p>
                                <a href="{{ route('venues.courts.show', [$venue->routeIdentifier(), $scheduleGroup['routeIdentifier']]) }}" class="btn btn--secondary btn--sm">Расписание и аренда</a>
                            @else
                                <p>Онлайн-бронирование через портал недоступно.</p>
                                @include('venues.partials.informational-hours', ['scheduleGroup' => $scheduleGroup, 'legacyHours' => true])
                            @endif
                        </div>
                    @empty
                        <p>Режим работы не указан.</p>
                    @endforelse
                @elseif($venue->about->scheduleDays !== [])
                    @include('components.venue-schedule-refresh-status', ['refreshState' => $scheduleRefresh[$venue->selectedCourt['id']] ?? null])
                    <div class="venue-schedule-preview">
                        @foreach($venue->about->scheduleDays as $day)
                            <button
                                type="button"
                                @class([
                                'venue-schedule-preview__day',
                                'is-today' => $day['isToday'],
                                'is-closed' => $day['isClosed'],
                                ])
                                data-venue-day-card
                                data-date="{{ $day['date'] }}"
                                data-label="{{ $day['label'] }}"
                                data-weekday="{{ $day['weekday'] }}"
                                data-is-today="{{ $day['isToday'] ? '1' : '0' }}"
                                data-is-closed="{{ $day['isClosed'] ? '1' : '0' }}"
                                data-intervals='@json($day['intervals'])'
                            >
                                <div>
                                    <span>{{ $day['label'] }}</span>
                                    <small>{{ $day['weekday'] }}</small>
                                </div>

                                @if($day['isClosed'])
                                    <strong>Закрыто</strong>
                                @else
                                    <div class="venue-schedule-preview__intervals">
                                        @foreach($day['intervals'] as $interval)
                                            <strong>{{ $interval['startsAt'] === '24:00' ? '00:00' : $interval['startsAt'] }}-{{ in_array($interval['endsAt'], ['23:59', '24:00'], true) ? '00:00' : $interval['endsAt'] }}</strong>
                                        @endforeach
                                    </div>
                                @endif
                            </button>
                        @endforeach
                    </div>

                    <div class="venue-schedule-legend" aria-label="Легенда расписания">
                        <span><i class="venue-schedule-legend__dot venue-schedule-legend__dot--open"></i>Есть интервалы</span>
                        <span><i class="venue-schedule-legend__dot venue-schedule-legend__dot--closed"></i>Закрыто</span>
                        <span><i class="venue-schedule-legend__dot venue-schedule-legend__dot--today"></i>Сегодня</span>
                    </div>

                    <div class="venue-day-modal" data-venue-day-modal hidden>
                        <div class="venue-day-modal__backdrop" data-venue-day-modal-close></div>
                        <section class="venue-day-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="venue-day-modal-title">
                            <div class="venue-day-modal__head">
                                <div>
                                    <p class="venue-day-modal__eyebrow" data-venue-day-modal-weekday></p>
                                    <h3 id="venue-day-modal-title" data-venue-day-modal-title>День расписания</h3>
                                </div>
                                <button type="button" class="venue-day-modal__close" data-venue-day-modal-close aria-label="Закрыть">
                                    <i class="ti ti-x"></i>
                                </button>
                            </div>

                            <div class="venue-day-modal__body">
                                <div data-venue-day-modal-intervals></div>
                                <div class="venue-day-modal__notice">
                                    Бронирование появится здесь после подключения модуля событий и заявок.
                                </div>
                            </div>
                        </section>
                    </div>
                @else
                    <div class="venue-schedule-preview">
                        @for($day = 0; $day < 7; $day++)
                            @php($placeholderDate = now()->addDays($day))
                            <div class="venue-schedule-preview__day">
                                <div>
                                    <span>{{ $placeholderDateLabel($placeholderDate) }}</span>
                                    <small>{{ $placeholderWeekdayLabel($placeholderDate) }}</small>
                                </div>
                                <strong>Режим работы не указан</strong>
                            </div>
                        @endfor
                    </div>
                @endif
            </section>
