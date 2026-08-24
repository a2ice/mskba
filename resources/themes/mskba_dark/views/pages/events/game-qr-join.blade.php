@php
    use App\Modules\Event\Domain\Enums\GameAdmissionDirectionEnum;
    use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
    use App\Modules\Event\Domain\Enums\GameStatusEnum;

    $routeParameters = [$event->routeIdentifier(), $game->id];
    $joinUrl = route('events.games.recruitment.join', $routeParameters, false);
    $organizer = $event->organizerActor?->user?->canonical();
    $organizerName = trim(implode(' ', array_filter([
        $organizer?->profile?->first_name,
        $organizer?->profile?->last_name,
    ]))) ?: $organizer?->username ?: 'организатор';
    $pendingAdmission = $latestAdmission?->status === GameAdmissionStatusEnum::PENDING;
    $sidesConfirmed = $game->sides_confirmed_at !== null;
    $inProgress = $game->status === GameStatusEnum::IN_PROGRESS;
@endphp

@extends('theme::layouts.app', ['title' => 'Присоединиться к игре · '.$event->title])

@section('content')
<section
    class="section first-screen px-1 game-qr-join"
    data-game-qr-join
    data-game-id="{{ $game->id }}"
    @if($pendingAdmission) data-pending-admission-id="{{ $latestAdmission->id }}" @endif
>
    <div class="inner game-qr-join__inner">
        <a class="game-qr-join__back" href="{{ route('events.show', $event->routeIdentifier()) }}">
            <i class="ti ti-arrow-left" aria-hidden="true"></i>Открыть страницу игры
        </a>

        <header class="game-qr-join__hero">
            <span class="eyebrow">Присоединиться к игре</span>
            <h1>{{ $event->title }}</h1>
            <div class="game-qr-join__meta">
                <span><i class="ti ti-map-pin" aria-hidden="true"></i>{{ $event->venue->name }}</span>
                <span><i class="ti ti-calendar-event" aria-hidden="true"></i>{{ $event->starts_at->format('d.m.Y') }}</span>
                <span><i class="ti ti-clock" aria-hidden="true"></i>{{ $event->starts_at->format('H:i') }}</span>
                <span><i class="ti ti-users-group" aria-hidden="true"></i>{{ $game->format?->label() ?? $game->formatLabel() }} · balanced</span>
                @if($inProgress)<span class="is-live"><i class="ti ti-point-filled" aria-hidden="true"></i>Игра уже идёт</span>@endif
            </div>
        </header>

        <div class="game-qr-join__card">
            @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
            @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
            @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

            @guest
                @if($available)
                    <div class="game-qr-join__auth-warning">
                        <i class="ti ti-brand-telegram" aria-hidden="true"></i>
                        <div>
                            <strong>Уже пользовались MSKBA? Не создавайте второй аккаунт.</strong>
                            <p>QR открылся в обычном браузере, поэтому авторизация из Telegram Mini App здесь может быть не видна. Если аккаунт уже есть, войдите через <strong>Telegram</strong>, <strong>VK ID</strong> или обычным способом — мы вернём вас на эту игру.</p>
                        </div>
                    </div>

                    <div class="game-qr-join__auth-actions">
                        <button
                            class="btn btn--primary js-handler"
                            type="button"
                            data-handler="modal"
                            data-modal-action="open"
                            data-modal-target="auth-entry-classic"
                            data-auth-redirect-url="{{ $joinUrl }}"
                        >Войти или зарегистрироваться</button>
                    </div>
                    <p class="form-hint mb-0">После входа останется только нажать «Подать заявку».</p>
                @else
                    <div class="alert alert-info mb-0">
                        <strong>Набор на эту игру закрыт.</strong>
                        <p class="mt-2 mb-0">Организатор выключил новые заявки либо игра уже завершена.</p>
                    </div>
                @endif
            @else
                <div class="game-qr-join__identity">
                    <i class="ti ti-user-check" aria-hidden="true"></i>
                    <span>Вы вошли как <strong>{{ auth()->user()->canonical()->username }}</strong></span>
                </div>

                @if($latestAdmission?->status === GameAdmissionStatusEnum::ACCEPTED)
                    <div class="game-qr-join__status is-accepted">
                        <i class="ti ti-circle-check" aria-hidden="true"></i>
                        <div>
                            <strong>Заявка принята</strong>
                            @if($assignedSide)
                                <p>Вы добавлены в <strong>{{ $assignedSide->display_name ?: 'сторону '.$assignedSide->slot }}</strong>. {{ $inProgress ? 'Игра уже идёт — можно подключаться.' : 'Следите за страницей игры до начала.' }}</p>
                            @else
                                <p>Вы допущены к игре. Организатор включит вас в balanced-формирование сторон.</p>
                            @endif
                        </div>
                    </div>
                    <a class="btn btn--primary" href="{{ route('events.show', $event->routeIdentifier()) }}">Открыть игру</a>
                @elseif($latestAdmission?->status === GameAdmissionStatusEnum::PENDING)
                    @if($latestAdmission->direction === GameAdmissionDirectionEnum::INVITATION)
                        <div class="game-qr-join__status is-pending">
                            <i class="ti ti-mail-opened" aria-hidden="true"></i>
                            <div>
                                <strong>Вас уже пригласили на эту игру</strong>
                                <p>Подтвердите участие — отдельную заявку создавать не нужно.</p>
                            </div>
                        </div>
                        @if(!$sidesConfirmed)
                            <div class="game-qr-join__decision-actions">
                                <form method="POST" action="{{ route('events.games.recruitment.respond', [...$routeParameters, $latestAdmission->id]) }}">
                                    @csrf
                                    <input type="hidden" name="decision" value="accepted">
                                    <button class="btn btn--primary" type="submit">Принять приглашение</button>
                                </form>
                                <form method="POST" action="{{ route('events.games.recruitment.respond', [...$routeParameters, $latestAdmission->id]) }}">
                                    @csrf
                                    <input type="hidden" name="decision" value="declined">
                                    <button class="btn btn--secondary" type="submit">Отклонить</button>
                                </form>
                            </div>
                        @else
                            <p class="form-hint">Стороны уже сформированы. Попросите организатора добавить вас в одну из команд.</p>
                        @endif
                    @else
                        <div class="game-qr-join__status is-pending">
                            <span class="game-qr-join__pulse" aria-hidden="true"></span>
                            <div>
                                <strong>Заявка отправлена</strong>
                                <p>{{ $inProgress ? 'Игра уже идёт. Организатор выберет сторону A или B и добавит вас в текущий состав.' : 'Ожидаем решения организатора.' }} При открытой странице статус обновится автоматически в realtime.</p>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('events.games.recruitment.join.revoke', [...$routeParameters, $latestAdmission->id]) }}">
                            @csrf @method('DELETE')
                            <button class="btn btn--secondary btn--sm" type="submit">Отозвать заявку</button>
                        </form>
                    @endif
                @elseif($latestAdmission?->status === GameAdmissionStatusEnum::DECLINED)
                    <div class="game-qr-join__status is-declined">
                        <i class="ti ti-circle-x" aria-hidden="true"></i>
                        <div>
                            <strong>Заявка отклонена</strong>
                            @if($latestAdmission->response_comment)<p>{{ $latestAdmission->response_comment }}</p>@else<p>Если решение непонятно, уточните у организатора: {{ $organizerName }}.</p>@endif
                        </div>
                    </div>
                    @if($available)
                        <form method="POST" action="{{ route('events.games.recruitment.join.apply', $routeParameters) }}">
                            @csrf
                            <button class="btn btn--primary" type="submit">Подать заявку повторно</button>
                        </form>
                    @endif
                @elseif($available)
                    <div class="game-qr-join__ready">
                        <i class="ti ti-basketball" aria-hidden="true"></i>
                        <div>
                            <strong>{{ $inProgress ? 'Хотите подключиться к игре?' : 'Готовы играть?' }}</strong>
                            <p>{{ $sidesConfirmed ? 'Отправьте заявку. После подтверждения организатор сразу добавит вас в одну из текущих сторон.' : 'Отправьте заявку организатору. После подтверждения вы попадёте в пул balanced-формирования команд.' }}</p>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('events.games.recruitment.join.apply', $routeParameters) }}">
                        @csrf
                        <button class="btn btn--primary game-qr-join__apply" type="submit">Подать заявку на игру</button>
                    </form>
                @else
                    <div class="alert alert-info mb-0">
                        <strong>Набор на эту игру закрыт.</strong>
                        <p class="mt-2 mb-0">Организатор выключил новые заявки либо игра уже завершена.</p>
                    </div>
                @endif
            @endguest
        </div>
    </div>
</section>
@endsection
