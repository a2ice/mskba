@php
    $waiting = in_array($joinRequest->status, [
        \App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum::PENDING,
        \App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum::AWAITING_RESPONSE,
    ], true);
    $blocked = $joinRequest->status === \App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum::BLOCKED;
@endphp
@if($isManager && ($waiting || $blocked))
    <details class="app-context-actions app-messages__actions" data-chat-actions>
        @include('theme::pages.account.partials.chat-actions-trigger', ['ariaLabel' => 'Действия по заявке'])
        <div class="app-context-actions__menu" aria-label="Действия по заявке">
            @if($waiting)
                <button type="button" data-team-review-trigger="accept">Принять заявку</button>
                <button type="button" data-team-review-trigger="reject">Отклонить заявку</button>
                <button type="button" data-team-review-trigger="block">Заблокировать заявку</button>
            @elseif($blocked)
                <button type="button" data-team-review-trigger="unblock">Разблокировать заявку</button>
            @endif
            <a href="{{ route('teams.join-requests.index', $joinRequest->team->routeIdentifier()) }}">Все заявки команды</a>
        </div>
    </details>
    <dialog class="app-messages__review-dialog app-messages__team-review-dialog" data-team-review-dialog>
        <form method="POST" action="{{ route('teams.join-requests.respond', [$joinRequest->team->routeIdentifier(), $joinRequest->id]) }}">
            @csrf
            @method('PATCH')
            <input type="hidden" name="action" value="" data-team-review-action>
            <h3 data-team-review-title>Решение по заявке</h3>
            <div data-team-review-reason-wrap>
                <label for="team-review-reason-{{ $joinRequest->id }}">Причина решения</label>
                <textarea id="team-review-reason-{{ $joinRequest->id }}" name="review_reason" maxlength="2000" data-team-review-reason></textarea>
            </div>
            <div class="app-messages__team-review-actions">
                <button type="button" class="button secondary" data-team-review-cancel>Отмена</button>
                <button type="submit" class="button primary" data-team-review-submit>Подтвердить</button>
            </div>
        </form>
    </dialog>
@endif
