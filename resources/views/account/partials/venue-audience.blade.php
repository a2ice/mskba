<div class="account-contracts-workspace">
    <nav class="account-contracts-workspace__tabs" aria-label="Навигация">
        @if($backoffice)
            <a href="{{ \App\Filament\Backoffice\Resources\Venues\VenueResource::getUrl('edit', ['record' => $venue]) }}">К карточке в Filament</a>
        @else
            <a href="{{ route('account.venue-contracts.show', $venue->id) }}">К площадке</a>
        @endif
    </nav>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
    <div class="account-contracts-workspace__card">
        <h2>Всего интересуются: {{ $summary['total'] }}</h2>
        <div class="account-contracts-workspace__meta">
            <span>Прямой интерес: {{ $summary['direct_count'] }}</span>
            <span>По условиям: {{ $summary['matched_count'] }}</span>
            <span>Без открытого профиля интереса: {{ $summary['anonymous_count'] }}</span>
        </div>
        <p>Личности видны только при согласии в совпавшей подписке и доступном профиле. Закрытые интересы учитываются только в общем количестве.</p>
    </div>
    <form class="account-contracts-workspace__filters" method="GET" action="{{ request()->url() }}">
        <label>Роль
            <span class="select-control"><select name="role"><option value="">Все роли</option>
                @foreach($roleOptions as $role)<option value="{{ $role->value }}" @selected(($filters['role'] ?? '') === $role->value)>{{ $role->label() }}</option>@endforeach
            </select>@include('account.partials.native-select-chevron')</span>
        </label>
        <label>Активность
            <span class="select-control"><select name="activity"><option value="">Вся активность</option>
                <option value="games" @selected(($filters['activity'] ?? '') === 'games')>Есть сыгранные игры</option>
                <option value="organized" @selected(($filters['activity'] ?? '') === 'organized')>Есть проведённые мероприятия</option>
            </select>@include('account.partials.native-select-chevron')</span>
        </label>
        <button type="submit" class="account-contracts-workspace__primary">Применить</button>
        <a class="account-contracts-workspace__reset" href="{{ request()->url() }}">Сбросить</a>
    </form>
    <p class="account-contracts-workspace__count" role="status">Пользователей с доступным профилем: {{ $people->total() }}</p>
    <div class="account-contracts-workspace__cards">
    @forelse($people as $person)
        <article class="account-contracts-workspace__card">
            <div class="account-contracts-workspace__card-head">
                <div style="display:flex;align-items:center;gap:12px;min-width:0">
                    @if($person['avatar'])<img src="{{ $person['avatar'] }}" alt="" width="48" height="48" loading="lazy" style="border-radius:50%;object-fit:cover">@endif
                    <div><h2><a href="{{ $person['url'] }}">{{ $person['name'] }}</a></h2>
                        <p>{{ collect($person['roles'])->pluck('label')->join(', ') ?: 'Публичные роли не указаны' }}</p></div>
                </div>
                <span class="account-contracts-workspace__status">{{ $person['reason'] }}</span>
            </div>
            <div class="account-contracts-workspace__meta">
                @if($person['games'] !== null)<span>Игр: {{ $person['games'] }}</span>@endif
                @if($person['organized'] !== null)<span>Проведено мероприятий: {{ $person['organized'] }}</span>@endif
                <span>Рейтинг: пока нет данных</span>
            </div>
            @if($person['can_message'])
                <details>
                    <summary>Написать</summary>
                    <form method="POST" action="{{ route($backoffice ? 'account.venue-audience.backoffice.message' : 'account.venue-audience.message', [$venue->id, $person['user_id']]) }}" class="account-contracts-workspace__filters">
                        @csrf
                        <label>Сообщение<textarea name="body" maxlength="500" rows="3" required></textarea></label>
                        <button type="submit" class="account-contracts-workspace__primary">Отправить</button>
                    </form>
                </details>
            @endif
            @if($person['can_offer'])
                <details>
                    <summary>Персональное предложение</summary>
                    @php($available = $offers->filter(fn ($offer) => $offer->recipients->contains('id', $person['user_id'])))
                    @if($available->isNotEmpty())
                        <form method="POST" class="account-contracts-workspace__filters"
                              action="{{ route($backoffice ? 'account.venue-audience.backoffice.offer' : 'account.venue-audience.offer', [$venue->id, $person['user_id']]) }}">
                            @csrf
                            <label>Опубликованное предложение<span class="select-control"><select name="offer_id" required>
                                @foreach($available as $offer)<option value="{{ $offer->id }}">{{ $offer->title }}</option>@endforeach
                            </select>@include('account.partials.native-select-chevron')</span></label>
                            <button type="submit" class="account-contracts-workspace__primary">Отправить предложение</button>
                        </form>
                    @else
                        <p>Нет опубликованных предложений для этого пользователя.</p>
                    @endif
                    @if(!$backoffice)
                        <a class="account-contracts-workspace__secondary" href="{{ route('account.venues.special-terms.index', $venue->id) }}">Управление специальными условиями</a>
                    @endif
                </details>
            @endif
        </article>
    @empty
        <div class="account-contracts-workspace__empty">Нет пользователей с открытым интересом по выбранным условиям.</div>
    @endforelse
    </div>
    @if($people->hasPages())<div class="account-contracts-workspace__pagination">{{ $people->links() }}</div>@endif
</div>
