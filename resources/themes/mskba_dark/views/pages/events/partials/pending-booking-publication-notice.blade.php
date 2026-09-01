@php
    use App\Modules\Event\Domain\Enums\EventStatusEnum;
    use App\Modules\Event\Domain\Enums\EventTypeEnum;
    use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
@endphp

@if($event->status === EventStatusEnum::DRAFT && $event->booking?->status === VenueBookingStatusEnum::PENDING)
    @php
        $isGame = $event->type === EventTypeEnum::GAME;
    @endphp
    <div class="alert alert-warning event-show__alert" role="status">
        <strong>{{ $isGame ? 'Игра пока не опубликована.' : 'Мероприятие пока не опубликовано.' }}</strong>
        Бронирование площадки ожидает подтверждения. До подтверждения
        {{ $isGame ? 'игра видна' : 'мероприятие видно' }} только организатору и администраторам,
        не отображается в каталоге и не отправляется в выбранные Telegram-чаты.
    </div>
@endif
