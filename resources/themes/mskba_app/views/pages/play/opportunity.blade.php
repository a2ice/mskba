@extends('theme::layouts.app', ['title' => $opportunity->displayTitle()])

@section('content')
@php
    $presentWall = app(\App\Modules\Social\Application\Services\SocialWallService::class);
    $myId = (int) $viewer->id;
@endphp
<section class="section app-play-opportunity" data-play-opportunity-page
    aria-labelledby="play-opportunity-title">
    <div class="container">
        <nav class="app-play-opportunity__breadcrumbs" aria-label="Хлебные крошки">
            <a href="{{ route('account') }}">Личный кабинет</a>
            <span aria-hidden="true">/</span>
            <a href="{{ route('play-intents.mine') }}">Мои подписки</a>
            <span aria-hidden="true">/</span>
            <span aria-current="page">{{ $opportunity->displayTitle() }}</span>
        </nav>

        @if(session('status')) <p class="app-play-opportunity__message" role="status">{{ session('status') }}</p> @endif
        @if(session('error')) <p class="app-play-opportunity__message is-error" role="alert">{{ session('error') }}</p> @endif
        @if($errors->any()) <p class="app-play-opportunity__message is-error" role="alert">{{ $errors->first() }}</p> @endif

        <div class="app-play-opportunity__election" id="group-election" aria-labelledby="group-election-heading">
            <div class="app-play-opportunity__heading">
                <div>
                    <p class="eyebrow">Самоуправление</p>
                    <h2 id="group-election-heading">{{ $election ? 'Выборы администратора группы' : ($opportunity->administrator ? 'Администратор группы' : 'В группе пока нет администратора') }}</h2>
                    <p class="muted">Только избранный администратор сможет создавать групповые мероприятия, опросы и заявки на бронирование.</p>
                </div>
                <a href="#group-election-form" class="button secondary">{{ $opportunity->administrator ? 'Предложить смену администратора' : 'Стать администратором группы' }}</a>
            </div>
            @if($opportunity->administrator)
                <p class="app-play-opportunity__notice">Текущий администратор: <strong>{{ $opportunity->administrator->username ?: 'Игрок #'.$opportunity->administrator->id }}</strong></p>
            @endif

            @if($election)
                <p class="app-play-opportunity__notice">Голосов для избрания: <strong>{{ $election->votes_required }}</strong> из {{ $election->electorate_count }} · Приём голосов до {{ $election->closes_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</p>
                <div class="app-play-opportunity__candidates">
                    @foreach($election->candidates->whereNull('withdrawn_at') as $candidate)
                        @php($votesForCandidate = $election->votes->where('candidate_id', $candidate->id)->count())
                        <div class="app-play-opportunity__candidate">
                            <div>
                                <strong>{{ $candidate->user?->username ?: 'Игрок #'.$candidate->user_id }}</strong>
                                <p>{{ $candidate->reason }}</p>
                                <small>{{ $votesForCandidate }} из {{ $election->votes_required }} необходимых голосов</small>
                            </div>
                            <form method="POST" action="{{ route('play.opportunities.elections.vote', $opportunity) }}">
                                @csrf @method('PUT')
                                <input type="hidden" name="candidate_id" value="{{ $candidate->id }}">
                                <button class="button {{ $userVote?->candidate_id === $candidate->id ? 'secondary' : 'primary' }}"
                                    type="submit" @disabled($userVote?->candidate_id === $candidate->id)>
                                    {{ $userVote?->candidate_id === $candidate->id ? 'Вы поддержали' : 'Поддержать' }}
                                </button>
                            </form>
                        </div>
                    @endforeach
                </div>
                @if($userVote)
                    <form method="POST" action="{{ route('play.opportunities.elections.retract', $opportunity) }}">
                        @csrf @method('DELETE')
                        <button class="button secondary" type="submit">Отозвать свой голос</button>
                    </form>
                @endif
            @endif

            <details id="group-election-form" class="app-play-opportunity__form-details">
                <summary>{{ $election ? 'Подать свою кандидатуру' : 'Стать администратором группы' }}</summary>
                <form method="POST" action="{{ route('play.opportunities.candidates.store', $opportunity) }}">
                    @csrf
                    <label for="group-candidate-reason">Почему участники должны выбрать именно тебя?</label>
                    <textarea name="reason" id="group-candidate-reason" rows="3" required minlength="20" maxlength="1000"
                        placeholder="Расскажи, как планируешь помогать группе организовывать игры">{{ old('reason') }}</textarea>
                    <small>От 20 до 1000 символов · кандидатуры и голоса сохраняются в истории группы.</small>
                    <button type="submit" class="button primary">Подать заявку</button>
                </form>
            </details>
        </div>

        <header class="app-play-opportunity__header">
            <div>
                <p class="eyebrow">Группа совпавших игровых интересов</p>
                <h1 id="play-opportunity-title">{{ $opportunity->displayTitle() }}</h1>
                <p class="muted">Группа объединяет игроков с пересекающимися предпочтениями. Интерес не означает подтверждение участия или бронь.</p>
            </div>
            <button type="button" class="button secondary"
                data-mskba-faq-section="other" data-mskba-faq-article="play-interest-group-howto">
                Как это работает?
            </button>
        </header>

        <div class="app-play-opportunity__workspace">
            <aside class="app-play-opportunity__sidebar" aria-label="Разделы группы">
                <nav data-play-opportunity-tabs>
                    <button type="button" class="is-active" data-play-opportunity-tab="overview" aria-current="page">Обзор</button>
                    <button type="button" data-play-opportunity-tab="venues">Площадки</button>
                    <button type="button" data-play-opportunity-tab="members">Участники</button>
                    <button type="button" data-play-opportunity-tab="discussion">Обсуждение</button>
                    <button type="button" data-play-opportunity-tab="history">История действий</button>
                </nav>
            </aside>
            <div class="app-play-opportunity__main">
                <section data-play-opportunity-panel="overview" aria-label="Обзор группы">
                    <h2>Что у нас совпало</h2>
                    <div class="app-play-opportunity__facts">
                        <span><strong>{{ $memberCount }}</strong> заинтересованных игроков</span>
                        <span>Формат: <strong>{{ $opportunity->format ? str_replace('x', '×', $opportunity->format) : 'Уточняется' }}</strong></span>
                        <span>Время: <strong>{{ $opportunity->common_time['label'] ?? 'Подходящие окна в обсуждении' }}</strong></span>
                        <span>Подходящих площадок: <strong>{{ count((array) $opportunity->common_venue_ids) }}</strong></span>
                    </div>
                    <h3>Организация игры</h3>
                    <p class="muted">Выбери интересную площадку и предложи время. Когда у группы будет избран администратор, он сможет запустить согласование и организацию мероприятия.</p>
                    @if($assemblies->isEmpty())
                        <p class="muted">Связанных мероприятий или бронирований пока нет.</p>
                    @else
                        @foreach($assemblies as $assembly)
                            <article class="app-play-opportunity__event">
                                @if($assembly->event)
                                    <a href="{{ route('events.show', $assembly->event->routeIdentifier()) }}">{{ $assembly->event->title }}</a>
                                    <small>Мероприятие: {{ $assembly->event->status->value }}</small>
                                @else
                                    <strong>Сбор участников</strong><small>{{ $assembly->status }} · мероприятие ещё не создано</small>
                                @endif
                            </article>
                        @endforeach
                    @endif
                    @if($isGroupAdmin)
                        <details class="app-play-opportunity__form-details">
                            <summary>Изменить название группы</summary>
                            <form method="POST" action="{{ route('play.opportunities.title.update', $opportunity) }}">
                                @csrf @method('PATCH')
                                <label for="group-custom-title">Название (не более 80 символов)</label>
                                <input id="group-custom-title" name="custom_title" maxlength="80"
                                    value="{{ $opportunity->custom_title }}" placeholder="{{ $opportunity->generated_title }}">
                                <button class="button primary" type="submit">Сохранить</button>
                                <p class="muted">Очисти поле и сохрани, чтобы вернуть автоматическое название.</p>
                            </form>
                        </details>
                    @endif
                </section>

                <section data-play-opportunity-panel="venues" aria-label="Оценка площадок" hidden>
                    <h2>Выбор площадки и времени</h2>
                    <p class="muted">Оцени подходящие площадки от 1 до 10, предложи время и дату. Это ещё не бронирование.</p>
                    @forelse($rankedVenues as $result)
                        @php($venue = $result['venue'])
                        <article class="app-play-opportunity__venue">
                            <header>
                                <h3><a href="{{ route('venues.show', $venue->routeIdentifier()) }}">{{ $venue->name }}</a></h3>
                                <strong>{{ $result['average'] === null ? 'Без оценок' : $result['average'].'/10' }}</strong>
                            </header>
                            <p class="muted">{{ $venue->location?->address?->full_address ?: $venue->raw_address ?: 'Адрес не указан' }}</p>
                            <small>{{ $result['rating_count'] }} оценили · {{ $result['supporters'] }} поддерживают (7–10) · {{ $result['not_suitable'] }} не подходит</small>
                            <form method="POST" action="{{ route('play.opportunities.venues.rate', [$opportunity, $venue->id]) }}">
                                @csrf @method('PUT')
                                <div class="app-play-opportunity__two">
                                    <label>Моя оценка от 1 до 10
                                        <select name="rating">
                                            <option value="">Без оценки</option>
                                            @for($score=1;$score<=10;$score++)
                                                <option value="{{ $score }}" @selected($result['my']?->rating === $score)>{{ $score }} / 10</option>
                                            @endfor
                                        </select>
                                    </label>
                                    <label>Удобное время: режим
                                        <select name="time_mode" data-group-time-mode>
                                            <option value="">Не предлагать</option>
                                            <option value="weekly">День недели</option>
                                            <option value="date">Конкретная дата</option>
                                        </select>
                                    </label>
                                    <label class="app-play-opportunity__time-input" data-group-time-weekday hidden>День недели
                                        <select name="weekday">
                                            @foreach(['Пн','Вт','Ср','Чт','Пт','Сб','Вс'] as $dayNumber => $day)
                                                <option value="{{ $dayNumber+1 }}">{{ $day }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="app-play-opportunity__time-input" data-group-time-date hidden>Дата
                                        <input type="date" name="date" min="{{ now()->addDay()->toDateString() }}">
                                    </label>
                                    <label>Начало
                                        <input type="time" name="start_time">
                                    </label>
                                    <label>Окончание
                                        <input type="time" name="end_time">
                                    </label>
                                </div>
                                <label class="app-play-opportunity__checkbox">
                                    <input type="checkbox" name="unsuitable" value="1" @checked($result['my']?->unsuitable)>
                                    Эта площадка не подходит мне
                                </label>
                                <label>Комментарий к площадке
                                    <textarea name="comment" maxlength="300" rows="2">{{ $result['my']?->comment }}</textarea>
                                </label>
                                <button class="button primary" type="submit">Сохранить оценку и время</button>
                            </form>
                        </article>
                    @empty
                        <p class="muted">Пока нет общего подходящего варианта. Уточни условия подписки.</p>
                    @endforelse
                </section>

                <section data-play-opportunity-panel="members" aria-label="Участники" hidden>
                    <h2>Участники группы</h2>
                    <div class="app-play-opportunity__members">
                        @foreach($opportunity->members->where('status','active') as $member)
                            <div>
                                <strong>{{ $member->user?->username ?: 'Игрок #'.$member->user_id }}</strong>
                                @if($member->user_id === $opportunity->admin_user_id)
                                    <span class="badge">Администратор</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>

                <section data-play-opportunity-panel="discussion" aria-label="Стена группы" hidden>
                    <h2>Обсуждение группы</h2>
                    <p class="muted">Пиши по делу: до 200 символов и не более трёх пользовательских действий за минуту.</p>
                    <div class="app-play-opportunity__wall">
                        @forelse($wall as $entry)
                            @php($item = $presentWall->present($entry))
                            <article class="{{ $item['system'] ? 'is-system' : '' }}">
                                <header>
                                    <strong>{{ $item['system'] ? 'Событие' : $item['actor'] }}</strong>
                                    <time>{{ $item['date'] }}</time>
                                </header>
                                <p class="{{ $item['deleted'] ? 'is-deleted' : '' }}">{{ $item['text'] }}</p>
                                @if($item['deleted'] && !$item['system'])
                                    <small>Сообщение удалено автором</small>
                                @elseif(!$item['system'] && $entry->actor_user_id === $myId)
                                    <form method="POST" action="{{ route('play.opportunities.wall.delete', [$opportunity, $entry->id]) }}">
                                        @csrf @method('DELETE')
                                        <button class="app-play-opportunity__text-button" type="submit">Удалить</button>
                                    </form>
                                @endif
                            </article>
                        @empty
                            <p class="muted">Сообщений пока нет. Начни обсуждение.</p>
                        @endforelse
                    </div>
                    <form method="POST" action="{{ route('play.opportunities.wall.store', $opportunity) }}">
                        @csrf
                        <input type="hidden" name="client_request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <label for="play-group-wall-text">Написать участникам</label>
                        <textarea name="body" id="play-group-wall-text" rows="3" maxlength="200" required
                            placeholder="Предлагаю в среду в 19:00...">{{ old('body') }}</textarea>
                        <button type="submit" class="button primary">Отправить</button>
                    </form>
                </section>

                <section data-play-opportunity-panel="history" aria-label="История действий" hidden>
                    <h2>История решений</h2>
                    @forelse($wall->filter(fn ($entry) => $entry->kind !== 'comment') as $entry)
                        @php($item = $presentWall->present($entry))
                        <div class="app-play-opportunity__history-entry">
                            <time>{{ $item['date'] }}</time>
                            <p>{{ $item['text'] }}</p>
                        </div>
                    @empty
                        <p class="muted">Пока нет системных действий.</p>
                    @endforelse
                </section>
            </div>
        </div>
    </div>
</section>
@endsection
