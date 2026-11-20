@php
    $state = $restriction ? 'Заблокировано'
        : ($ownership ? 'Подтверждено'
        : ($claim ? $claim->status->label()
        : ($contracts->isNotEmpty() ? 'Контракт назначен' : 'Требуется подтверждение')));
    $pending = $claim?->status === \App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum::PENDING;
    $draft = $claim?->status === \App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum::DRAFT;
    $rejected = $claim?->status === \App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum::REJECTED;
    $claimBlocked = $claim?->status === \App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum::BLOCKED;
@endphp
<div class="venue-contract-case">
    <nav class="venue-contract-case__back" aria-label="Навигация">
        <a href="{{ route('account.contracts.venues') }}">← Мои контракты / Площадки</a>
    </nav>
    @if(session('status'))<p class="venue-contract-case__notice" role="status">{{ session('status') }}</p>@endif
    @if(session('error'))<p class="venue-contract-case__error" role="alert">{{ session('error') }}</p>@endif
    @if($errors->any())
        <div class="venue-contract-case__error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
    @endif
    <article class="venue-contract-case__panel">
        <div class="venue-contract-case__head">
            <div>
                <h2>{{ $venue->name }}</h2>
                @if($venue->raw_address)<p>{{ $venue->raw_address }}</p>@endif
            </div>
            <span class="venue-contract-case__badge {{ $restriction ? 'is-blocked' : ($ownership ? 'is-active' : '') }}">{{ $state }}</span>
        </div>
        @if(app(\App\Modules\PlayInterest\Application\Services\VenueSubscriptionAudience::class)->canManageAudience(auth()->user(), $venue))
            <div class="venue-contract-case__actions">
                <a class="venue-contract-case__button" href="{{ route('account.venue-audience.index', $venue->id) }}">Интересуются</a>
            </div>
        @endif
        @if($invitation)
            <p class="venue-contract-case__note">Приглашение принято: {{ $invitation->role === 'owner' ? 'подтверждение полномочий владельца' : 'участие в управлении площадкой' }}. Подтверждение email не заменяет проверку прав управления.</p>
        @endif
        @if($restriction)
            <div class="venue-contract-case__error">
                <strong>Повторная подача заблокирована администрацией.</strong>
                <p>{{ $restriction->reason }}</p>
                <p>Обратитесь в поддержку MSKBA. Новая заявка станет доступна только после снятия ограничения администратором.</p>
            </div>
        @elseif($ownership)
            <p class="venue-contract-case__success">Полномочия по управлению подтверждены. Действующий договор и разрешения доступны ниже.</p>
        @elseif($alreadyOwned && !$reviewer)
            <p class="venue-contract-case__note">У этой площадки уже есть подтверждённый представитель. Новая заявка на владение не создаётся автоматически; для передачи полномочий необходим отдельный процесс.</p>
        @elseif(!$claim && !$reviewer)
            <p>Чтобы получить полномочия владельца, укажите основание управления площадкой и отправьте заявку на проверку. Администрация может запросить дополнительные документы в переписке.</p>
        @endif

        @if($claim)
            <div class="venue-contract-case__section" aria-labelledby="venue-case-claim">
                <h3 id="venue-case-claim">Заявка на подтверждение</h3>
                <p><strong>Статус:</strong> {{ $restriction ? 'Заблокировано' : $claim->status->label() }}
                    @if($claim->submitted_at) · отправлена {{ $claim->submitted_at->format('d.m.Y H:i') }} @endif
                </p>
                @if($claim->evidence)<p class="venue-contract-case__evidence">{{ $claim->evidence }}</p>@endif
                @if($claim->decision_reason)
                    <p class="venue-contract-case__note"><strong>Решение администрации:</strong> {{ $claim->decision_reason }}</p>
                @endif
                @if($rejected && !$restriction && !$alreadyOwned && !$reviewer)
                    <p>Заявка отклонена, но вы можете добавить убедительное объяснение, исправить сведения и приложить новые документы в повторной заявке. История предыдущего решения сохранится.</p>
                @elseif($claimBlocked)
                    <p>Эта заявка заблокирована. После снятия ограничения можно будет подать новую, без удаления истории.</p>
                @endif
                @if($claim->documents->isNotEmpty())
                    <h4>Предоставленные документы</h4>
                    <ul>
                        @foreach($claim->documents as $document)
                            <li><a href="{{ route('account.venue-ownership.documents.download', [$claim, $document]) }}">{{ $document->name }}</a>
                                @if($draft)
                                    <form method="POST" action="{{ route('account.venue-ownership.documents.destroy', [$claim, $document]) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="venue-contract-case__link-button">Удалить документ</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        @if($draft && !$restriction && !$reviewer)
            <form id="claim-form" class="venue-contract-case__form" method="POST" enctype="multipart/form-data" action="{{ route('account.venue-ownership.draft.save', $claim) }}">
                @csrf
                <h3>Основание полномочий</h3>
                <label for="venue-ownership-evidence">Как вы связаны с площадкой и чем можете подтвердить управление?</label>
                <textarea id="venue-ownership-evidence" name="ownership_evidence" maxlength="5000" rows="5" placeholder="Организация, должность, контактные данные, основание управления">{{ old('ownership_evidence', $claim->evidence) }}</textarea>
                <label for="venue-ownership-files">Документы (необязательно)</label>
                <input id="venue-ownership-files" type="file" name="ownership_documents[]" multiple accept="image/jpeg,image/png,application/pdf">
                <small>JPG, PNG или PDF, до 10 МБ на файл, до 10 документов. Файлы доступны только участникам проверки.</small>
                <div class="venue-contract-case__actions">
                    <button type="submit" name="intent" value="save" class="venue-contract-case__button">Сохранить черновик</button>
                    <button type="submit" name="intent" value="submit" class="venue-contract-case__button is-primary">Отправить на рассмотрение</button>
                </div>
            </form>
        @elseif($canStartClaim)
            <form method="POST" action="{{ route('account.venue-contracts.start', $venue) }}" class="venue-contract-case__actions" id="claim-form">
                @csrf
                <button type="submit" class="venue-contract-case__button is-primary">{{ $rejected || $claimBlocked ? 'Подать повторную заявку' : 'Начать подтверждение управления' }}</button>
            </form>
        @endif
    </article>

    @if($claim && ($claim->conversation || ($reviewer && $pending)))
        <section class="venue-contract-case__panel" id="claim-conversation" aria-labelledby="venue-ownership-chat-title">
            <h3 id="venue-ownership-chat-title">Переписка по проверке полномочий</h3>
            <p class="venue-contract-case__note">Переписка общая для заявителя и администрации MSKBA. Документы не публикуются на сайте.</p>
            <div class="venue-contract-case__messages" aria-live="polite">
                @forelse($claim->conversation?->messages ?? [] as $message)
                    <article class="venue-contract-case__message">
                        <strong>{{ $message->authorActor?->user?->username ?? 'Администрация MSKBA' }}</strong>
                        <time>{{ $message->created_at?->format('d.m.Y H:i') }}</time>
                        @if($message->body)<p>{{ $message->body }}</p>@endif
                        @if($message->attachment_path)
                            <a href="{{ route('account.venue-ownership.conversation.attachment', [$claim, $message]) }}">Скачать: {{ $message->attachment_name }}</a>
                        @endif
                    </article>
                @empty
                    <p>Диалог ещё не начат. Первое сообщение отправляет администрация.</p>
                @endforelse
            </div>
            @if($pending)
                <form method="POST" action="{{ route('account.venue-ownership.conversation.store', $claim) }}" class="venue-contract-case__form">
                    @csrf
                    <input type="hidden" name="client_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <label for="venue-ownership-message">Сообщение</label>
                    <textarea id="venue-ownership-message" name="body" maxlength="4000" rows="3" required></textarea>
                    <button class="venue-contract-case__button is-primary" type="submit">Отправить сообщение</button>
                </form>
                @if($claim->conversation)
                    <form method="POST" enctype="multipart/form-data" action="{{ route('account.venue-ownership.conversation.attach', $claim) }}" class="venue-contract-case__form">
                        @csrf
                        <input type="hidden" name="client_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <label for="venue-ownership-attachment">Ответить документом (по запросу администрации)</label>
                        <input type="file" id="venue-ownership-attachment" name="attachment" accept="image/jpeg,image/png,application/pdf,text/plain" required>
                        <label for="venue-ownership-attachment-comment">Комментарий (необязательно)</label>
                        <input id="venue-ownership-attachment-comment" type="text" name="body" maxlength="4000">
                        <button type="submit" class="venue-contract-case__button">Прикрепить документ</button>
                    </form>
                @endif
            @else
                <p class="venue-contract-case__note">Решение принято. История переписки доступна для чтения.</p>
            @endif
        </section>
    @elseif($pending)
        <section class="venue-contract-case__panel"><h3>Заявка на рассмотрении</h3><p>Администратор проверит основания управления и при необходимости откроет защищённый диалог для уточнений и документов.</p></section>
    @endif

    @if($reviewer && $pending)
        <section class="venue-contract-case__panel" aria-labelledby="venue-ownership-review-heading">
            <h3 id="venue-ownership-review-heading">Решение администрации</h3>
            <form method="POST" action="{{ route('account.venue-ownership.approve', $claim) }}" class="venue-contract-case__form">
                @csrf
                <label>Комментарий к одобрению (необязательно)<textarea name="reason" maxlength="2000"></textarea></label>
                <button type="submit" class="venue-contract-case__button is-primary">Подтвердить полномочия</button>
            </form>
            <form method="POST" action="{{ route('account.venue-ownership.reject', $claim) }}" class="venue-contract-case__form">
                @csrf
                <label>Причина отказа<textarea name="reason" minlength="5" maxlength="2000" required></textarea></label>
                <button type="submit" class="venue-contract-case__button">Отклонить с возможностью повторной подачи</button>
            </form>
            <form method="POST" action="{{ route('account.venue-ownership.reject-and-block', $claim) }}" class="venue-contract-case__form">
                @csrf
                <label>Основание блокировки<textarea name="reason" minlength="5" maxlength="2000" required></textarea></label>
                <button type="submit" class="venue-contract-case__button is-danger">Заблокировать повторную подачу</button>
            </form>
        </section>
    @endif

    @if($contracts->isNotEmpty())
        <section class="venue-contract-case__panel" aria-labelledby="venue-contracts-existing">
            <h3 id="venue-contracts-existing">Назначенные контракты</h3>
            @foreach($contracts as $membership)
                @if($membership->contract)
                    <article class="venue-contract-case__contract">
                        <strong>{{ $membership->contract->name }}</strong>
                        <span>{{ $membership->contract->status->label() }}</span>
                        <p>Полномочия: {{ $membership->contract->permissions->pluck('permission')->join(', ') ?: 'Без специальных прав' }}</p>
                        <a href="{{ route('account.contracts.show', $membership->contract->id) }}">Открыть контракт №{{ $membership->contract->number ?? $membership->contract->id }}</a>
                    </article>
                @endif
            @endforeach
        </section>
    @endif

    @if($claims->isNotEmpty())
        <section class="venue-contract-case__panel" aria-labelledby="venue-contracts-history">
            <h3 id="venue-contracts-history">История заявок</h3>
            <div class="venue-contract-case__history">
                @foreach($claims as $history)
                    <a href="{{ route('account.venue-contracts.show', ['venue' => $venue->id, 'claim' => $history->public_id]) }}">
                        <span>{{ $history->created_at?->format('d.m.Y H:i') }}</span>
                        <strong>{{ $history->status->label() }}</strong>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</div>
<style>
.venue-contract-case{display:grid;gap:18px;min-width:0}.venue-contract-case__back a,.venue-contract-case a:not(.venue-contract-case__button){color:var(--accent,#db610f);text-decoration:underline;text-underline-offset:2px}
.venue-contract-case__panel{background:var(--surface,#fff);border:1px solid var(--line,#dbe1e8);border-radius:20px;padding:clamp(18px,2.6vw,30px);display:grid;gap:16px;min-width:0}
.venue-contract-case__panel h2{font-size:clamp(22px,2.4vw,30px);margin:0}.venue-contract-case__panel h3{font-size:20px;margin:0}.venue-contract-case__panel h4{font-size:16px;margin:0}
.venue-contract-case__head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}.venue-contract-case__head p{color:var(--muted,#607080);margin:5px 0 0}
.venue-contract-case__badge{font-size:13px;font-weight:700;border-radius:100px;padding:8px 12px;background:var(--surface-raised,#f0f2f5);border:1px solid var(--line,#dbe1e8);white-space:normal}
.venue-contract-case__badge.is-active{color:#168248;background:#e5f7ed}.venue-contract-case__badge.is-blocked,.venue-contract-case__error{color:#b82e2e;background:#fff1f1}
.venue-contract-case__note,.venue-contract-case__success,.venue-contract-case__error,.venue-contract-case__notice{padding:12px 14px;border-radius:12px;border:1px solid var(--line,#dbe1e8);margin:0;line-height:1.55}
.venue-contract-case__success{background:#e8f9ee;color:#17613c}.venue-contract-case__notice{background:var(--surface-raised,#f2f5f9)}
.venue-contract-case__section{display:grid;gap:12px;padding-top:14px;border-top:1px solid var(--line,#dbe1e8)}
.venue-contract-case__form{display:grid;gap:10px;padding:14px 0;border-top:1px solid var(--line,#dbe1e8)}
.venue-contract-case__form :is(textarea,input[type=text],input[type=file]){display:block;width:100%;max-width:100%;min-width:0;border:1px solid var(--control-line,var(--line,#bdc8d5));border-radius:10px;padding:12px;background:var(--field,var(--surface,#fff));color:var(--text,#182029);font:inherit;box-sizing:border-box}
.venue-contract-case__form textarea{resize:vertical}.venue-contract-case__form label{display:grid;gap:6px;font-weight:600}
.venue-contract-case__actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.venue-contract-case__button{display:inline-flex;align-items:center;justify-content:center;padding:11px 16px;min-height:44px;border-radius:11px;border:1px solid var(--control-line,#abbdd0);color:var(--text,#182029);font:700 14px/1.3 inherit;cursor:pointer;background:var(--surface-raised,#f1f4f8)}
.venue-contract-case__button.is-primary{background:var(--accent,#ce5200);color:#fff;border-color:transparent}.venue-contract-case__button.is-danger{color:#ab2525;border-color:#d78383}
.venue-contract-case__evidence{white-space:pre-line;overflow-wrap:anywhere}.venue-contract-case__messages{display:grid;gap:8px;max-height:420px;overflow-y:auto}
.venue-contract-case__message{background:var(--surface-raised,#f2f5f9);border-radius:12px;padding:12px;display:grid;gap:5px}
.venue-contract-case__message time{font-size:12px;color:var(--muted,#64748b)}.venue-contract-case__message p{white-space:pre-line;overflow-wrap:anywhere;margin:0}
.venue-contract-case__contract,.venue-contract-case__history a{display:flex;flex-wrap:wrap;gap:8px;align-items:center;border-top:1px solid var(--line,#dbe1e8);padding:12px 0}
.venue-contract-case__contract{display:grid}.venue-contract-case__link-button{border:0;background:none;color:var(--accent,#ce5200);text-decoration:underline;cursor:pointer}
@media(max-width:600px){.venue-contract-case__actions{display:grid}.venue-contract-case__button{width:100%}}
</style>
