@extends('theme::layouts.account', ['title' => 'Изменение данных — сообщения'])

@section('account-content')
<section class="app-messages app-messages--thread" aria-label="Обсуждение изменения данных"
    @if($canRead) data-chat-root data-chat-history-url="{{ route('account.notifications.threads.profile.history', $changeRequest->id) }}"
        data-chat-channel="request-threads.{{ $thread->id }}"
        data-chat-read-url="{{ route('account.notifications.threads.profile.read', $changeRequest->id) }}"
        data-chat-unread-initial="0" @endif>
    <header class="panel app-messages__thread-header">
        <a class="app-messages__back" href="{{ route('account.notifications') }}" aria-label="Все сообщения">
            <svg aria-hidden="true"><use href="#arrow"/></svg>
        </a>
        <span class="app-messages__avatar" aria-hidden="true"><svg><use href="#message"/></svg></span>
        <div class="app-messages__thread-contact">
            <h2>Изменение данных №{{ $changeRequest->id }}</h2>
            <p>{{ !$canRead ? 'Доступ прекращён' : match($changeRequest->status) {
                'pending' => 'На рассмотрении', 'approved' => 'Одобрена', 'rejected' => 'Отклонена',
                'blocked' => 'Заблокирована', 'cancelled' => 'Отменена', default => 'Заявка' } }}</p>
        </div>
    </header>

    @if (!$canRead)
        <section class="panel app-profile-thread__closed" role="status" data-profile-thread-closed>
            <h3>Доступ к обсуждению прекращён</h3>
            <p class="muted">{{ $removedReason }}</p>
            <p class="muted">История сообщений и действия по заявке больше недоступны. Запись в списке сообщений сохранена.</p>
        </section>
    @else
        <div class="panel app-messages__chat-log" data-chat-scroll role="region" aria-label="Прокручиваемая история заявки" tabindex="0">
            <div class="app-messages__load" data-chat-load aria-live="polite" hidden>
                <span class="spinner" aria-hidden="true"></span><span>Загружаем историю…</span>
            </div>
            <div class="app-messages__history" data-chat-history data-next-before="{{ $nextBefore }}" role="log" aria-live="polite" aria-label="История заявки">
                @foreach ($timeline as $entry)
                    @include('theme::pages.account.partials.chat-bubble', ['entry' => $entry, 'viewer' => $viewer])
                @endforeach
            </div>
        </div>

        @if ($canReview && $isLead && $changeRequest->status === 'pending')
            <section class="panel app-profile-thread__reviewers" data-chat-reviewers hidden aria-label="Участники рассмотрения">
                <h3>Участники рассмотрения</h3>
                <p class="app-profile-thread__lead">Главный: {{ $thread->lead?->username ?? 'Не назначен' }}</p>
                <div class="app-profile-thread__management">
                    <form method="POST" action="{{ route('account.notifications.threads.profile.invite', $changeRequest->id) }}" data-profile-thread-invite>
                        @csrf
                        <label for="profile-thread-user-search">Пригласить уполномоченного сотрудника</label>
                        <input type="search" id="profile-thread-user-search" placeholder="Начни вводить логин…" autocomplete="off"
                            data-profile-thread-search data-url="{{ route('account.notifications.threads.profile.candidates', $changeRequest->id) }}">
                        <div data-profile-thread-results role="listbox" aria-label="Найденные сотрудники"></div>
                        <input type="hidden" name="user_id" data-profile-thread-selected required>
                        <button class="button secondary" type="submit" data-profile-thread-submit disabled>Пригласить</button>
                    </form>
                    @foreach ($participants as $participant)
                        @if ((int) $participant->id !== (int) $viewer->id)
                            <div class="app-profile-thread__participant">
                                <strong>{{ $participant->username }}</strong>
                                <form method="POST" action="{{ route('account.notifications.threads.profile.transfer', $changeRequest->id) }}">
                                    @csrf <input type="hidden" name="user_id" value="{{ $participant->id }}">
                                    <button class="button secondary" type="submit">Сделать главным</button>
                                </form>
                                <form method="POST" action="{{ route('account.notifications.threads.profile.remove', $changeRequest->id) }}">
                                    @csrf <input type="hidden" name="user_id" value="{{ $participant->id }}">
                                    <button class="button secondary" type="submit">Исключить</button>
                                </form>
                            </div>
                        @endif
                    @endforeach
                </div>
            </section>
        @endif

        @if ($canWrite || ($canReview && $changeRequest->status === 'pending'))
            <form class="panel app-messages__compose" method="POST" action="{{ route('account.notifications.threads.profile.messages', $changeRequest->id) }}" data-chat-compose>
                @csrf
                <label class="sr-only" for="profile-thread-body">Сообщение</label>
                <textarea id="profile-thread-body" name="body" rows="3" maxlength="2000" required
                    @disabled(!$canWrite) placeholder="{{ $canWrite ? 'Введите сообщение…' : 'Сначала возьмите заявку в работу' }}"></textarea>
                <div class="app-messages__compose-footer">
                    <details class="app-context-actions app-messages__actions" data-chat-actions>
                        @include('theme::pages.account.partials.chat-actions-trigger', ['ariaLabel' => 'Действия с заявкой'])
                        <div class="app-context-actions__menu" aria-label="Действия с заявкой">
                            @if ($canReview && !$isMember && $changeRequest->status === 'pending')
                                <button type="submit" form="profile-thread-claim">Взять заявку в работу</button>
                            @endif
                            @if ($canRescue && $changeRequest->status === 'pending')
                                <button type="submit" form="profile-thread-rescue">Назначить себя главным</button>
                            @endif
                            @if ($canReview && $isLead && $changeRequest->status === 'pending')
                                <button type="button" data-chat-reviewers-toggle>Управление участниками</button>
                            @endif
                            @if ($canReview && $isMember && $changeRequest->status === 'pending')
                                <button type="submit" form="profile-thread-approve">Одобрить заявку</button>
                                <button type="button" data-review-dialog-open="reject">Отклонить заявку</button>
                                <button type="button" data-review-dialog-open="block">Заблокировать заявку</button>
                            @endif
                            @if ((int) $viewer->id === (int) $changeRequest->user_id && $changeRequest->status === 'pending')
                                <button type="submit" form="profile-thread-cancel">Отменить заявку</button>
                            @endif
                            <a href="{{ route('account.notifications') }}">Все сообщения</a>
                        </div>
                    </details>
                    <button class="button primary" type="submit" data-chat-send @disabled(!$canWrite)>Отправить</button>
                </div>
                <p class="app-messages__read-error" data-chat-error role="alert" hidden></p>
            </form>
            @if ($canReview && !$isMember && $changeRequest->status === 'pending')
                <form id="profile-thread-claim" method="POST" action="{{ route('account.notifications.threads.profile.claim', $changeRequest->id) }}">@csrf</form>
            @endif
            @if ($canRescue && $changeRequest->status === 'pending')
                <form id="profile-thread-rescue" method="POST" action="{{ route('account.notifications.threads.profile.rescue', $changeRequest->id) }}">@csrf</form>
            @endif
            @if ($canReview && $isMember && $changeRequest->status === 'pending')
                <form id="profile-thread-approve" method="POST" action="{{ route('admin.profile-change-requests.review', $changeRequest->id) }}">
                    @csrf <input type="hidden" name="action" value="approve"><input type="hidden" name="return_to" value="thread">
                </form>
                <dialog class="app-messages__review-dialog" data-chat-review-dialog aria-labelledby="profile-review-title">
                    <form method="POST" action="{{ route('admin.profile-change-requests.review', $changeRequest->id) }}">
                        @csrf <input type="hidden" name="action" value="" data-review-action><input type="hidden" name="return_to" value="thread">
                        <h3 id="profile-review-title" data-review-title>Решение по заявке</h3>
                        <label for="profile-review-reason">Причина решения</label>
                        <textarea id="profile-review-reason" name="review_reason" maxlength="2000" required></textarea>
                        <div class="app-messages__compose-footer">
                            <button class="button secondary" type="button" data-review-dialog-close>Отмена</button>
                            <button class="button primary" type="submit">Подтвердить</button>
                        </div>
                    </form>
                </dialog>
            @endif
            @if ((int) $viewer->id === (int) $changeRequest->user_id && $changeRequest->status === 'pending')
                <form id="profile-thread-cancel" method="POST" action="{{ route('account.profile-change-requests.cancel', $changeRequest->id) }}">@csrf <input type="hidden" name="return_to" value="thread"></form>
            @endif
        @elseif ($changeRequest->status !== 'pending')
            <div class="panel app-messages__compose">
                <p class="app-messages__closed">Рассмотрение завершено. История доступна только для чтения.</p>
                @if ($canReview && $isMember && $changeRequest->status === 'blocked')
                    <div class="app-messages__compose-footer">
                        <details class="app-context-actions app-messages__actions" data-chat-actions>
                            @include('theme::pages.account.partials.chat-actions-trigger', ['ariaLabel' => 'Действия с заявкой'])
                            <div class="app-context-actions__menu">
                                <button type="submit" form="profile-thread-unblock">Разблокировать заявку</button>
                                <a href="{{ route('account.notifications') }}">Все сообщения</a>
                            </div>
                        </details>
                    </div>
                    <form id="profile-thread-unblock" method="POST" action="{{ route('admin.profile-change-requests.review', $changeRequest->id) }}">
                        @csrf <input type="hidden" name="action" value="unblock"><input type="hidden" name="return_to" value="thread">
                    </form>
                @endif
            </div>
        @endif
    @endif
</section>
@endsection
