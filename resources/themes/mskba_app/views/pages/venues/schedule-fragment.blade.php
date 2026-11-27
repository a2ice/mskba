@php
    $bookableCourtIds = collect($venue->courtSchedules)->where('bookingEnabled', true)->pluck('id')->all();
    $isOutdoorCourt = $venue->isCourtPage && $venue->selectedCourt['environment'] === 'outdoor';
@endphp
            <section class="panel app-venue-block" id="app-venue-schedule"@if(!empty($scheduleRefresh))
                    data-venue-schedule-refresh
                    data-venue-id="{{ $venue->id }}"
                    data-refresh-court="{{ $venue->isCourtPage ? $venue->selectedCourt['id'] : '' }}"
                    data-refresh-status-url="{{ route('venues.schedule.refresh-status', $venue->id) }}"
                    data-refresh-states="{{ json_encode((object) $scheduleRefresh) }}"
                @endif>
                <header class="app-venue-block__header"><h2>Расписание</h2>
                    @if(count($bookableCourtIds))
                    <button type="button" class="button secondary" data-venue-rental-open>{{ $venue->isCourtPage ? ($isOutdoorCourt ? 'Арендовать корт' : 'Арендовать зал') : 'Арендовать площадку' }}</button>
                    @endif
                </header>
                @foreach($venue->courtSchedules as $scheduleGroup)
                    @php
                        $refreshState = $scheduleRefresh[$scheduleGroup['id']] ?? null;
                        $refreshing = ($refreshState['state'] ?? null) === 'updating';
                    @endphp
                    <div class="app-venue-schedule__court" data-venue-schedule-court="{{ $scheduleGroup['id'] }}"
                        data-venue-refresh-scope="{{ $scheduleGroup['id'] }}"
                        data-court-name="{{ $scheduleGroup['name'] }}">
                        @if(!$venue->isCourtPage || $refreshState)
                            <div class="app-venue-schedule__court-header">
                                <div class="venue-schedule-refresh-heading">
                                    @unless($venue->isCourtPage)
                                        <h3><a class="app-venue-court-link" href="{{ route('venues.courts.show', [$venue->routeIdentifier(), $scheduleGroup['routeIdentifier']]) }}">{{ $scheduleGroup['name'] }}</a></h3>
                                    @endunless
                                    @include('components.venue-schedule-refresh-status', ['refreshState' => $refreshState])
                                </div>
                            </div>
                        @endif
                        @if(!($scheduleGroup['bookingEnabled'] ?? false))
                            @include('venues.partials.informational-hours', ['scheduleGroup' => $scheduleGroup])
                        @elseif(count($scheduleGroup['days']))
                            <div class="app-venue-schedule" role="group" aria-label="Дни и часы работы зала {{ $scheduleGroup['name'] }}"
                                data-venue-refresh-days aria-busy="{{ $refreshing ? 'true' : 'false' }}">
                                @foreach($scheduleGroup['days'] as $day)
                                    @php
                                        $occupancy = $day['occupancyPercent'] ?? null;
                                        $isWeekend = in_array($day['weekday'] ?? '', ['Сб', 'Вс'], true);
                                    @endphp
                                    <div class="app-venue-schedule__entry" data-venue-day-entry data-day-date="{{ $day['date'] }}">
                                        <button type="button" class="app-venue-schedule__day app-venue-schedule__day--interactive"
                                            data-venue-day-open data-court-id="{{ $scheduleGroup['id'] }}"
                                            @if($refreshing) disabled data-venue-refresh-disabled @endif
                                            data-day-date="{{ $day['date'] }}"
                                            aria-label="{{ $scheduleGroup['name'] }}: расписание и бронирование {{ $day['label'] ?? $day['date'] }}">
                                            <strong @class(['app-venue-schedule__date', 'is-weekend' => $isWeekend])>{{ $day['label'] ?? $day['date'] }}</strong>
                                            @if(count($day['intervals'] ?? []))
                                                @php
                                                    // Cards show only the overall opening span, not each break.
                                                    // Actual slot availability remains in the day booking dialog.
                                                    $firstHours = $day['intervals'][0];
                                                    $lastHours = $day['intervals'][array_key_last($day['intervals'])];
                                                @endphp
                                                <p class="app-venue-schedule__hours" aria-label="Часы работы">
                                                    <svg aria-hidden="true"><use href="#clock"/></svg>
                                                    <span>{{ $firstHours['startsAt'] }}–{{ in_array($lastHours['endsAt'], ['23:59', '24:00'], true) ? '00:00' : $lastHours['endsAt'] }}</span>
                                                </p>
                                            @else
                                                <p class="app-venue-schedule__closed">Не работает</p>
                                            @endif
                                        </button>
                                        <div @class(['app-venue-schedule__occupancy', 'is-unknown' => $occupancy === null])
                                            data-day-occupancy role="img"
                                            aria-label="{{ $occupancy === null ? 'Нет данных о занятости' : 'Занято '.$occupancy.'%' }}"
                                            title="{{ $occupancy === null ? 'Нет данных о занятости' : 'Занято '.$occupancy.'%' }}">
                                            <span class="app-venue-schedule__occupancy-booked" data-day-occupancy-booked
                                                style="width: {{ (int) ($occupancy ?? 0) }}%"></span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="muted">Расписание для этого зала пока не установлено.</p>
                        @endif
                    </div>
                @endforeach
            </section>
