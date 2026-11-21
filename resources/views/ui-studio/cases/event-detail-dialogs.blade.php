@php
    $studioDialogs = [
        'studio-participate'=>'Участвовать',
        'studio-leave'=>'Не участвовать',
        'studio-auth-required'=>'Участие в мероприятии',
        'studio-auth'=>'Войти',
        'studio-share'=>'Поделиться',
        'studio-share-internal'=>'Поделиться внутри MSKBA',
        'studio-share-external'=>'Поделиться',
        'studio-rental-dialog'=>'Аренда',
        'studio-venue-dialog'=>'Площадка',
        'studio-game-dialog'=>'Игра',
    ];
@endphp
@foreach($studioDialogs as $id=>$heading)
    @continue($id === 'studio-rental-dialog' && !$hasRental)
    <dialog id="{{ $id }}" class="mskba-modal studio-event-dialog" aria-labelledby="{{ $id }}-title">
        <header class="mskba-modal__header"><h2 id="{{ $id }}-title">{{ $heading }}</h2><button type="button" class="icon-button" data-event-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12M6 6l12 12"/></svg></button></header>
        <div class="mskba-modal__body mskba-scroll">
        @switch($id)
            @case('studio-participate')
                <div class="studio-event-choices"><button type="button" class="button primary" data-event-rsvp="confirmed">Пойду</button><button type="button" class="button secondary" data-event-rsvp="tentative">Подумаю</button></div>
                @break
            @case('studio-leave')
                <p>Вы уверены, что не будете участвовать в мероприятии?</p>
                <div class="studio-event-choices"><button type="button" class="button secondary" data-event-close>Отмена</button><button type="button" class="button primary" data-event-rsvp="left">Не участвовать</button></div>
                @break
            @case('studio-auth-required')
                <p>Для участия в мероприятии необходимо авторизоваться.</p>
                <button type="button" class="button primary full" data-event-open="studio-auth">Войти</button>
                @break
            @case('studio-auth')
                <label class="field-group">Логин или email<input type="text" autocomplete="off" placeholder="Логин или email"></label>
                <label class="field-group">Пароль<input type="password" autocomplete="off" placeholder="Пароль"></label>
                <button type="button" class="button primary full" data-event-demo-login>Войти</button>
                @break
            @case('studio-share')
                <div class="studio-share-choices"><button type="button" class="button primary full" data-event-open="studio-share-internal">Внутри MSKBA</button><button type="button" class="button secondary full" data-event-open="studio-share-external">В соцсетях и по почте</button></div>
                @break
            @case('studio-share-internal')
                <label class="field-group">Получатель<input type="search" data-studio-recipient placeholder="Имя или никнейм"></label>
                <div class="studio-share-choices" data-studio-recipients><label><input type="checkbox" value="olsen"> olsen</label><label><input type="checkbox" value="superadmin"> superadmin</label></div>
                <button type="button" class="button primary full" data-event-close>Поделиться</button>
                @break
            @case('studio-share-external')
                <div class="studio-share-choices"><button type="button" class="button secondary full" data-studio-external>Telegram</button><button type="button" class="button secondary full" data-studio-external>ВКонтакте</button><button type="button" class="button secondary full" data-studio-external>Электронная почта</button><button type="button" class="button secondary full" data-studio-copy>Скопировать ссылку</button></div>
                @break
            @case('studio-rental-dialog')
                <dl class="studio-event-facts">
                    <div><dt>Площадка</dt><dd>{{ $venueName }}</dd></div>
                    <div><dt>Зал</dt><dd>{{ $courtName }}</dd></div>
                    <div><dt>Дата и время</dt><dd>{{ $eventTime }}</dd></div>
                    <div><dt>Статус</dt><dd><span class="badge {{ $rentalActive ? 'studio-rental-active' : '' }}">{{ $rentalActive ? 'Активна' : 'Неактивна' }}</span></dd></div>
                    <div><dt>Организатор аренды</dt><dd>olsen</dd></div>
                </dl>
                @break
            @case('studio-venue-dialog')
                <strong>{{ $venueName }}</strong><p>{{ $courtName }}</p><p>Москва, Лобненская улица, 13</p>
                @break
            @case('studio-game-dialog')
                <strong>{{ $eventTitle }}</strong><p>{{ $eventTime }}</p><p>{{ $courtName }}</p>
                @break
        @endswitch
        </div>
    </dialog>
@endforeach
