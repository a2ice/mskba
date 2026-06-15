@php
    $venue = isset($venue) ? $venue : null;
    $title = $venue ? 'Расписание: ' . $venue->name : 'Расписание площадки';
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
    @if(isset($error))
        <div class="alert alert-danger">
            {{ $error['message'] }}
        </div>
    @endif

    @if(session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger">
            {{ session('error') }}
        </div>
    @endif

    @if($venue !== null)
        <form method="POST" action="{{ route('account.venues.schedule.update', $venue->alias) }}" class="account-venue-schedule">
            @csrf
            @method('PUT')

            <div class="field mb-4">
                <label for="venueScheduleTimezone" class="form-label">Часовой пояс</label>
                <input
                    id="venueScheduleTimezone"
                    type="text"
                    name="timezone"
                    class="form-control @error('timezone') is-invalid @enderror"
                    value="{{ old('timezone', $venue->schedule?->timezone ?? 'Europe/Moscow') }}"
                    required
                >
                @error('timezone')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="account-venue-schedule__bulk-actions mb-4">
                <button type="button" class="btn btn--secondary btn--sm" data-venue-schedule-apply-all>
                    Применить первый заполненный день ко всем
                </button>
                <button type="button" class="btn btn--secondary btn--sm" data-venue-schedule-reset-all>
                    Сбросить у всех
                </button>
            </div>

            <div class="account-venue-schedule__days">
                @foreach($weekDays as $dayOfWeek => $dayLabel)
                    <section class="account-venue-schedule-day" data-venue-schedule-day>
                        <div class="account-venue-schedule-day__head">
                            <h2>{{ $dayLabel }}</h2>
                            <span data-venue-schedule-day-state>Выходной</span>
                        </div>

                        <div class="account-venue-schedule-day__intervals">
                            @foreach($scheduleRows[$dayOfWeek] as $index => $interval)
                                @php
                                    $startsAtValue = old("intervals.$dayOfWeek.$index.starts_at", $interval['starts_at']);
                                    $endsAtValue = old("intervals.$dayOfWeek.$index.ends_at", $interval['ends_at']);
                                    $isVisibleInterval = $index === 0 || $startsAtValue || $endsAtValue;
                                @endphp
                                <div
                                    class="account-venue-schedule-interval"
                                    data-venue-schedule-interval
                                    @unless($isVisibleInterval) hidden @endunless
                                >
                                    <div class="account-venue-schedule-interval__label">
                                        Интервал {{ $index + 1 }}
                                    </div>
                                    <label>
                                        <span>Начало</span>
                                        <input
                                            type="time"
                                            name="intervals[{{ $dayOfWeek }}][{{ $index }}][starts_at]"
                                            class="form-control @error("intervals.$dayOfWeek.$index.starts_at") is-invalid @enderror"
                                            value="{{ $startsAtValue }}"
                                        >
                                    </label>
                                    <label>
                                        <span>Конец</span>
                                        <input
                                            type="time"
                                            name="intervals[{{ $dayOfWeek }}][{{ $index }}][ends_at]"
                                            class="form-control @error("intervals.$dayOfWeek.$index.ends_at") is-invalid @enderror"
                                            value="{{ $endsAtValue }}"
                                        >
                                    </label>
                                    <button type="button" class="btn btn--secondary btn--sm account-venue-schedule-interval__remove" data-venue-schedule-remove-interval>
                                        Удалить
                                    </button>
                                </div>
                                @error("intervals.$dayOfWeek.$index.starts_at")
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                                @error("intervals.$dayOfWeek.$index.ends_at")
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            @endforeach
                        </div>

                        <button type="button" class="btn btn--secondary btn--sm account-venue-schedule-day__add" data-venue-schedule-add-interval>
                            Добавить интервал
                        </button>
                    </section>
                @endforeach
            </div>

            <div class="account-venue-schedule__actions mt-4">
                <a href="{{ route('account.venues.show', $venue->alias) }}" class="btn btn--secondary btn--sm">К площадке</a>
                <button type="submit" class="btn btn--primary btn--sm">Сохранить расписание</button>
            </div>
        </form>
    @endif
@endsection
