@php
    $title = 'Запрос на аренду';
    $timezone = $pre->venue->schedule()->value('timezone') ?: config('app.timezone');
    $active = $pre->status === \App\Modules\VenueBooking\Domain\Enums\VenueRentalPreRequestStatus::ACTIVE;
@endphp
@extends('theme::layouts.app', ['title' => $title])
@section('content')
<section class="first-screen">
    <div class="inner">
        <header class="mb-4">
            <a href="{{ route('account.venue-pre-requests.index') }}">Запросы на аренду</a>
            <h1>{{ $title }}</h1>
            <p>{{ $pre->venue->name }} · {{ $pre->court->publicName() }}</p>
        </header>
        @if(session('error'))<p role="alert">{{ session('error') }}</p>@endif
        @if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
        <section class="card card-body mb-3">
            <h2>{{ $pre->status->label() }}</h2>
            <p>{{ $pre->starts_at->setTimezone($timezone)->format('d.m.Y H:i') }}–{{ $pre->ends_at->setTimezone($timezone)->format('d.m.Y H:i') }} · {{ (int) $pre->starts_at->diffInMinutes($pre->ends_at) }} мин · {{ $pre->scope->label() }}</p>
            <p>Заявитель: {{ $pre->requester->username }} · {{ $pre->created_at->setTimezone($timezone)->format('d.m.Y H:i') }}</p>
            <p>Запрос на аренду не удерживает время и не требует оплаты. Для аренды нужно согласовать отдельное предложение.</p>
            @if($pre->resolved_at)<p>Согласована: {{ $pre->resolved_at->setTimezone($timezone)->format('d.m.Y H:i') }}</p>@endif
            @if($pre->withdrawn_at)<p>Отозвана: {{ $pre->withdrawn_at->setTimezone($timezone)->format('d.m.Y H:i') }}</p>@endif
            <div class="venue-management-actions">
                @if($active && $canOffer)
                    <form method="POST" action="{{ route('account.venue-pre-requests.offer', $pre) }}">@csrf
                        <button class="btn btn--primary btn--sm" type="submit">Предложить бронирование</button>
                    </form>
                @endif
                @if($active && $isCustomer)
                    <form method="POST" action="{{ route('account.venue-pre-requests.withdraw', $pre) }}">@csrf
                        <button class="btn btn--secondary btn--sm" type="submit">Отозвать запрос</button>
                    </form>
                @endif
            </div>
        </section>
        @foreach($pre->bookings->sortByDesc('id') as $booking)
            <section class="card card-body mb-3">
                <h2>Предложение №{{ $booking->id }}</h2>
                <p>{{ $booking->status->label() }} · {{ app(\App\Modules\VenueBooking\Application\Services\MinorAmountParser::class)->format((int) data_get($booking->quote_snapshot, 'pricing.amount_minor'), (string) data_get($booking->quote_snapshot, 'pricing.currency')) }} {{ data_get($booking->quote_snapshot, 'pricing.currency') }}</p>
                <p>Ответить до {{ $booking->proposal_expires_at->setTimezone($timezone)->format('d.m.Y H:i') }}.</p>
                @if($booking->proposal_accepted_at === null && $booking->proposal_expires_at->isPast())<p>Срок предложения истёк.</p>@endif
                @if($booking->proposal_accepted_at)<p>Принято: {{ $booking->proposal_accepted_at->setTimezone($timezone)->format('d.m.Y H:i') }}</p>@endif
                <div class="venue-management-actions">
                    @if($active && $isCustomer && $booking->status->value === 'requested' && $booking->proposal_expires_at->isFuture())
                        @foreach(['accept' => 'Принять предложение', 'decline' => 'Отклонить'] as $decision => $label)
                            <form method="POST" action="{{ route('account.venue-pre-requests.respond', $pre) }}">@csrf
                                <input type="hidden" name="booking_id" value="{{ $booking->id }}">
                                <button class="btn {{ $decision === 'accept' ? 'btn--primary btn--sm' : 'btn--secondary btn--sm' }}" name="decision" value="{{ $decision }}">{{ $label }}</button>
                            </form>
                        @endforeach
                    @endif
                    <a class="btn btn--secondary btn--sm" href="{{ route('account.venue-bookings.show', $booking) }}">Бронь и дальнейшее оформление</a>
                </div>
            </section>
        @endforeach
        <section class="card card-body mb-3" aria-labelledby="pre-request-messages">
            <h2 id="pre-request-messages">Переписка</h2>
            <p><a href="{{ request()->fullUrl() }}">Обновить сообщения</a></p>
            @foreach($messages->getCollection()->reverse() as $message)
                <article>
                    <p><strong>{{ $message->authorActor?->user?->username ?? 'Система' }}</strong>
                        <time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->setTimezone($timezone)->format('d.m.Y H:i') }}</time></p>
                    <p>{{ $message->body }}</p>
                    @if($message->attachment_path)<a href="{{ route('account.venue-pre-requests.download', [$pre, $message]) }}">{{ $message->attachment_name }}</a>@endif
                </article>
            @endforeach
            {{ $messages->links() }}
            @if($canWrite && config('features.venue_rental.conversations'))
                <form method="POST" enctype="multipart/form-data" class="mb-3" action="{{ route('account.venue-pre-requests.send', $pre) }}">@csrf
                    <input type="hidden" name="client_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <label class="form-label d-block">Сообщение<textarea class="form-control" name="body" maxlength="4000" rows="4"></textarea></label>
                    <label class="form-label d-block">Файл (JPG, PNG, PDF, TXT, до 10 МБ)<input class="form-control" type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf,.txt"></label>
                    <button class="btn btn--primary btn--sm" type="submit">Отправить</button>
                </form>
            @endif
        </section>
    </div>
</section>
@endsection
