@php
    use App\Modules\Event\Domain\Enums\GameStatusEnum;

    $routeParameters = [$event->routeIdentifier(), $game->id];
    $joinUrl = route('events.games.recruitment.join', $routeParameters);
    $confirmed = $game->sides_confirmed_at !== null;
    $decisionOpen = $game->actual_ended_at === null
        && in_array($game->status, [GameStatusEnum::SCHEDULED, GameStatusEnum::IN_PROGRESS], true);
    $name = static function ($admission): string {
        $user = $admission->user?->canonical();
        $profile = $user?->profile;

        return trim(implode(' ', array_filter([$profile?->first_name, $profile?->last_name])))
            ?: $user?->username
            ?: 'Игрок #'.$admission->user_id;
    };
@endphp

<section
    class="section-card mb-5 game-qr-late-panel"
    data-game-qr-late-panel
    data-game-id="{{ $game->id }}"
    data-game-qr-join-url="{{ $joinUrl }}"
>
    <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start mb-3">
        <div>
            <span class="eyebrow">Быстрый сбор игроков</span>
            <h2 class="mb-1">QR-подключение к balanced-игре</h2>
            <p class="form-text mb-0">
                {{ $game->status === GameStatusEnum::IN_PROGRESS
                    ? 'Игра уже идёт: новые игроки могут подать заявку, а вы добавите принятого игрока прямо в сторону A или B.'
                    : 'Покажите QR игрокам на площадке. После авторизации они смогут подать заявку на участие.' }}
            </p>
        </div>
        @if($available)
            <span class="tournament-preparation-status is-ready"><i class="ti ti-qrcode" aria-hidden="true"></i>Набор открыт</span>
        @else
            <span class="tournament-preparation-status is-pending"><i class="ti ti-lock" aria-hidden="true"></i>Набор закрыт</span>
        @endif
    </div>

    @if($available)
        <div class="game-qr-share game-qr-share--embedded" data-game-qr-share>
            <div class="game-qr-share__copy">
                <h3>Отсканируйте и присоединяйтесь</h3>
                <p>QR ведёт на специальную страницу входа и заявки. Если игрок уже пользовался Telegram Mini App, страница подскажет войти через Telegram/VK, чтобы не создать дубликат.</p>
                <div class="game-qr-share__actions">
                    <button class="btn btn--secondary btn--sm" type="button" data-game-qr-copy>Скопировать ссылку</button>
                    <a class="btn btn--primary btn--sm" href="{{ $joinUrl }}" target="_blank" rel="noopener">Открыть страницу игрока</a>
                </div>
                <small class="game-qr-share__status" data-game-qr-copy-status aria-live="polite"></small>
            </div>
            <div class="game-qr-share__code" data-game-qr-code aria-label="QR-код для подключения к игре"></div>
        </div>
    @endif

    @if($confirmed && $decisionOpen)
        <div class="border rounded p-3 mt-4">
            <form method="POST" action="{{ route('events.games.recruitment.late.applications', $routeParameters) }}" data-game-late-join-ajax>
                @csrf @method('PATCH')
                <input type="hidden" name="enabled" value="0">
                <label class="d-flex gap-3 align-items-start">
                    <input type="checkbox" name="enabled" value="1" @checked($game->accepts_applications)>
                    <span>
                        <strong>Принимать игроков во время игры</strong><br>
                        <small class="form-text">Выключите, когда состав уже достаточный. Ожидающие заявки всё равно можно обработать.</small>
                    </span>
                </label>
                <button class="btn btn--secondary btn--sm mt-3" type="submit">Сохранить настройку</button>
            </form>
        </div>

        <div class="game-late-admissions mt-4">
            <div class="d-flex justify-content-between gap-3 align-items-end mb-2">
                <div>
                    <h3 class="h5 mb-1">Новые заявки</h3>
                    <p class="form-text mb-0">После утверждения сторон принятому игроку сразу назначается команда.</p>
                </div>
                <span class="game-late-admissions__count">{{ $pendingAdmissions->count() }}</span>
            </div>

            @forelse($pendingAdmissions as $admission)
                <article class="game-late-admission">
                    <div class="game-late-admission__identity">
                        @if($admission->user?->profile?->activeAvatar)
                            <img src="{{ $admission->user->profile->activeAvatar->publicUrl() }}" alt="">
                        @else
                            <span>{{ mb_strtoupper(mb_substr($name($admission), 0, 2)) }}</span>
                        @endif
                        <div>
                            <strong>{{ $name($admission) }}</strong>
                            <small>Ожидает решения</small>
                        </div>
                    </div>
                    <div class="game-late-admission__actions">
                        @foreach($game->sides->sortBy('slot') as $side)
                            <form method="POST" action="{{ route('events.games.recruitment.late.accept', [...$routeParameters, $admission->id]) }}" data-game-late-join-ajax>
                                @csrf
                                <input type="hidden" name="side" value="{{ $side->slot }}">
                                <button class="btn btn--primary btn--sm" type="submit">
                                    В {{ $side->display_name ?: 'сторону '.$side->slot }}
                                </button>
                            </form>
                        @endforeach
                        <form method="POST" action="{{ route('events.games.recruitment.late.decline', [...$routeParameters, $admission->id]) }}" data-game-late-join-ajax>
                            @csrf
                            <button class="btn btn--secondary btn--sm" type="submit">Отклонить</button>
                        </form>
                    </div>
                </article>
            @empty
                <p class="form-text mb-0">Новых заявок сейчас нет. Можно оставить QR открытым на экране — игроки смогут подключаться по мере прихода.</p>
            @endforelse
        </div>
    @elseif($confirmed && !$decisionOpen)
        <div class="alert alert-info mt-4 mb-0">Игра уже завершена или закрыта для изменения состава. QR-страница остаётся доступна только для просмотра статуса ранее поданных заявок.</div>
    @endif
</section>
